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
                $meta = $this->metadata->generate();
                $meta['export_info']['file_format'] = $this->file_extension;
                $meta['export_info']['exporter_version'] = CUSTOM_MIGRATOR_VERSION;
                
                file_put_contents($meta_file, wp_json_encode($meta, JSON_PRETTY_PRINT));
                $this->filesystem->log('Metadata generated successfully');
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
        $csv_offset = $resume_data['csv_offset'] ?? 0;
        $archive_offset = $resume_data['archive_offset'] ?? 0;
        $files_processed = $resume_data['files_processed'] ?? 0;
        $bytes_processed = $resume_data['bytes_processed'] ?? 0;
        
        $is_resuming = $csv_offset > 0 || $archive_offset > 0;
        
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
        
        // Seek to archive offset if resuming
        if ($archive_offset > 0) {
            fseek($archive_handle, $archive_offset);
        }
        
        // Start precise timing for optimal resource management
        $start = microtime(true);
        $completed = true;
        
        try {
            // Process files from CSV one at a time for hosting-friendly resource usage
            while (($file_data = fgetcsv($csv_handle)) !== FALSE) {
                
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
                
                $result = $this->add_file_to_archive($archive_handle, $file_info);
                
                if ($result['success']) {
                    $files_processed++;
                    $bytes_processed += $result['bytes'];
                    
                    // Progress logging every 1000 files
                    if ($files_processed % 1000 === 0) {
                        $elapsed = microtime(true) - $start;
                        $rate = $files_processed / max($elapsed, 1);
                        $this->filesystem->log(sprintf(
                            "Single-file processing: %d files (%.2f MB). Rate: %.2f files/sec",
                            $files_processed,
                            $bytes_processed / (1024 * 1024),
                            $rate
                        ));
                    }
                }
                
                // Check timeout immediately after each file for hosting compatibility
                if (($timeout = apply_filters('ai1wm_completed_timeout', 10))) {
                    if ((microtime(true) - $start) > $timeout) {
                        $this->filesystem->log("Pausing after processing $files_processed files (optimal timing)");
                        
                        // Save CSV and archive positions for precise resume
                        $current_csv_offset = ftell($csv_handle);
                        $current_archive_offset = ftell($archive_handle);
                        
                        $this->save_resume_data($resume_info_file, [
                            'csv_offset' => $current_csv_offset,
                            'archive_offset' => $current_archive_offset,
                            'files_processed' => $files_processed,
                            'bytes_processed' => $bytes_processed,
                            'last_update' => time()
                        ]);
                        
                        $completed = false;
                        break; // Pause immediately for hosting compatibility
                    }
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
            
            $total_time = microtime(true) - $start;
            $this->filesystem->log(sprintf(
                "Single-file processing completed: %d files (%.2f MB) in %.2f seconds",
                $files_processed,
                $bytes_processed / (1024 * 1024),
                $total_time
            ));
        }
        
        return $completed ? true : 'paused';
    }

    /**
     * Enumerate content files into CSV for efficient processing.
     */
    private function enumerate_content_files($content_list_file) {
        $wp_content_dir = WP_CONTENT_DIR;
        
        $this->filesystem->log('Enumerating files into CSV...');
        
        $csv_handle = fopen($content_list_file, 'w');
        if (!$csv_handle) {
            throw new Exception('Cannot create content list file');
        }
        
        try {
            // Use efficient directory iteration
            $directory_iterator = new RecursiveDirectoryIterator(
                $wp_content_dir, 
                RecursiveDirectoryIterator::SKIP_DOTS 
            );
            
            $iterator = new RecursiveIteratorIterator(
                $directory_iterator,
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            $file_count = 0;
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                
                $real_path = $file->getRealPath();
                
                // Check exclusions
                if ($this->is_file_excluded($real_path)) {
                    continue;
                }
                
                // Get file stats
                $file_size = filesize($real_path);
                $file_mtime = filemtime($real_path);
                $relative_path = 'wp-content/' . substr($real_path, strlen($wp_content_dir) + 1);
                
                if ($file_size === false || $file_mtime === false) {
                    continue;
                }
                
                // Write to CSV: [absolute_path, relative_path, size, mtime]
                fputcsv($csv_handle, [$real_path, $relative_path, $file_size, $file_mtime]);
                $file_count++;
                
                // Progress every 5000 files
                if ($file_count % 5000 === 0) {
                    $this->filesystem->log("Enumerated $file_count files...");
                }
            }
            
        } catch (Exception $e) {
            fclose($csv_handle);
            throw new Exception('File enumeration failed: ' . $e->getMessage());
        }
        
        fclose($csv_handle);
        $this->filesystem->log("File enumeration complete: $file_count files");
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
     * Check if processing should be paused (optimized timeout pattern).
     * This function is no longer used since we check timeout directly in the main loop.
     */
    private function should_pause_processing($start_time, $batch_start_time, $files_in_batch) {
        // NOTE: This function is kept for compatibility but not used anymore.
        // We now use optimized pattern: apply_filters('ai1wm_completed_timeout', 10)
        // and check timeout immediately after each file in the main loop.
        
        return false; // Never called in the new single-file approach
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
                    $progress_time = $status['last_update'] ?? 0;
                    
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
            
            $this->database->export($sql_file);
            
            // Update status after completion
            $this->update_database_export_status($status_file, 'completed');
            $this->filesystem->log('Database exported successfully to ' . basename($sql_file));
            
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
}