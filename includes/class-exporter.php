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
     * Block format for binary archive (corrected PHP pack/unpack format)
     * 
     * @var array
     */
    private $block_format = [
        'pack' => 'a4096VVa4096',  // For pack: filename, size, date, path
        'unpack' => 'a4096filename/Vsize/Vdate/a4096path'  // For unpack: named fields
    ];

    /**
     * Batch processing constants.
     */
    const BATCH_SIZE = 100;
    const MAX_EXECUTION_TIME = 240;
    const MEMORY_THRESHOLD = 0.8;

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
     * Export WordPress content files using structured binary archive.
     */
    private function export_wp_content_archive($hstgr_file) {
        $wp_content_dir = WP_CONTENT_DIR;
        
        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        $resume_data = $this->load_resume_data($resume_info_file);
        $files_processed = $resume_data['files_processed'];
        $bytes_processed = $resume_data['bytes_processed'];
        $last_file_path = $resume_data['last_file_path'] ?? '';
        $file_list_cache = $resume_data['file_list'] ?? null;
        $skipped_count = $resume_data['skipped_count'] ?? 0;
        
        if ($files_processed > 0) {
            $this->filesystem->log("Resuming export from file " . $files_processed);
        }
        
        if (!$file_list_cache) {
            $this->filesystem->log('Building file list...');
            $file_list_cache = $this->build_file_list($wp_content_dir);
            $this->filesystem->log('File list built: ' . count($file_list_cache) . ' files');
        }
        
        $append_mode = $files_processed > 0 ? 'ab' : 'wb';
        
        $archive_handle = fopen($hstgr_file, $append_mode);
        if (!$archive_handle) {
            throw new Exception('Cannot create or open archive file');
        }

        $start_time = microtime(true);
        $batch_start_time = microtime(true);
        $files_in_batch = 0;
        $skip_until_found = !empty($last_file_path);
        $execution_start = time();
        
        foreach ($file_list_cache as $file_info) {
            if ($this->should_pause_processing($execution_start, $batch_start_time, $files_in_batch)) {
                $this->filesystem->log("Approaching resource limit, saving state after processing $files_processed files");
                
                $this->save_resume_data($resume_info_file, [
                    'files_processed' => $files_processed,
                    'skipped_count' => $skipped_count,
                    'bytes_processed' => $bytes_processed,
                    'last_file_path' => $file_info['path'],
                    'file_list' => $file_list_cache,
                    'last_update' => time()
                ]);
                
                fclose($archive_handle);
                
                $this->filesystem->write_status('paused');
                wp_schedule_single_event(time() + 5, 'cm_run_export');
                
                return 'paused';
            }
            
            $real_path = $file_info['path'];
            
            if ($skip_until_found) {
                if ($real_path === $last_file_path) {
                    $skip_until_found = false;
                }
                continue;
            }
            
            if ($this->is_file_excluded($real_path)) {
                $skipped_count++;
                continue;
            }
            
            $result = $this->add_file_to_archive($archive_handle, $file_info);
            if ($result['success']) {
                $files_processed++;
                $bytes_processed += $result['bytes'];
                $files_in_batch++;
                
                if ($files_processed % 500 === 0) {
                    $elapsed = microtime(true) - $start_time;
                    $rate = $files_processed / max($elapsed, 1);
                    $bytes_rate = ($bytes_processed / max($elapsed, 1)) / (1024 * 1024);
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
        
        fclose($archive_handle);
        
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
     * Add a file to the binary archive using the structured format.
     */
    private function add_file_to_archive($archive_handle, $file_info) {
        $file_path = $file_info['path'];
        $relative_path = $file_info['relative'];
        $file_size = $file_info['size'];
        
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Get file stats
        $stat = stat($file_path);
        if ($stat === false) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Prepare file info for binary block - ensure strings fit in allocated space
        $file_name = basename($file_path);
        $file_date = $stat['mtime'];
        $file_dir = dirname($relative_path);
        
        // Truncate strings to fit the fixed-size fields
        if (strlen($file_name) > 4095) {
            $file_name = substr($file_name, 0, 4095);
        }
        if (strlen($file_dir) > 4095) {
            $file_dir = substr($file_dir, 0, 4095);
        }
        
        // Create properly formatted binary block header
        // Order must match the pack format: filename, size, date, path
        $block = pack($this->block_format['pack'], $file_name, $file_size, $file_date, $file_dir);
        
        // Verify block size is correct
        $expected_size = 4096 + 4 + 4 + 4096; // 8200 bytes
        if (strlen($block) !== $expected_size) {
            $this->filesystem->log("Warning: Binary block size mismatch for {$file_name}. Expected: {$expected_size}, Got: " . strlen($block));
            return ['success' => false, 'bytes' => 0];
        }
        
        // Write the header block
        if (fwrite($archive_handle, $block) === false) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Open and copy file content
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            return ['success' => false, 'bytes' => 0];
        }
        
        $bytes_written = 0;
        $buffer_size = 512000; // 512KB buffer like All-in-One
        
        while (!feof($file_handle)) {
            $content = fread($file_handle, $buffer_size);
            if ($content === false) {
                break;
            }
            
            $written = fwrite($archive_handle, $content);
            if ($written === false || $written !== strlen($content)) {
                $this->filesystem->log("Error writing file content: " . $file_path);
                break;
            }
            
            $bytes_written += $written;
        }
        
        fclose($file_handle);
        
        return ['success' => true, 'bytes' => $file_size];
    }

    /**
     * Build file list.
     */
    private function build_file_list($wp_content_dir) {
        $file_list = [];
        
        try {
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
     * Check if processing should be paused.
     */
    private function should_pause_processing($start_time, $batch_start_time, $files_in_batch) {
        if ((time() - $start_time) > self::MAX_EXECUTION_TIME) {
            return true;
        }
        
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit_bytes();
        if ($memory_usage > ($memory_limit * self::MEMORY_THRESHOLD)) {
            $this->filesystem->log('Memory threshold reached: ' . $this->format_bytes($memory_usage) . ' / ' . $this->format_bytes($memory_limit));
            return true;
        }
        
        if ($files_in_batch >= self::BATCH_SIZE && (microtime(true) - $batch_start_time) > 30) {
            return true;
        }
        
        return false;
    }

    /**
     * Load resume data.
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
}