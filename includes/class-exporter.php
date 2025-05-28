<?php
/**
 * The class responsible for exporting the website.
 *
 * @package CustomMigrator
 */

/**
 * Exporter class for WordPress website migration.
 */
class Custom_Migrator_Exporter {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * The database handler.
     *
     * @var Custom_Migrator_Database
     */
    private $database;

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
     * Batch processing constants for improved performance and reliability.
     */
    const BATCH_SIZE = 100; // Process 100 files per batch
    const MAX_EXECUTION_TIME = 240; // 4 minutes max per batch
    const MEMORY_THRESHOLD = 0.8; // Stop at 80% memory usage

    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->filesystem = new Custom_Migrator_Filesystem();
        $this->database = new Custom_Migrator_Database();
        $this->metadata = new Custom_Migrator_Metadata();

        // Define exclusion paths
        $this->set_exclusion_paths();
    }

    /**
     * Set paths to be excluded from export.
     */
    private function set_exclusion_paths() {
        $wp_content_dir = WP_CONTENT_DIR;

        $this->exclusion_paths = [
            // Migration and backup directories
            $wp_content_dir . '/ai1wm-backups',
            $wp_content_dir . '/hostinger-migration-archives',
            $wp_content_dir . '/updraft',
            $wp_content_dir . '/backup',
            $wp_content_dir . '/backups',
            $wp_content_dir . '/vivid-migration-backups',
            $wp_content_dir . '/migration-backups',
            
            // Plugin-specific exclusions
            $wp_content_dir . '/plugins/custom-migrator',
            $wp_content_dir . '/plugins/all-in-one-migration',
            $wp_content_dir . '/plugins/updraftplus',
            
            // Cache directories
            $wp_content_dir . '/cache',
            $wp_content_dir . '/wp-cache',
            $wp_content_dir . '/et_cache',
            $wp_content_dir . '/w3tc',
            $wp_content_dir . '/wp-rocket-config',
            
            // Temporary directories
            $wp_content_dir . '/temp',
            $wp_content_dir . '/tmp'
        ];

        // Allow filtering of exclusion paths via a WordPress filter
        $this->exclusion_paths = apply_filters('custom_migrator_export_exclusion_paths', $this->exclusion_paths);
    }

    /**
     * Run the export process.
     * 
     * @return bool Whether the export completed successfully.
     */
    public function export() {
        // Set up execution environment for better resource management
        $this->setup_execution_environment();
        
        // Get export paths with descriptive filenames
        $file_paths = $this->filesystem->get_export_file_paths();
        $hstgr_file = $file_paths['hstgr'];
        $meta_file = $file_paths['metadata'];
        $sql_file = $file_paths['sql'];

        // Check if we're resuming a previously paused export
        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        $is_resuming = file_exists($resume_info_file);

        if ($is_resuming) {
            // Update status to resuming
            $this->filesystem->write_status('resuming');
            $this->filesystem->log('Resuming export process');
        } else {
            // Update status to exporting
            $this->filesystem->write_status('exporting');
            $this->filesystem->log('Starting export process');
        }

        try {
            // Step 1: Create metadata file (only if not resuming)
            if (!$is_resuming) {
                $this->filesystem->log('Generating metadata...');
                $meta = $this->metadata->generate();
                
                // Add export file info to metadata
                $meta['export_info']['file_format'] = $this->file_extension;
                $meta['export_info']['exporter_version'] = CUSTOM_MIGRATOR_VERSION;
                
                file_put_contents($meta_file, wp_json_encode($meta, JSON_PRETTY_PRINT));
                $this->filesystem->log('Metadata generated successfully');
            }

            // Step 2: Export database as plain SQL (not compressed) - only if not resuming
            if (!$is_resuming && !file_exists($sql_file) && !file_exists($sql_file . '.gz')) {
                $this->filesystem->log('Exporting database...');
                $this->database->export($sql_file);
                $this->filesystem->log('Database exported successfully to ' . basename($sql_file));
            } else if ($is_resuming) {
                $this->filesystem->log('Skipping database export as we are resuming content export');
            }

            // Step 3: Export wp-content files with improved resume logic
            $this->filesystem->log('Exporting wp-content files...');
            $content_export_result = $this->export_wp_content_improved($hstgr_file);
            
            // Check if export was paused - if so, return true to indicate successful pause
            if ($content_export_result === 'paused') {
                return true;
            }
            
            $this->filesystem->log('wp-content files exported successfully');

            // Clean up resume file on completion
            if (file_exists($resume_info_file)) {
                @unlink($resume_info_file);
            }

            // Update status to done
            $this->filesystem->write_status('done');
            $this->filesystem->log('Export completed successfully');
            
            return true;
        } catch (Exception $e) {
            // Update status to error with specific message
            $error_message = 'Export failed: ' . $e->getMessage();
            $this->filesystem->write_status('error: ' . $error_message);
            $this->filesystem->log($error_message);
            
            // Add additional debugging information if possible
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->filesystem->log('Error trace: ' . $e->getTraceAsString());
            }
            
            return false;
        }
    }

    /**
     * Setup execution environment with better resource management.
     * 
     * @return void
     */
    private function setup_execution_environment() {
        // Set time limit
        if (function_exists('set_time_limit') && !ini_get('safe_mode')) {
            @set_time_limit(0);
        }
        
        // Increase memory limit more aggressively
        $current_limit = $this->get_memory_limit_bytes();
        $target_limit = max($current_limit, 1024 * 1024 * 1024); // At least 1GB
        
        @ini_set('memory_limit', $this->format_bytes($target_limit));
        
        // Disable output buffering to prevent memory issues
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Force garbage collection
        if (function_exists('gc_enable')) {
            gc_enable();
        }
        
        $this->filesystem->log('Execution environment setup: Memory limit = ' . ini_get('memory_limit') . ', Time limit = ' . ini_get('max_execution_time'));
    }

    /**
     * Export WordPress content files to archive with improved resume capability.
     *
     * @param string $hstgr_file The path to the archive file.
     * @return string|bool True on success, 'paused' if export was paused, or throws an Exception.
     * @throws Exception If the export fails.
     */
    private function export_wp_content_improved($hstgr_file) {
        $wp_content_dir = WP_CONTENT_DIR;
        
        // Check if this is a resume operation
        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        $resume_data = $this->load_resume_data($resume_info_file);
        $files_processed = $resume_data['files_processed'];
        $bytes_processed = $resume_data['bytes_processed'];
        $last_file_path = $resume_data['last_file_path'] ?? '';
        $file_list_cache = $resume_data['file_list'] ?? null;
        $skipped_count = $resume_data['skipped_count'] ?? 0;
        
        if ($files_processed > 0) {
            $this->filesystem->log("Resuming export from file " . $files_processed);
        } else {
            $skipped_count = 0;
        }
        
        // Build or load file list to avoid iterator recreation issues
        if (!$file_list_cache) {
            $this->filesystem->log('Building file list...');
            $file_list_cache = $this->build_file_list($wp_content_dir);
            $this->filesystem->log('File list built: ' . count($file_list_cache) . ' files');
        }
        
        // For a new export or if resuming an existing one
        $append_mode = $files_processed > 0 ? 'ab' : 'wb';
        
        // Open the archive file for writing
        $hstgr_fp = fopen($hstgr_file, $append_mode);
        if (!$hstgr_fp) {
            throw new Exception('Cannot create or open archive file');
        }

        // Track the number of files processed
        $start_time = microtime(true);
        
        // Only write header if this is a new export
        if ($files_processed === 0) {
            // Write archive header with format info
            $header = "# Hostinger Migration File\n";
            $header .= "# Format: HSTGR-1.0\n";
            $header .= "# Date: " . gmdate('Y-m-d H:i:s') . "\n";
            $header .= "# Generator: Hostinger Migrator v" . CUSTOM_MIGRATOR_VERSION . "\n\n";
            fwrite($hstgr_fp, $header);
        }

        $batch_start_time = microtime(true);
        $files_in_batch = 0;
        $skip_until_found = !empty($last_file_path);
        
        // Set a sensible time limit - process for at most 4 minutes then save state
        $max_execution_time = self::MAX_EXECUTION_TIME;
        $execution_start = time();
        
        // Loop through each file in the pre-built list
        foreach ($file_list_cache as $file_info) {
            // Check if we need to pause processing based on resource limits
            if ($this->should_pause_processing($execution_start, $batch_start_time, $files_in_batch)) {
                // Save our progress and resume later
                $this->filesystem->log("Approaching resource limit, saving state after processing $files_processed files");
                
                // Save resume information
                $this->save_resume_data($resume_info_file, [
                    'files_processed' => $files_processed,
                    'skipped_count' => $skipped_count,
                    'bytes_processed' => $bytes_processed,
                    'last_file_path' => $file_info['path'],
                    'file_list' => $file_list_cache,
                    'last_update' => time()
                ]);
                
                // Write current stats to the end of the file - these will be overwritten on resume
                $temp_stats = "\n__temp_stats__\n";
                $temp_stats .= "processed_files:" . $files_processed . "\n";
                $temp_stats .= "skipped_files:" . $skipped_count . "\n";
                $temp_stats .= "processed_size:" . $bytes_processed . "\n";
                $temp_stats .= "resume:true\n";
                $temp_stats .= "__to_be_continued__\n";
                
                fwrite($hstgr_fp, $temp_stats);
                fclose($hstgr_fp);
                
                // Update the status to paused so the frontend knows
                $this->filesystem->write_status('paused');
                
                // Schedule the export to continue
                wp_schedule_single_event(time() + 5, 'cm_run_export');
                
                // Return paused status
                return 'paused';
            }
            
            $real_path = $file_info['path'];
            
            // Skip files until we reach the resume point
            if ($skip_until_found) {
                if ($real_path === $last_file_path) {
                    $skip_until_found = false;
                }
                continue;
            }
            
            // Check if the file is in any of the exclusion paths
            $is_excluded = $this->is_file_excluded($real_path);
            
            if ($is_excluded) {
                $skipped_count++;
                continue;
            }
            
            // Process the file
            $result = $this->process_single_file($hstgr_fp, $file_info);
            if ($result['success']) {
                $files_processed++;
                $bytes_processed += $result['bytes'];
                $files_in_batch++;
                
                // Log periodically to show progress
                if ($files_processed % 1000 === 0) {
                    $elapsed = microtime(true) - $start_time;
                    $rate = $files_processed / max($elapsed, 1);
                    $bytes_rate = ($bytes_processed / max($elapsed, 1)) / (1024 * 1024); // MB/s
                    $this->filesystem->log(sprintf(
                        "Processed %d files (%.2f MB). Rate: %.2f files/sec (%.2f MB/s)",
                        $files_processed,
                        $bytes_processed / (1024 * 1024),
                        $rate,
                        $bytes_rate
                    ));
                }
            }
        }
        
        // If we get here, the export is complete
        // Write archive footer with statistics
        $footer = "\n__stats__\n";
        $footer .= "total_files:" . $files_processed . "\n";
        $footer .= "skipped_files:" . $skipped_count . "\n";
        $footer .= "total_size:" . $bytes_processed . "\n";
        $footer .= "export_time:" . round(microtime(true) - $start_time, 2) . "\n";
        $footer .= "__done__\n";
        
        fwrite($hstgr_fp, $footer);
        fclose($hstgr_fp);
        
        $total_time = microtime(true) - $start_time;
        $this->filesystem->log(sprintf(
            "Exported %d files (%.2f MB) in %.2f seconds, skipped %d files",
            $files_processed,
            $bytes_processed / (1024 * 1024),
            $total_time,
            $skipped_count
        ));
        
        return true;
    }

    /**
     * Build a complete file list to avoid iterator recreation issues.
     *
     * @param string $wp_content_dir The wp-content directory path.
     * @return array Array of file information.
     */
    private function build_file_list($wp_content_dir) {
        $file_list = [];
        
        try {
            // Create a recursive iterator for wp-content directory
            $directory_iterator = new RecursiveDirectoryIterator(
                $wp_content_dir, 
                RecursiveDirectoryIterator::SKIP_DOTS 
            );
            
            $iterator = new RecursiveIteratorIterator(
                $directory_iterator,
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $real_path = $file->getRealPath();
                    $relative_path = 'wp-content/' . substr($real_path, strlen($wp_content_dir) + 1);
                    
                    $file_list[] = [
                        'path' => $real_path,
                        'relative' => $relative_path,
                        'size' => $file->getSize()
                    ];
                }
            }
        } catch (Exception $e) {
            $this->filesystem->log('Error building file list: ' . $e->getMessage());
            throw new Exception('Failed to build file list: ' . $e->getMessage());
        }
        
        return $file_list;
    }

    /**
     * Process a single file and add it to the archive.
     *
     * @param resource $hstgr_fp The file pointer for the archive.
     * @param array $file_info File information array.
     * @return array Result array with success status and bytes processed.
     */
    private function process_single_file($hstgr_fp, $file_info) {
        $real_path = $file_info['path'];
        $relative_path = $file_info['relative'];
        $file_size = $file_info['size'];
        
        if (!file_exists($real_path) || !is_readable($real_path)) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Calculate file hash for integrity check
        $file_md5 = md5_file($real_path);
        
        // Write file header with metadata
        $file_header = "__file__:" . $relative_path . "\n";
        $file_header .= "__size__:" . $file_size . "\n";
        $file_header .= "__md5__:" . $file_md5 . "\n";
        
        if (fwrite($hstgr_fp, $file_header) === false) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Write file content using binary safe file operations
        $file_handle = fopen($real_path, 'rb');
        if (!$file_handle) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Use an optimized buffer size for better performance
        $buffer_size = 8192 * 16; // 128KB buffer
        $bytes_written = 0;
        
        while (!feof($file_handle)) {
            $buffer = fread($file_handle, $buffer_size);
            if ($buffer === false) {
                // Handle read error
                $this->filesystem->log("Error reading file: " . $real_path);
                break;
            }
            
            $write_result = fwrite($hstgr_fp, $buffer);
            if ($write_result === false || $write_result != strlen($buffer)) {
                // Handle write error
                $this->filesystem->log("Error writing to archive: " . $real_path);
                break;
            }
            
            $bytes_written += $write_result;
        }
        fclose($file_handle);
        
        // Write file footer
        fwrite($hstgr_fp, "\n__endfile__\n");
        
        return ['success' => true, 'bytes' => $file_size];
    }

    /**
     * Check if processing should be paused based on resource limits.
     *
     * @param int $start_time Start time of processing.
     * @param float $batch_start_time Start time of current batch.
     * @param int $files_in_batch Number of files processed in current batch.
     * @return bool Whether processing should be paused.
     */
    private function should_pause_processing($start_time, $batch_start_time, $files_in_batch) {
        // Time-based pause
        if ((time() - $start_time) > self::MAX_EXECUTION_TIME) {
            return true;
        }
        
        // Memory-based pause
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit_bytes();
        if ($memory_usage > ($memory_limit * self::MEMORY_THRESHOLD)) {
            $this->filesystem->log('Memory threshold reached: ' . $this->format_bytes($memory_usage) . ' / ' . $this->format_bytes($memory_limit));
            return true;
        }
        
        // Batch-based pause (process at least some files per batch)
        if ($files_in_batch >= self::BATCH_SIZE && (microtime(true) - $batch_start_time) > 30) {
            return true;
        }
        
        return false;
    }

    /**
     * Load resume data from file.
     *
     * @param string $resume_info_file Path to resume info file.
     * @return array Resume data array.
     */
    private function load_resume_data($resume_info_file) {
        if (!file_exists($resume_info_file)) {
            return [
                'files_processed' => 0,
                'bytes_processed' => 0,
                'skipped_count' => 0,
                'last_file_path' => '',
                'file_list' => null
            ];
        }
        
        $data = json_decode(file_get_contents($resume_info_file), true);
        return $data ?: [
            'files_processed' => 0,
            'bytes_processed' => 0,
            'skipped_count' => 0,
            'last_file_path' => '',
            'file_list' => null
        ];
    }

    /**
     * Save resume data to file.
     *
     * @param string $resume_info_file Path to resume info file.
     * @param array $data Resume data to save.
     * @return void
     */
    private function save_resume_data($resume_info_file, $data) {
        file_put_contents($resume_info_file, json_encode($data));
    }

    /**
     * Check if a file should be excluded from export.
     *
     * @param string $file_path The file path to check.
     * @return bool Whether the file should be excluded.
     */
    private function is_file_excluded($file_path) {
        foreach ($this->exclusion_paths as $excluded_path) {
            if (strpos($file_path, $excluded_path) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get memory limit in bytes.
     *
     * @return int Memory limit in bytes.
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
     * Format bytes to human readable format.
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted string.
     */
    private function format_bytes($bytes) {
        $units = ['B', 'K', 'M', 'G'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f%s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}