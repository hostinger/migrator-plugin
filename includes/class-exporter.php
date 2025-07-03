<?php
/**
 * The class responsible for exporting the website.
 *
 * @package CustomMigrator
 */

/**
 * Exporter class for WordPress website migration with binary archive format.
 */
class Custom_Migrator_Exporter {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;



    /**
     * The metadata handler.
     *
     * @var Custom_Migrator_Metadata
     */
    private $metadata;

    /**
     * The file extension for exported content.
     * 
     * @var string
     */
    private $file_extension = 'hstgr';

    /**
     * Paths to exclude from export.
     * 
     * @var array
     */
    private $exclusion_paths = [];



    /**
     * Batch processing constants - Optimized for responsiveness.
     */
    const BATCH_SIZE = 1000;            // Reduced from 10,000 to 1,000 for faster resume
    const MAX_EXECUTION_TIME = 10;      // 10 seconds like All-in-One WP Migration  
    const MEMORY_THRESHOLD = 0.9;       // More aggressive memory usage
    const CHUNK_SIZE = 512000;          // 512KB chunks like All-in-One

    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->filesystem = new Custom_Migrator_Filesystem();
        // Note: Database export now uses unified Custom_Migrator_Database_Exporter
        $this->metadata = new Custom_Migrator_Metadata();

        // Define exclusion paths
        $this->set_exclusion_paths();
    }

    /**
     * Set paths to be excluded from export.
     */
    private function set_exclusion_paths() {
        // Use unified helper class for exclusion paths
        $this->exclusion_paths = Custom_Migrator_Helper::get_exclusion_paths();
    }

    /**
     * Run the export process with database export protection.
     */
    public function export() {
        $this->setup_execution_environment();
        
        $file_paths = $this->filesystem->get_export_file_paths();
        $hstgr_file = $file_paths['hstgr'];
        $meta_file = $file_paths['metadata'];
        $sql_file = $file_paths['sql'];

        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        $is_resuming = file_exists($resume_info_file);

        if ($is_resuming) {
            $this->filesystem->write_status('resuming');
            $this->filesystem->log('Resuming export process');
        } else {
            $this->filesystem->write_status('exporting');
            $this->filesystem->log('Starting export process');
        }

        try {
            if (!$is_resuming) {
                $this->filesystem->log('Generating metadata...');
                
                // Use unified metadata generation with regular export configuration
                $metadata_options = array(
                    'file_format' => $this->file_extension,
                    'exporter_version' => CUSTOM_MIGRATOR_VERSION,
                    'export_type' => 'regular',
                    'export_method' => 'cron_based',
                );
                
                $result = $this->metadata->generate_and_save($meta_file, $metadata_options);
                if (!$result) {
                    throw new Exception('Metadata generation failed');
                }
            }

            // CRITICAL FIX: Only run database export on fresh start, never when resuming file export
            if (!$is_resuming) {
                // Fresh start: check if database export is needed
                if (!$this->is_database_export_complete($sql_file)) {
                    $this->safe_database_export($sql_file);
                } else {
                    $this->filesystem->log('Database export already complete, skipping');
                }
            } else {
                // Resuming: we're only resuming file export, never database export
                $this->filesystem->log('Resuming file export - database export already complete, skipping');
            }

            $this->filesystem->log('Exporting wp-content files...');
            $content_export_result = $this->export_wp_content_archive($hstgr_file);
            
            if ($content_export_result === 'paused') {
                return true;
            }
            
            $this->filesystem->log('wp-content files exported successfully');

            // CRITICAL: Validate all required files exist before marking export as done
            $missing_files = Custom_Migrator_Helper::validate_export_files($file_paths);
            if (!empty($missing_files)) {
                $error_msg = 'Export incomplete - missing files: ' . implode(', ', $missing_files);
                $this->filesystem->write_status('error: ' . $error_msg);
                $this->filesystem->log('❌ EXPORT VALIDATION FAILED: ' . $error_msg);
                throw new Exception($error_msg);
            }

            if (file_exists($resume_info_file)) {
                @unlink($resume_info_file);
            }

            $this->filesystem->write_status('done');
            $this->filesystem->log('Export completed successfully');
            
            return true;
        } catch (Exception $e) {
            $error_message = 'Export failed: ' . $e->getMessage();
            $this->filesystem->write_status('error: ' . $error_message);
            $this->filesystem->log($error_message);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->filesystem->log('Error trace: ' . $e->getTraceAsString());
            }
            
            return false;
        }
    }

    /**
     * Setup execution environment.
     */
    private function setup_execution_environment() {
        if (function_exists('set_time_limit') && !ini_get('safe_mode')) {
            @set_time_limit(0);
        }
        
        $current_limit = $this->get_memory_limit_bytes();
        $target_limit = max($current_limit, 1024 * 1024 * 1024);
        
        @ini_set('memory_limit', $this->format_bytes($target_limit));
        
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        if (function_exists('gc_enable')) {
            gc_enable();
        }
        
        $this->filesystem->log('Execution environment setup: Memory limit = ' . ini_get('memory_limit'));
    }

    /**
     * Export WordPress content files using optimized single-file processing.
     * Process one file at a time with immediate timeout checks for better resource management.
     */
    private function export_wp_content_archive($hstgr_file) {
        $wp_content_dir = WP_CONTENT_DIR;
        $export_dir = $this->filesystem->get_export_dir();
        
        $content_list_file = $export_dir . '/content-list.csv';
        $resume_info_file = $export_dir . '/export-resume-info.json';
        
        // Load resume data with CSV offset tracking
        $resume_data = $this->load_resume_data($resume_info_file);
        $csv_offset = isset($resume_data['csv_offset']) ? $resume_data['csv_offset'] : 0;
        $archive_offset = isset($resume_data['archive_offset']) ? $resume_data['archive_offset'] : 0;
        $files_processed = isset($resume_data['files_processed']) ? $resume_data['files_processed'] : 0;
        $bytes_processed = isset($resume_data['bytes_processed']) ? $resume_data['bytes_processed'] : 0;
        
        $is_resuming = $csv_offset > 0 || $archive_offset > 0;
        
        // Calculate adaptive timeout based on restart patterns
        $timeout_info = $this->calculate_adaptive_timeout($resume_data);
        $adaptive_timeout = $timeout_info['timeout'];
        $cron_mode = $timeout_info['cron_mode'];
        
        // Log startup mode
        if ($is_resuming) {
            $this->filesystem->log("Resuming in {$timeout_info['mode_name']} - processed $files_processed files so far");
        }
        
        // Step 1: Enumerate files into CSV for efficient processing
        if (!$is_resuming && !file_exists($content_list_file)) {
            $this->filesystem->log('Phase 1: Enumerating files into CSV for optimized processing');
            $this->enumerate_content_files($content_list_file);
        }
        
        // Step 2: Process files from CSV (ONE FILE AT A TIME)
        $this->filesystem->log('Phase 2: Processing files one-by-one for optimal resource usage');
        
        // Open CSV file for reading
        $csv_handle = fopen($content_list_file, 'r');
        if (!$csv_handle) {
            throw new Exception('Cannot open content list file');
        }
        
        // Seek to CSV offset if resuming
        if ($csv_offset > 0) {
            fseek($csv_handle, $csv_offset);
        }
        
        // Open archive file
        $archive_mode = $archive_offset > 0 ? 'ab' : 'wb';
        $archive_handle = fopen($hstgr_file, $archive_mode);
        if (!$archive_handle) {
            fclose($csv_handle);
            throw new Exception('Cannot create or open archive file');
        }
        
        // CRITICAL FIX: Don't seek when using append mode!
        // Append mode ('ab') automatically positions at end of file
        // The fseek() was causing corruption by seeking to wrong position
        // if ($archive_offset > 0) {
        //     fseek($archive_handle, $archive_offset);
        // }
        
        // Start precise timing for optimal resource management
        $start = microtime(true);
        $completed = true;
        
        // Track files processed in this batch for accurate rate calculation
        $files_processed_this_batch = 0;
        
        // CRITICAL: Track failed files to prevent incomplete backups
        $files_failed = 0;
        $bytes_failed = 0;
        $total_files_attempted = 0;
        
        try {
            // Process files from CSV one at a time for hosting-friendly resource usage
            while (($file_data = fgetcsv($csv_handle, 0, ',', '"', '\\')) !== FALSE) {
                
                // Parse CSV data: [file_path, relative_path, size, mtime]
                if (count($file_data) < 4) continue;
                
                $file_path = $file_data[0];
                $relative_path = $file_data[1];
                $file_size = (int)$file_data[2];
                $file_mtime = (int)$file_data[3];
                
                // Skip if file no longer exists
                if (!file_exists($file_path) || !is_readable($file_path)) {
                    continue;
                }
                
                // Process the file (one at a time for optimal resource usage)
                $file_info = [
                    'path' => $file_path,
                    'relative' => $relative_path,
                    'size' => $file_size
                ];
                
                $total_files_attempted++;
                $result = $this->add_file_to_archive($archive_handle, $file_info);
                
                if ($result['success']) {
                    $files_processed++;
                    $files_processed_this_batch++;
                    $bytes_processed += $result['bytes'];
                } else {
                    // CRITICAL: Track failed files and their impact
                    $files_failed++;
                    $bytes_failed += $file_size;
                    
                    // FAIL FAST: If too many files are failing, stop the export immediately
                    if ($files_failed >= 100) {
                        $failure_rate = ($files_failed / $total_files_attempted) * 100;
                        $failed_size_mb = $bytes_failed / (1024 * 1024);
                        
                        $error_msg = sprintf(
                            'Export FAILED: %d files failed to archive (%.1f%% failure rate, %.2f MB lost). ' .
                            'Latest failure: %s. This backup would be incomplete and unreliable.',
                            $files_failed,
                            $failure_rate,
                            $failed_size_mb,
                            basename($file_path)
                        );
                        
                        $this->filesystem->write_status('error: ' . $error_msg);
                        $this->filesystem->log('❌ EXPORT TERMINATED DUE TO EXCESSIVE FAILURES: ' . $error_msg);
                        throw new Exception($error_msg);
                    }
                }
                
                // Progress logging every 1000 files
                if ($files_processed % 1000 === 0) {
                    $elapsed = microtime(true) - $start;
                    $batch_rate = $files_processed_this_batch / max($elapsed, 1);
                    $mode_desc = $cron_mode ? "Cron" : "HTTP";
                    $this->filesystem->log(sprintf(
                        "Processing: %d files (%.2f MB). Batch Rate: %.2f files/sec (%d files in %.1fs) [%s Mode - %ds timeout]",
                        $files_processed,
                        $bytes_processed / (1024 * 1024),
                        $batch_rate,
                        $files_processed_this_batch,
                        $elapsed,
                        $mode_desc,
                        $adaptive_timeout
                    ));
                }
                
                // LVE THROTTLING DETECTION: Check every 100 files for performance degradation
                if ($files_processed % 100 === 0) {
                    static $last_lve_check = null;
                    static $last_lve_files = 0;
                    
                    if ($last_lve_check !== null) {
                        $time_since_check = microtime(true) - $last_lve_check;
                        $files_since_check = $files_processed - $last_lve_files;
                        $recent_rate = $files_since_check / max($time_since_check, 1);
                        
                        // If processing rate drops below 5 files/sec, likely LVE throttling
                        if ($recent_rate < 5 && $files_since_check >= 100) {
                            $this->filesystem->log(sprintf(
                                "⚠️ LVE THROTTLING DETECTED: Rate=%.1f files/sec (last 100 files took %.1fs) - will pause early to avoid limits",
                                $recent_rate,
                                $time_since_check
                            ));
                            
                            // Force early timeout to avoid hitting harder LVE limits
                            $completed = false;
                            break;
                        }
                    }
                    
                    $last_lve_check = microtime(true);
                    $last_lve_files = $files_processed;
                }
                
                // Check adaptive timeout after each file
                if ((microtime(true) - $start) > $adaptive_timeout) {
                    $elapsed = microtime(true) - $start;
                    
                    $this->filesystem->log(sprintf(
                        "⏰ Batch complete: Processed %d files in %.1fs using %s (timeout=%ds)",
                        $files_processed_this_batch,
                        $elapsed,
                        $timeout_info['mode_name'],
                        $adaptive_timeout
                    ));
                    
                    // Save CSV and archive positions for precise resume
                    $current_csv_offset = ftell($csv_handle);
                    
                    // Archive offset is saved for logging but NOT used for seeking
                    // We use append mode ('ab') which automatically positions at end of file
                    $current_archive_offset = ftell($archive_handle);
                    
                    $this->save_resume_data($resume_info_file, [
                        'csv_offset' => $current_csv_offset,
                        'archive_offset' => $current_archive_offset,
                        'files_processed' => $files_processed,
                        'bytes_processed' => $bytes_processed,
                        'last_update' => time(),
                        'last_restart_time' => time(),
                        'restart_count' => (isset($resume_data['restart_count']) ? $resume_data['restart_count'] : 0) + 1,
                        'cron_mode' => $cron_mode
                    ]);
                    
                    $completed = false;
                    break; // Pause for optimal batch processing
                }
            }
            
        } catch (Exception $e) {
            fclose($csv_handle);
            fclose($archive_handle);
            throw new Exception('Single-file processing failed: ' . $e->getMessage());
        }
        
        fclose($csv_handle);
        fclose($archive_handle);
        
        // If not completed, schedule immediate resume and exit
        if (!$completed) {
            $this->filesystem->write_status('paused');
            $this->schedule_immediate_resume();
        } else {
            // Clean up files when completed
            if (file_exists($content_list_file)) {
                @unlink($content_list_file);
            }
            if (file_exists($resume_info_file)) {
                @unlink($resume_info_file);
            }

            // Final consistency check: ensure at least 99% of enumerated files were archived
            $total_files_enumerated = $this->count_lines_in_csv($content_list_file);
            $completion_rate = $total_files_enumerated > 0 ? ($files_processed / $total_files_enumerated) : 1;
            if ($total_files_enumerated > 0 && $completion_rate < 0.99) {
                $missing = $total_files_enumerated - $files_processed;
                $failed_size_mb = isset($bytes_failed) ? $bytes_failed / (1024 * 1024) : 0;
                $failed_count = isset($files_failed) ? $files_failed : 0;
                
                $error_msg = sprintf(
                    'Export incomplete: Only %d of %d files exported (%.1f%%). Missing %d files. ' .
                    'Failed files: %d (%.2f MB lost). This backup is unreliable.',
                    $files_processed,
                    $total_files_enumerated,
                    $completion_rate * 100,
                    $missing,
                    $failed_count,
                    $failed_size_mb
                );
                $this->filesystem->write_status('error: ' . $error_msg);
                $this->filesystem->log('❌ EXPORT VALIDATION FAILED: ' . $error_msg);
                throw new Exception($error_msg);
            } else {
                $total_time = microtime(true) - $start;
                $failed_info = '';
                if (isset($files_failed) && $files_failed > 0) {
                    $failed_size_mb = $bytes_failed / (1024 * 1024);
                    $failed_info = sprintf(' (Warning: %d files failed, %.2f MB lost)', $files_failed, $failed_size_mb);
                }
                
                $this->filesystem->log(sprintf(
                    'Single-file processing completed: %d files (%.2f MB) in %.2f seconds%s',
                    $files_processed,
                    $bytes_processed / (1024 * 1024),
                    $total_time,
                    $failed_info
                ));
                $this->filesystem->write_status('done');
                $this->filesystem->log('Export completed successfully');
            }
        }
        
        return $completed ? true : 'paused';
    }

    /**
     * Enumerate content files into CSV using unified enumerator.
     */
    private function enumerate_content_files($content_list_file) {
        // Use unified file enumerator with unlimited execution environment
        $enumerator = new Custom_Migrator_File_Enumerator($this->filesystem);
        
        $options = array(
            'progress_interval' => 1000,  // More frequent progress updates
            'use_exclusions' => true,
            'validate_files' => true,
            'skip_unreadable' => true,
            'log_errors' => true,
            'use_unlimited_execution' => true  // Enable unlimited execution time for enumeration
        );
        
        $stats = $enumerator->enumerate_to_csv($content_list_file, $options);
        
        // Log final results in format compatible with existing code
        $this->filesystem->log("File enumeration complete: {$stats['files_found']} files");
        
        return $stats;
    }

    /**
     * Add a file to the binary archive using the structured format.
     */
    private function add_file_to_archive($archive_handle, $file_info) {
        $file_path = $file_info['path'];
        $relative_path = $file_info['relative'];
        $file_size = $file_info['size'];
        
        // Enhanced file validation
        if (!file_exists($file_path)) {
            $this->filesystem->log("File disappeared during processing: " . basename($file_path));
            return ['success' => false, 'bytes' => 0];
        }
        
        if (!is_readable($file_path)) {
            $this->filesystem->log("File became unreadable: " . basename($file_path));
            return ['success' => false, 'bytes' => 0];
        }
        
        // Re-check file size (files can change during processing)
        $current_size = filesize($file_path);
        if ($current_size !== $file_size) {
            $this->filesystem->log("File size changed during processing: " . basename($file_path) . " (was: $file_size, now: $current_size) - using current size");
            $file_size = $current_size;
            $file_info['size'] = $file_size; // Update for consistency
        }
        
        // ENHANCED: Large file handling and memory management
        $is_large_file = $file_size > 50 * 1024 * 1024; // 50MB threshold
        if ($is_large_file) {
            $this->filesystem->log("Processing large file: " . basename($file_path) . " (" . $this->format_bytes($file_size) . ")");
            
            // Force garbage collection before large file
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Check available memory
            $memory_limit = $this->get_memory_limit();
            $memory_used = memory_get_usage(true);
            $memory_available = $memory_limit - $memory_used;
            
            if ($memory_available < ($file_size * 1.5)) {
                $this->filesystem->log("Warning: Low memory for large file processing. Available: " . $this->format_bytes($memory_available) . ", File: " . $this->format_bytes($file_size));
            }
        }
        
        // Get file stats
        $stat = stat($file_path);
        if ($stat === false) {
            $this->filesystem->log("Cannot get file stats: " . basename($file_path));
            return ['success' => false, 'bytes' => 0];
        }
        
        // Prepare file info for binary block - ensure strings fit in allocated space
        $file_name = basename($file_path);
        $file_date = $stat['mtime'];
        $file_dir = dirname($relative_path);
        
        // Use unified binary block creation from helper
        try {
            $block = Custom_Migrator_Helper::create_binary_block($file_name, $file_size, $file_date, $file_dir);
        } catch (Exception $e) {
            $this->filesystem->log("Error: " . $e->getMessage());
            return ['success' => false, 'bytes' => 0];
        }
        
        $expected_size = Custom_Migrator_Helper::get_binary_block_size();
        
        // Write the header block with verification
        $header_written = fwrite($archive_handle, $block);
        if ($header_written === false || $header_written !== $expected_size) {
            $this->filesystem->log("Error: Failed to write header block for {$file_name}");
            return ['success' => false, 'bytes' => 0];
        }
        
        // ENHANCED: Large file content copying with memory management
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            $this->filesystem->log("Error: Cannot open file for reading: " . basename($file_path));
            return ['success' => false, 'bytes' => 0];
        }
        
        $bytes_copied = 0;
        $chunk_size = $is_large_file ? 256 * 1024 : self::CHUNK_SIZE; // Smaller chunks for large files
        
        // ENHANCED: Progress tracking for large files
        $progress_interval = $is_large_file ? (10 * 1024 * 1024) : 0; // 10MB intervals for large files
        $last_progress = 0;
        
        while (!feof($file_handle)) {
            $chunk = fread($file_handle, $chunk_size);
            if ($chunk === false) {
                $this->filesystem->log("Error: Failed to read from file: " . basename($file_path));
                fclose($file_handle);
                return ['success' => false, 'bytes' => $bytes_copied];
            }
            
            $chunk_length = strlen($chunk);
            if ($chunk_length === 0) {
                break; // End of file
            }
            
            $written = fwrite($archive_handle, $chunk);
            if ($written === false || $written !== $chunk_length) {
                $this->filesystem->log("Error: Failed to write chunk for file: " . basename($file_path));
                fclose($file_handle);
                return ['success' => false, 'bytes' => $bytes_copied];
            }
            
            $bytes_copied += $written;
            
            // ENHANCED: Progress logging and memory management for large files
            if ($is_large_file && $progress_interval > 0 && ($bytes_copied - $last_progress) >= $progress_interval) {
                $progress = round(($bytes_copied / $file_size) * 100, 1);
                $this->filesystem->log("Large file progress: {$progress}% of " . basename($file_path) . " (" . $this->format_bytes($bytes_copied) . "/" . $this->format_bytes($file_size) . ")");
                $last_progress = $bytes_copied;
                
                // Force garbage collection during large file processing
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }
        
        fclose($file_handle);
        
        // ENHANCED: Post-large-file memory management
        if ($is_large_file) {
            $this->filesystem->log("Large file completed: " . basename($file_path) . " (" . $this->format_bytes($bytes_copied) . ")");
            
            // Force garbage collection after large file
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Flush archive buffer to disk to free memory
            if (function_exists('fflush')) {
                fflush($archive_handle);
            }
            
            // Brief pause to let system recover
            usleep(100000); // 0.1 second pause
        }
        
        // Final verification - ensure we copied the expected amount
        if ($bytes_copied !== $file_size) {
            $this->filesystem->log("Warning: Bytes copied ($bytes_copied) doesn't match file size ($file_size) for: " . basename($file_path));
        }
        
        return ['success' => true, 'bytes' => $bytes_copied];
    }

    /**
     * Get PHP memory limit in bytes.
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit == -1) {
            return PHP_INT_MAX; // Unlimited
        }
        
        $value = (int) $memory_limit;
        $unit = strtolower(substr($memory_limit, -1));
        
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }

    /**
     * Format bytes for display
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Load resume data with CSV offset tracking.
     */
    private function load_resume_data($resume_info_file) {
        if (!file_exists($resume_info_file)) {
            return [
                'csv_offset' => 0,
                'archive_offset' => 0,
                'files_processed' => 0,
                'bytes_processed' => 0
            ];
        }
        
        $data = json_decode(file_get_contents($resume_info_file), true);
        return $data ?: [
            'csv_offset' => 0,
            'archive_offset' => 0,
            'files_processed' => 0,
            'bytes_processed' => 0
        ];
    }

    /**
     * Save resume data.
     */
    private function save_resume_data($resume_info_file, $data) {
        file_put_contents($resume_info_file, json_encode($data));
    }

    /**
     * Calculate adaptive timeout based on restart patterns (Ultra-Conservative Timeout Structure).
     * Implements the timeout structure from your screenshot.
     */
    private function calculate_adaptive_timeout($resume_data) {
        $current_time = time();
        $last_restart = isset($resume_data['last_restart_time']) ? $resume_data['last_restart_time'] : $current_time;
        $restart_count = isset($resume_data['restart_count']) ? $resume_data['restart_count'] : 0;
        
        // Calculate time since last restart
        $restart_gap = $current_time - $last_restart;
        
        // Detect cron mode: if restart took >30 seconds, we're likely in cron fallback
        $is_cron_mode = $restart_gap > 30 || $resume_data['cron_mode'];
        
        if ($is_cron_mode) {
            // ULTRA-CONSERVATIVE mode: Maximum shared hosting compatibility
            if ($restart_gap > 300) {
                // Very slow restarts (5+ minutes) = ultra-conservative
                $timeout = 25; // 2.5x longer than normal (ultra-safe)
                $mode_name = "Ultra-Safe Maximum";
            } elseif ($restart_gap > 120) {
                // Slow restarts (2+ minutes) = conservative
                $timeout = 20; // 2x longer than normal (safe)
                $mode_name = "Ultra-Safe High";
            } else {
                // Medium slow restarts (30s-2min) = moderate
                $timeout = 15; // 1.5x longer than normal (moderate)
                $mode_name = "Ultra-Safe Moderate";
            }
        } else {
            // HTTP mode: stay nimble for fast restarts  
            $timeout = 10; // Standard timeout
            $mode_name = "HTTP Mode (Standard)";
        }
        
        // Enhanced logging for batch size detection
        if ($restart_count > 0) { // Don't log on first run
            $this->filesystem->log(sprintf(
                "🔄 Restart #%d detected: Gap=%ds (%.1fm), Mode=%s, Batch-Timeout=%ds",
                $restart_count,
                $restart_gap,
                $restart_gap / 60,
                $mode_name,
                $timeout
            ));
            
            if ($is_cron_mode) {
                $this->filesystem->log(sprintf(
                    "⚡ Cron mode: %ds gap → Batch size %dx larger (%ds vs 10s standard)",
                    $restart_gap,
                    $timeout / 10,
                    $timeout
                ));
            }
        }
        
        return [
            'timeout' => $timeout,
            'cron_mode' => $is_cron_mode,
            'mode_name' => $mode_name
        ];
    }

    /**
     * Get memory limit in bytes.
     */
    private function get_memory_limit_bytes() {
        $limit = ini_get('memory_limit');
        if ($limit == -1) {
            return PHP_INT_MAX;
        }
        
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;
        
        switch ($unit) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Prepare export environment (step 1 of step-by-step export).
     * 
     * @return bool Success status.
     */
    public function prepare_export() {
        $this->filesystem->log('Preparing export environment');
        
        // Set exclusion paths
        $this->set_exclusion_paths();
        
        // Setup execution environment
        $this->setup_execution_environment();
        
        // Clean any previous resume info
        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        if (file_exists($resume_info_file)) {
            @unlink($resume_info_file);
        }
        
        $this->filesystem->log('Export environment prepared');
        return true;
    }

    /**
     * Export only wp-content files (step 2 of step-by-step export).
     * 
     * @return mixed Success status (true), paused status ('paused'), or false on error.
     */
    public function export_content_only() {
        $this->filesystem->log('Starting wp-content export step');
        
        $file_paths = $this->filesystem->get_export_file_paths();
        $hstgr_file = $file_paths['hstgr'];
        
        // Check if .hstgr file already exists and has content
        if (file_exists($hstgr_file) && filesize($hstgr_file) > 0) {
            $file_size = $this->format_bytes(filesize($hstgr_file));
            $this->filesystem->log("wp-content file already exists ($file_size), skipping content export");
            return true;
        }
        
        $content_export_result = $this->export_wp_content_archive($hstgr_file);
        
        if ($content_export_result === 'paused') {
            $this->filesystem->log('wp-content export paused, will continue in next step');
            return 'paused'; // Return paused status instead of false
        }
        
        $this->filesystem->log('wp-content export completed');
        return true;
    }

    /**
     * Export only database (step 3 of step-by-step export) with protection.
     * 
     * @return bool Success status.
     */
    public function export_database_only() {
        $this->filesystem->log('Starting database export step');
        
        $file_paths = $this->filesystem->get_export_file_paths();
        $sql_file = $file_paths['sql'];
        
        // Use the same safe database export mechanism
        if (!$this->is_database_export_complete($sql_file)) {
            $this->safe_database_export($sql_file);
        } else {
            $this->filesystem->log('Database export already complete, skipping');
        }
        
        return true;
    }

    /**
     * Generate only metadata (step 4 of step-by-step export).
     * 
     * @return bool Success status.
     */
    public function generate_metadata_only() {
        $this->filesystem->log('Starting metadata generation step');
        
        $file_paths = $this->filesystem->get_export_file_paths();
        $meta_file = $file_paths['metadata'];
        
        // Use unified metadata generation with regular export configuration
        $metadata_options = array(
            'file_format' => $this->file_extension,
            'exporter_version' => CUSTOM_MIGRATOR_VERSION,
            'export_type' => 'regular',
            'export_method' => 'step_by_step',
        );
        
        return $this->metadata->generate_and_save($meta_file, $metadata_options);
    }

    /**
     * Schedule immediate resume (optimized background processing).
     */
    private function schedule_immediate_resume() {
        $ajax_url = admin_url('admin-ajax.php');
        $request_params = array(
            'action' => 'cm_run_export_now',
            'background_mode' => '1'
        );
        
        // Method 1: Non-blocking request (don't wait for response)
        wp_remote_post($ajax_url, array(
            'method'    => 'POST',
            'timeout'   => 0.01,  // Very short timeout
            'blocking'  => false, // Non-blocking so we can exit immediately
            'sslverify' => false,
            'headers'   => array('Connection' => 'close'),
            'body'      => $request_params,
        ));
        
        // Method 2: Additional non-blocking request as backup
        wp_remote_post($ajax_url, array(
            'method'    => 'POST',
            'timeout'   => 10,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => $request_params,
        ));
        
        $this->filesystem->log('Sent immediate resume requests - exiting to allow new request');
        
        // Optimized pattern: Exit immediately after sending HTTP request
        // This ensures the current request ends and the new request can start immediately
        exit();
    }

    /**
     * Trigger cron execution.
     */
    private function trigger_cron_execution() {
        // Method 1: Standard WordPress spawn_cron
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        
        // Method 2: Direct HTTP request to wp-cron.php
        $cron_url = site_url('wp-cron.php');
        $this->non_blocking_request($cron_url . '?doing_wp_cron=1');
    }

    /**
     * Make a non-blocking HTTP request.
     */
    private function non_blocking_request($url) {
        $args = array(
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'redirection' => 0
        );
        
        wp_remote_get($url, $args);
    }

    /**
     * Check if database export is complete and valid.
     */
    private function is_database_export_complete($sql_file) {
        // Only check the EXACT current database file, not old ones from previous exports
        
        // Method 1: Check if the exact compressed file exists and has content
        if (file_exists($sql_file . '.gz') && filesize($sql_file . '.gz') > 1024) {
            $this->filesystem->log('Current database export found: ' . basename($sql_file . '.gz') . ' (' . $this->format_bytes(filesize($sql_file . '.gz')) . ')');
            return true;
        }
        
        // Method 2: Check if the exact uncompressed file exists and is complete
        if (file_exists($sql_file) && filesize($sql_file) > 1024) {
            // Read last 1024 bytes to check for completion marker
            $handle = fopen($sql_file, 'r');
            if ($handle) {
                fseek($handle, -1024, SEEK_END);
                $tail = fread($handle, 1024);
                fclose($handle);
                
                // Look for SQL completion markers
                if (strpos($tail, '-- Export completed') !== false || 
                    strpos($tail, 'COMMIT;') !== false ||
                    strpos($tail, '/*!40101 SET') !== false) {
                    $this->filesystem->log('Current database export found: ' . basename($sql_file) . ' (' . $this->format_bytes(filesize($sql_file)) . ')');
                    return true;
                }
            }
        }
        
        $this->filesystem->log('No current database export found - need to create: ' . basename($sql_file));
        return false;
    }

    /**
     * Safely export database with lock mechanism to prevent parallel exports.
     */
    private function safe_database_export($sql_file) {
        $export_dir = $this->filesystem->get_export_dir();
        $lock_file = $export_dir . '/database-export.lock';
        $status_file = $export_dir . '/database-export-status.json';
        
        // Check if another process is already exporting database
        if (file_exists($lock_file)) {
            $lock_time = filemtime($lock_file);
            $current_time = time();
            
            // If lock is older than 5 minutes, consider it stale
            if (($current_time - $lock_time) > 300) {
                $this->filesystem->log('Removing stale database export lock (older than 5 minutes)');
                @unlink($lock_file);
                @unlink($status_file);
            } else {
                // Check if database export is actually running
                if (file_exists($status_file)) {
                    $status = json_decode(file_get_contents($status_file), true);
                    $progress_time = isset($status['last_update']) ? $status['last_update'] : 0;
                    
                    // If no progress in last 2 minutes, consider it stuck
                    if (($current_time - $progress_time) > 120) {
                        $this->filesystem->log('Database export appears stuck, removing lock');
                        @unlink($lock_file);
                        @unlink($status_file);
                    } else {
                        $this->filesystem->log('Database export already in progress by another process, waiting...');
                        return;
                    }
                } else {
                    $this->filesystem->log('Database export lock exists but no status file, removing lock');
                    @unlink($lock_file);
                }
            }
        }
        
        // Create lock file to claim database export
        file_put_contents($lock_file, time());
        file_put_contents($status_file, json_encode([
            'started' => time(),
            'last_update' => time(),
            'pid' => getmypid()
        ]));
        
        try {
            $this->filesystem->log('Starting protected database export');
            
            // Update status before starting
            $this->update_database_export_status($status_file, 'starting');
            
            // Use unified database exporter for regular export
            // Configure for regular export mode (no chunking, longer timeout)
            $config = array(
                'timeout' => 300,        // 5 minutes total timeout (vs 25s for fallback)
                'batch_size' => 500,     // Same as current regular export
                'transaction_size' => 100, // Same as current regular export
                'compression' => 'auto', // Smart compression like current
                'resume' => false,       // No resume for regular export (all-or-nothing)
                'charset' => 'utf8mb4',  // Same as current
                'chunk_tables' => 999,   // Process all tables in one chunk
            );
            
            // Create unified database exporter instance
            require_once dirname(__FILE__) . '/class-database-exporter.php';
            $db_exporter = new Custom_Migrator_Database_Exporter($config);
            
            // Execute database export (no resume state for regular export)
            $result = $db_exporter->export($sql_file);
            
            if (!$result['completed']) {
                throw new Exception('Database export failed to complete: ' . $result['message']);
            }
            
            // Update status after completion
            $this->update_database_export_status($status_file, 'completed');
            $this->filesystem->log('Database exported successfully to ' . basename($sql_file) . ' - ' . $result['total_tables'] . ' tables, ' . $result['rows_exported'] . ' rows');
            
        } catch (Exception $e) {
            $this->filesystem->log('Database export failed: ' . $e->getMessage());
            throw $e;
        } finally {
            // Always clean up lock files
            @unlink($lock_file);
            @unlink($status_file);
        }
    }

    /**
     * Update database export status.
     */
    private function update_database_export_status($status_file, $status) {
        if (file_exists($status_file)) {
            $current_status = json_decode(file_get_contents($status_file), true);
            $current_status['status'] = $status;
            $current_status['last_update'] = time();
            file_put_contents($status_file, json_encode($current_status));
        }
    }

    /**
     * Count lines in CSV file using unified method.
     */
    private function count_lines_in_csv($csv_file) {
        return Custom_Migrator_File_Enumerator::count_csv_lines($csv_file);
    }
}