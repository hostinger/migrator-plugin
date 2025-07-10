<?php
/**
 * Unified file enumerator for WordPress content files.
 *
 * @package CustomMigrator
 */

/**
 * File Enumerator class for scanning and cataloging WordPress content files.
 */
class Custom_Migrator_File_Enumerator {

    /**
     * Filesystem instance for logging.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * Constructor.
     *
     * @param Custom_Migrator_Filesystem $filesystem Filesystem instance for logging.
     */
    public function __construct($filesystem) {
        $this->filesystem = $filesystem;
    }

    /**
     * Enumerate WordPress content files into a CSV file.
     *
     * @param string $csv_file_path Path where the CSV file will be created.
     * @param array  $options       Optional configuration options.
     * @return array Statistics about the enumeration process.
     * @throws Exception If enumeration fails.
     */
    public function enumerate_to_csv($csv_file_path, $options = array()) {
        $start_time = microtime(true);
        
        // Parse options with defaults
        $config = $this->parse_options($options);
        
        // Apply unlimited execution environment if requested
        if (!empty($config['use_unlimited_execution'])) {
            $this->setup_unlimited_execution_environment();
        }
        
        $this->filesystem->log('Starting file enumeration with unified enumerator...');
        
        // Validate source directory
        if (!is_dir($config['source_dir']) || !is_readable($config['source_dir'])) {
            throw new Exception('Source directory is not accessible: ' . $config['source_dir']);
        }
        
        // Create CSV file
        $csv_handle = fopen($csv_file_path, 'w');
        if (!$csv_handle) {
            throw new Exception('Cannot create CSV file: ' . $csv_file_path);
        }
        
        $stats = array(
            'files_found' => 0,
            'files_excluded' => 0,
            'files_excluded_by_size' => 0,  // Track large file exclusions separately
            'total_size' => 0,
            'errors' => 0,
            'start_time' => $start_time
        );
        
        try {
            $stats = $this->scan_directory($csv_handle, $config, $stats);
            
        } catch (Exception $e) {
            fclose($csv_handle);
            throw new Exception('File enumeration failed: ' . $e->getMessage());
        }
        
        fclose($csv_handle);
        
        $elapsed = microtime(true) - $start_time;
        $stats['elapsed_time'] = $elapsed;
        
        $this->log_enumeration_results($stats);
        
        return $stats;
    }

    /**
     * Parse and validate enumeration options.
     *
     * @param array $options User-provided options.
     * @return array Parsed configuration.
     */
    private function parse_options($options) {
        $defaults = array(
            'source_dir' => WP_CONTENT_DIR,
            'base_path_name' => 'wp-content',
            'progress_interval' => 5000,
            'use_exclusions' => true,
            'validate_files' => true,
            'skip_unreadable' => true,
            'log_errors' => true,
            'use_unlimited_execution' => false,  // Enable unlimited execution time for enumeration
            'max_file_size' => 500 * 1024 * 1024  // 500MB file size limit for production safety
        );
        
        return array_merge($defaults, $options);
    }

    /**
     * Setup PHP environment for unlimited execution during enumeration
     */
    private function setup_unlimited_execution_environment() {
        $this->filesystem->log('Applying unlimited execution environment for enumeration...');
        
        // Set whether a client disconnect should abort script execution
        @ignore_user_abort(true);
        
        // Set maximum execution time - NO LIMIT during enumeration
        @set_time_limit(0);
        
        // Set maximum time in seconds a script is allowed to parse input data
        @ini_set('max_input_time', '-1');
        
        // Set maximum backtracking steps
        @ini_set('pcre.backtrack_limit', PHP_INT_MAX);
        
        // Set binary safe encoding
        if (@function_exists('mb_internal_encoding') && (@ini_get('mbstring.func_overload') & 2)) {
            @mb_internal_encoding('ISO-8859-1');
        }
        
        // Clean (erase) the output buffer and turn off output buffering
        if (@ob_get_length()) {
            @ob_end_clean();
        }
    }

    /**
     * Scan directory recursively and write files to CSV.
     *
     * @param resource $csv_handle File handle for CSV output.
     * @param array    $config     Configuration options.
     * @param array    $stats      Current statistics.
     * @return array Updated statistics.
     * @throws Exception If scanning fails.
     */
    private function scan_directory($csv_handle, $config, $stats) {
        try {
            // Create recursive iterator for directory scanning
            $directory_iterator = new RecursiveDirectoryIterator($config['source_dir']);
            
            // CRITICAL: Use CATCH_GET_CHILD flag to handle permission errors gracefully
            // This allows the iterator to continue processing when subdirectories are inaccessible
            $iterator = new RecursiveIteratorIterator(
                $directory_iterator,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (Exception $e) {
            $this->filesystem->log('Failed to create directory iterator: ' . $e->getMessage());
            throw new Exception('Cannot scan directory: ' . $e->getMessage());
        }
        
        foreach ($iterator as $file) {
            try {
                $result = $this->process_file($file, $config);
                
                if ($result['excluded']) {
                    $stats['files_excluded']++;
                    if ($result['excluded_by_size']) {
                        $stats['files_excluded_by_size']++;
                    }
                    continue;
                }
                
                if ($result['error']) {
                    $stats['errors']++;
                    if ($config['log_errors']) {
                        $this->filesystem->log('File processing error: ' . $result['error']);
                    }
                    continue;
                }
                
                // Write valid file to CSV
                fputcsv($csv_handle, $result['csv_data'], ',', '"', '\\');
                $stats['files_found']++;
                $stats['total_size'] += $result['size'];
                
                // Progress logging
                if ($stats['files_found'] % $config['progress_interval'] === 0) {
                    $this->log_progress($stats, $config);
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                if ($config['log_errors']) {
                    $this->filesystem->log('File iteration error: ' . $e->getMessage());
                }
                // Continue with next file instead of failing completely
                continue;
            }
        }
        
        return $stats;
    }

    /**
     * Process a single file and prepare CSV data.
     *
     * @param SplFileInfo $file   File object from iterator.
     * @param array       $config Configuration options.
     * @return array Processing result.
     */
    private function process_file($file, $config) {
        $result = array(
            'excluded' => false,
            'excluded_by_size' => false,  // Track if excluded due to size limit
            'error' => null,
            'csv_data' => null,
            'size' => 0
        );
        
        // Skip non-files
        if (!$file->isFile()) {
            $result['excluded'] = true;
            return $result;
        }
        
        $real_path = $file->getRealPath();
        if ($real_path === false) {
            $result['error'] = 'Cannot resolve real path for: ' . $file->getPathname();
            return $result;
        }
        
        // Check exclusions using optimized helper method
        if ($config['use_exclusions'] && Custom_Migrator_Helper::is_file_excluded($real_path)) {
            $result['excluded'] = true;
            return $result;
        }
        
        // Get file statistics
        $file_size = filesize($real_path);
        $file_mtime = filemtime($real_path);
        
        if ($file_size === false || $file_mtime === false) {
            $result['error'] = 'Cannot get file stats: ' . basename($real_path);
            return $result;
        }
        
        // PRODUCTION SAFETY: Exclude files larger than configured limit to prevent memory/timeout issues
        // This protects both regular and fallback exports from handling massive files
        if ($file_size > $config['max_file_size']) {
            // Log large file exclusion for monitoring
            $size_limit_mb = round($config['max_file_size'] / (1024 * 1024));
            $this->filesystem->log('Excluding large file: ' . basename($real_path) . ' (' . $this->format_bytes($file_size) . ') - exceeds ' . $size_limit_mb . 'MB limit');
            $result['excluded'] = true;
            $result['excluded_by_size'] = true;
            return $result;
        }
        
        // Validate file accessibility
        if ($config['validate_files']) {
            if (!is_readable($real_path)) {
                if ($config['skip_unreadable']) {
                    $result['excluded'] = true;
                    return $result;
                } else {
                    $result['error'] = 'File not readable: ' . basename($real_path);
                    return $result;
                }
            }
        }
        
        // Calculate relative path
        $relative_path = $this->calculate_relative_path($real_path, $config);
        
        // Prepare CSV data: [absolute_path, relative_path, size, mtime]
        $result['csv_data'] = array($real_path, $relative_path, $file_size, $file_mtime);
        $result['size'] = $file_size;
        
        return $result;
    }

    /**
     * Calculate relative path from real path based on configuration.
     *
     * @param string $real_path The absolute file path.
     * @param array  $config    Configuration options.
     * @return string The relative path for the archive.
     */
    private function calculate_relative_path($real_path, $config) {
        $source_dir = rtrim($config['source_dir'], DIRECTORY_SEPARATOR);
        $base_name = $config['base_path_name'];
        
        // Normalize paths for comparison (resolve symlinks, handle different prefixes)
        $real_path_normalized = realpath($real_path);
        $source_dir_normalized = realpath($source_dir);
        
        // Use normalized paths if available, fallback to original
        $path_to_process = $real_path_normalized ?: $real_path;
        $source_to_match = $source_dir_normalized ?: $source_dir;
        
        // Check if the real path starts with the source directory
        if (strpos($path_to_process, $source_to_match) === 0) {
            // Perfect match - use normalized paths
            $relative_part = substr($path_to_process, strlen($source_to_match) + 1);
        } else {
            // Fallback: try to find common suffix pattern
            // This handles cases where paths have different prefixes (e.g., /bitnami vs /opt/bitnami)
            $source_suffix = basename($source_to_match);
            $source_pos = strpos($path_to_process, '/' . $source_suffix . '/');
            
            if ($source_pos !== false) {
                // Found the source directory name in the path
                $relative_part = substr($path_to_process, $source_pos + strlen($source_suffix) + 2);
            } else {
                // Last resort: use the original method
                $relative_part = substr($real_path, strlen($source_dir) + 1);
            }
        }
        
        $final_path = $base_name . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative_part);
        
        return $final_path;
    }

    /**
     * Log progress during enumeration.
     *
     * @param array $stats  Current statistics.
     * @param array $config Configuration options.
     */
    private function log_progress($stats, $config) {
        $elapsed = microtime(true) - $stats['start_time'];
        $rate = $stats['files_found'] / max($elapsed, 0.1);
        
        $size_exclusions = $stats['files_excluded_by_size'] > 0 ? 
            sprintf(', %d large files excluded', $stats['files_excluded_by_size']) : '';
            
        $this->filesystem->log(sprintf(
            'Enumeration progress: %d files found, %d excluded%s, %s total size (%.1f files/sec)',
            $stats['files_found'],
            $stats['files_excluded'],
            $size_exclusions,
            $this->format_bytes($stats['total_size']),
            $rate
        ));
    }

    /**
     * Log final enumeration results.
     *
     * @param array $stats Final statistics.
     */
    private function log_enumeration_results($stats) {
        // Enhanced final logging with large file exclusion details
        $size_exclusion_details = '';
        if ($stats['files_excluded_by_size'] > 0) {
            $size_limit_mb = round(500, 0); // Default 500MB limit for logging
            $size_exclusion_details = sprintf(' (including %d files >%dMB)', 
                $stats['files_excluded_by_size'], $size_limit_mb);
        }
        
        $this->filesystem->log(sprintf(
            'File enumeration completed: %d files found, %d excluded%s, %d errors, %s total size in %.2f seconds',
            $stats['files_found'],
            $stats['files_excluded'],
            $size_exclusion_details,
            $stats['errors'],
            $this->format_bytes($stats['total_size']),
            $stats['elapsed_time']
        ));
    }

    /**
     * Format bytes for display.
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted size string.
     */
    private function format_bytes($bytes) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $factor = floor((strlen($bytes) - 1) / 3);
        $factor = min($factor, count($units) - 1);
        
        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Count lines in a CSV file efficiently.
     *
     * @param string $csv_file Path to CSV file.
     * @return int Number of lines in the file.
     */
    public static function count_csv_lines($csv_file) {
        if (!file_exists($csv_file) || !is_readable($csv_file)) {
            return 0;
        }
        
        $line_count = 0;
        $handle = fopen($csv_file, 'r');
        
        if ($handle) {
            while (fgets($handle) !== false) {
                $line_count++;
            }
            fclose($handle);
        }
        
        return $line_count;
    }

    /**
     * Validate CSV file format and integrity.
     *
     * @param string $csv_file Path to CSV file.
     * @return array Validation results.
     */
    public function validate_csv($csv_file) {
        $validation = array(
            'valid' => false,
            'line_count' => 0,
            'errors' => array(),
            'sample_data' => array()
        );
        
        if (!file_exists($csv_file)) {
            $validation['errors'][] = 'CSV file does not exist';
            return $validation;
        }
        
        $handle = fopen($csv_file, 'r');
        if (!$handle) {
            $validation['errors'][] = 'Cannot open CSV file for reading';
            return $validation;
        }
        
        $line_num = 0;
        $valid_lines = 0;
        
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false && $line_num < 1000) { // Sample first 1000 lines
            $line_num++;
            
            if (count($data) >= 4) {
                $valid_lines++;
                
                // Store sample data from first few lines
                if (count($validation['sample_data']) < 5) {
                    $validation['sample_data'][] = array(
                        'path' => basename($data[0]),
                        'relative' => $data[1],
                        'size' => (int)$data[2],
                        'mtime' => (int)$data[3]
                    );
                }
            }
        }
        
        fclose($handle);
        
        $validation['line_count'] = self::count_csv_lines($csv_file);
        $validation['valid'] = $valid_lines > 0 && ($valid_lines / max($line_num, 1)) > 0.95;
        
        if (!$validation['valid']) {
            $validation['errors'][] = sprintf(
                'CSV format validation failed: %d valid lines out of %d sampled',
                $valid_lines,
                $line_num
            );
        }
        
        return $validation;
    }
} 