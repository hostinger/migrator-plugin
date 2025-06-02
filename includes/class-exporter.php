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
     * Block format for binary archive with optimized structure
     * 
     * @var array
     */
    private $block_format = [
        'pack' => 'a255VVa4112',  // filename(255), size(4), date(4), path(4112) = 4375 bytes
        'unpack' => 'a255filename/Vsize/Vdate/a4112path'  // For unpack: named fields
    ];

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

        $this->exclusion_paths = apply_filters('custom_migrator_export_exclusion_paths', $this->exclusion_paths);
    }

    /**
     * Run the export process.
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
                $meta = $this->metadata->generate();
                $meta['export_info']['file_format'] = $this->file_extension;
                $meta['export_info']['exporter_version'] = CUSTOM_MIGRATOR_VERSION;
                
                file_put_contents($meta_file, wp_json_encode($meta, JSON_PRETTY_PRINT));
                $this->filesystem->log('Metadata generated successfully');
            }

            if (!$is_resuming && !file_exists($sql_file) && !file_exists($sql_file . '.gz')) {
                $this->filesystem->log('Exporting database...');
                $this->database->export($sql_file);
                $this->filesystem->log('Database exported successfully to ' . basename($sql_file));
            } else if ($is_resuming) {
                $this->filesystem->log('Skipping database export as we are resuming content export');
            }

            $this->filesystem->log('Exporting wp-content files...');
            $content_export_result = $this->export_wp_content_archive($hstgr_file);
            
            if ($content_export_result === 'paused') {
                return true;
            }
            
            $this->filesystem->log('wp-content files exported successfully');

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
     * Export WordPress content files using direct streaming approach.
     * No CSV intermediary - process files immediately as they're discovered.
     */
    private function export_wp_content_archive($hstgr_file) {
        $wp_content_dir = WP_CONTENT_DIR;
        
        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        
        // Load resume data with binary offset tracking
        $resume_data = $this->load_resume_data($resume_info_file);
        $files_processed = $resume_data['files_processed'];
        $bytes_processed = $resume_data['bytes_processed'];
        $last_file_path = $resume_data['last_file_path'] ?? '';
        $archive_offset = $resume_data['archive_offset'] ?? 0;
        $skipped_count = $resume_data['skipped_count'] ?? 0;
        
        $is_resuming = $files_processed > 0;
        
        if ($is_resuming) {
            $this->filesystem->log("Resuming direct stream from file: " . basename($last_file_path) . " (processed: $files_processed files)");
        } else {
            $this->filesystem->log('Starting direct streaming export');
        }
        
        // Open archive in append mode if resuming, write mode if starting fresh
        $archive_mode = $is_resuming ? 'ab' : 'wb';
        $archive_handle = fopen($hstgr_file, $archive_mode);
        if (!$archive_handle) {
            throw new Exception('Cannot create or open archive file');
        }

        // If resuming, seek to the correct position
        if ($is_resuming && $archive_offset > 0) {
            fseek($archive_handle, $archive_offset);
        }

        $start_time = time();
        $batch_start_time = time();
        $files_in_batch = 0;
        $should_skip = $is_resuming; // Skip files until we reach resume point
        
        try {
            // Direct iteration - no CSV intermediary
            $directory_iterator = new RecursiveDirectoryIterator(
                $wp_content_dir, 
                RecursiveDirectoryIterator::SKIP_DOTS 
            );
            
            $iterator = new RecursiveIteratorIterator(
                $directory_iterator,
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                // Check for pause conditions much less frequently (every 1000 files)
                if ($files_in_batch % 1000 === 0) {
                    if ($this->should_pause_processing($start_time, $batch_start_time, $files_in_batch)) {
                        $this->filesystem->log("Pausing after processing $files_processed files (direct streaming)");
                        
                        // Save current position for resume
                        $current_offset = ftell($archive_handle);
                        $this->save_resume_data($resume_info_file, [
                            'files_processed' => $files_processed,
                            'skipped_count' => $skipped_count,
                            'bytes_processed' => $bytes_processed,
                            'last_file_path' => $file->getRealPath(),
                            'archive_offset' => $current_offset,
                            'last_update' => time()
                        ]);
                        
                        fclose($archive_handle);
                        $this->filesystem->write_status('paused');
                        
                        // Schedule immediate resume and exit immediately (All-in-One WP Migration approach)
                        $this->schedule_immediate_resume();
                        
                        // Exit immediately after HTTP request like All-in-One WP Migration
                        exit();
                    }
                }
                
                if (!$file->isFile()) {
                    continue;
                }
                
                $real_path = $file->getRealPath();
                
                // Skip files if we're resuming and haven't reached the resume point yet
                if ($should_skip) {
                    if ($real_path === $last_file_path) {
                        $should_skip = false; // Found resume point, start processing next file
                        $this->filesystem->log("Reached resume point: " . basename($last_file_path));
                    }
                    continue;
                }
                
                // Check exclusions
                if ($this->is_file_excluded($real_path)) {
                    $skipped_count++;
                    continue;
                }
                
                // Process file immediately (no CSV storage)
                $relative_path = 'wp-content/' . substr($real_path, strlen($wp_content_dir) + 1);
                
                // Validate file before processing
                if (!is_readable($real_path)) {
                    $this->filesystem->log("Warning: File not readable, skipping: " . basename($real_path));
                    $skipped_count++;
                    continue;
                }
                
                // Get file size safely
                $file_size = filesize($real_path);
                if ($file_size === false) {
                    $this->filesystem->log("Warning: Cannot get file size, skipping: " . basename($real_path));
                    $skipped_count++;
                    continue;
                }
                
                $file_info = [
                    'path' => $real_path,
                    'relative' => $relative_path,
                    'size' => $file_size
                ];
                
                $result = $this->add_file_to_archive($archive_handle, $file_info);
                
                if (!$result['success']) {
                    $this->filesystem->log("Warning: Failed to archive file: " . basename($real_path) . " - continuing with next file");
                    $skipped_count++;
                    continue;
                }
                
                // Verify file was written correctly
                if ($result['bytes'] !== $file_size) {
                    $this->filesystem->log("Warning: File size mismatch for: " . basename($real_path) . " (expected: $file_size, written: {$result['bytes']})");
                }

                $files_processed++;
                $bytes_processed += $result['bytes'];
                $files_in_batch++;
                
                // Progress logging every 5000 files (like All-in-One WP Migration)
                if ($files_processed % 5000 === 0) {
                    $elapsed = time() - $start_time;
                    $rate = $files_processed / max($elapsed, 1);
                    $bytes_rate = ($bytes_processed / max($elapsed, 1)) / (1024 * 1024);
                    $this->filesystem->log(sprintf(
                        "Direct streaming: %d files (%.2f MB). Rate: %.2f files/sec (%.2f MB/s)",
                        $files_processed,
                        $bytes_processed / (1024 * 1024),
                        $rate,
                        $bytes_rate
                    ));
                }
                
                // Minimal I/O throttling - only every 1000 files  
                if ($files_in_batch % 1000 === 0) {
                    usleep(10000); // 0.01 second pause only
                }
            }
            
        } catch (Exception $e) {
            fclose($archive_handle);
            throw new Exception('Direct streaming failed: ' . $e->getMessage());
        }
        
        fclose($archive_handle);
        
        // Clean up resume file when completed
        if (file_exists($resume_info_file)) {
            @unlink($resume_info_file);
        }
        
        $total_time = time() - $start_time;
        $this->filesystem->log(sprintf(
            "Direct streaming completed: %d files (%.2f MB) in %d seconds, skipped %d files",
            $files_processed,
            $bytes_processed / (1024 * 1024),
            $total_time,
            $skipped_count
        ));
        
        return true;
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
        
        // Truncate strings to fit the fixed-size fields
        if (strlen($file_name) > 254) {
            $file_name = substr($file_name, 0, 254);
        }
        if (strlen($file_dir) > 4111) {
            $file_dir = substr($file_dir, 0, 4111);
        }
        
        // Create properly formatted binary block header
        // Order must match the pack format: filename, size, date, path
        $block = pack($this->block_format['pack'], $file_name, $file_size, $file_date, $file_dir);
        
        // Verify block size is correct
        $expected_size = 255 + 4 + 4 + 4112; // 4375 bytes
        if (strlen($block) !== $expected_size) {
            $this->filesystem->log("Error: Binary block size mismatch for {$file_name}. Expected: {$expected_size}, Got: " . strlen($block));
            return ['success' => false, 'bytes' => 0];
        }
        
        // Write the header block with verification
        $header_written = fwrite($archive_handle, $block);
        if ($header_written === false || $header_written !== $expected_size) {
            $this->filesystem->log("Error: Failed to write header block for {$file_name}");
            return ['success' => false, 'bytes' => 0];
        }
        
        // Open and copy file content with verification
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            $this->filesystem->log("Error: Cannot open file for reading: " . basename($file_path));
            return ['success' => false, 'bytes' => 0];
        }
        
        $bytes_copied = 0;
        $chunk_size = self::CHUNK_SIZE; // 512KB chunks
        
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
        }
        
        fclose($file_handle);
        
        // Final verification - ensure we copied the expected amount
        if ($bytes_copied !== $file_size) {
            $this->filesystem->log("Warning: Bytes copied ($bytes_copied) doesn't match file size ($file_size) for: " . basename($file_path));
        }
        
        return ['success' => true, 'bytes' => $bytes_copied];
    }

    /**
     * Check if processing should be paused.
     */
    private function should_pause_processing($start_time, $batch_start_time, $files_in_batch) {
        // Check 10-second execution time limit
        if ((time() - $start_time) >= self::MAX_EXECUTION_TIME) {
            return true;
        }
        
        // Check memory threshold (more aggressive)
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit_bytes();
        if ($memory_usage > ($memory_limit * self::MEMORY_THRESHOLD)) {
            $this->filesystem->log('Memory threshold reached: ' . $this->format_bytes($memory_usage) . ' / ' . $this->format_bytes($memory_limit));
            return true;
        }
        
        // Pause if we've processed enough files in current batch (smaller batches)
        if ($files_in_batch >= self::BATCH_SIZE) {
            return true;
        }
        
        return false;
    }

    /**
     * Load resume data with binary offset tracking.
     */
    private function load_resume_data($resume_info_file) {
        if (!file_exists($resume_info_file)) {
            return [
                'files_processed' => 0,
                'bytes_processed' => 0,
                'skipped_count' => 0,
                'last_file_path' => '',
                'archive_offset' => 0
            ];
        }
        
        $data = json_decode(file_get_contents($resume_info_file), true);
        return $data ?: [
            'files_processed' => 0,
            'bytes_processed' => 0,
            'skipped_count' => 0,
            'last_file_path' => '',
            'archive_offset' => 0
        ];
    }

    /**
     * Save resume data.
     */
    private function save_resume_data($resume_info_file, $data) {
        file_put_contents($resume_info_file, json_encode($data));
    }

    /**
     * Check if file is excluded.
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
     * Format bytes.
     */
    private function format_bytes($bytes) {
        $units = ['B', 'K', 'M', 'G'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f%s", $bytes / pow(1024, $factor), $units[$factor]);
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
     * Export only database (step 3 of step-by-step export).
     * 
     * @return bool Success status.
     */
    public function export_database_only() {
        $this->filesystem->log('Starting database export step');
        
        $file_paths = $this->filesystem->get_export_file_paths();
        $sql_file = $file_paths['sql'];
        
        // Check if database file already exists
        if (file_exists($sql_file) || file_exists($sql_file . '.gz')) {
            $this->filesystem->log('Database file already exists, skipping');
            return true;
        }
        
        $this->database->export($sql_file);
        $this->filesystem->log('Database export completed');
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
        
        // Check if metadata file already exists
        if (file_exists($meta_file)) {
            $this->filesystem->log('Metadata file already exists, skipping');
            return true;
        }
        
        $meta = $this->metadata->generate();
        $meta['export_info']['file_format'] = $this->file_extension;
        $meta['export_info']['exporter_version'] = CUSTOM_MIGRATOR_VERSION;
        
        file_put_contents($meta_file, wp_json_encode($meta, JSON_PRETTY_PRINT));
        $this->filesystem->log('Metadata generation completed');
        return true;
    }

    /**
     * Schedule immediate resume (simple approach, no secret keys).
     */
    private function schedule_immediate_resume() {
        $ajax_url = admin_url('admin-ajax.php');
        $request_params = array(
            'action' => 'cm_run_export_now',
            'background_mode' => '1'
        );
        
        // Method 1: Immediate HTTP request
        wp_remote_post($ajax_url, array(
            'method'    => 'POST',
            'timeout'   => 10,
            'blocking'  => false,
            'sslverify' => false,
            'headers'   => array(),
            'body'      => $request_params,
        ));
        
        $this->filesystem->log('Initiated immediate resume via HTTP POST (no secret keys)');
        
        // Method 2: Backup cron scheduling  
        wp_schedule_single_event(time() + 1, 'cm_run_export');
        wp_schedule_single_event(time() + 3, 'cm_run_export');
        
        $this->filesystem->log('Scheduled multiple resume methods for reliability');
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
}