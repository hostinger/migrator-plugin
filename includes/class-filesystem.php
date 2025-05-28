<?php
/**
 * The class responsible for filesystem operations.
 *
 * @package CustomMigrator
 */

/**
 * Filesystem class.
 */
class Custom_Migrator_Filesystem {

    /**
     * File extension for archive files.
     *
     * @var string
     */
    private $file_extension = 'hstgr';

    /**
     * Maximum log file size before rotation.
     *
     * @var int
     */
    private $max_log_size = 10485760; // 10MB max log size

    /**
     * Get the export directory path.
     *
     * @return string The export directory path.
     */
    public function get_export_dir() {
        // Use a dedicated directory in wp-content for better organization and security
        return WP_CONTENT_DIR . '/hostinger-migration-archives';
    }

    /**
     * Get the export directory URL.
     *
     * @return string The export directory URL.
     */
    public function get_export_url() {
        // Create URL from content directory URL
        $wp_content_url = content_url();
        return $wp_content_url . '/hostinger-migration-archives';
    }

    /**
     * Get the path to the status file.
     *
     * @return string The path to the status file.
     */
    public function get_status_file_path() {
        return $this->get_export_dir() . '/export-status.txt';
    }

    /**
     * Get the path to the log file.
     *
     * @return string The path to the log file.
     */
    public function get_log_file_path() {
        // Use secure filename format if available, otherwise default
        $filenames = get_option('custom_migrator_filenames');
        if ($filenames && isset($filenames['log'])) {
            return $this->get_export_dir() . '/' . $filenames['log'];
        }
        return $this->get_export_dir() . '/export-log.txt';
    }

    /**
     * Create the export directory.
     *
     * @throws Exception If the directory cannot be created.
     */
    public function create_export_dir() {
        $dir = $this->get_export_dir();
        
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                throw new Exception('Cannot create export directory: ' . $dir);
            }
            
            // Create an index.php file to prevent directory listing
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.");
            
            // Create an .htaccess file with necessary access for hosting providers
            // but still blocking public access
            $htaccess = "# Disable directory browsing\n" .
                       "Options -Indexes\n\n" .
                       "# Allow specific file types to be downloaded directly\n" .
                       "<FilesMatch \"\\.(hstgr|sql|sql\\.gz|json|log|txt)$\">\n" .
                       "  Order Allow,Deny\n" .
                       "  Allow from all\n" .
                       "</FilesMatch>\n\n" .
                       "# Allow access to status file\n" .
                       "<Files \"export-status.txt\">\n" .
                       "  Order Allow,Deny\n" .
                       "  Allow from all\n" .
                       "</Files>\n\n" .
                       "# Deny access to sensitive files\n" .
                       "<Files \"export-log.txt\">\n" .
                       "  Order Allow,Deny\n" .
                       "  Deny from all\n" .
                       "</Files>\n";
            
            file_put_contents($dir . '/.htaccess', $htaccess);
        }
    }

    /**
     * Write the export status to the status file with enhanced atomic operations.
     *
     * @param string $status The export status.
     * @return bool Whether the status was written successfully.
     */
    public function write_status($status) {
        $status_file = $this->get_status_file_path();
        $temp_file = $status_file . '.tmp';
        
        // Add timestamp and process info to status for better monitoring
        $status_data = array(
            'status' => $status,
            'timestamp' => time(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'pid' => function_exists('getmypid') ? getmypid() : 'unknown'
        );
        
        // Write to temp file first, then rename (atomic operation)
        if (file_put_contents($temp_file, $status) !== false) {
            if (@rename($temp_file, $status_file)) {
                $this->log("Export status: $status (Memory: " . $this->format_file_size($status_data['memory_usage']) . ")");
                return true;
            }
        }
        
        // Fallback to direct write if atomic operation fails
        if (file_put_contents($status_file, $status) !== false) {
            $this->log("Export status: $status");
            return true;
        }
        
        $this->log("Failed to write status: $status");
        return false;
    }

    /**
     * Write a message to the log file with enhanced formatting and rotation.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log($message) {
        $log_file = $this->get_log_file_path();
        
        // Rotate log if it gets too large
        if (file_exists($log_file) && filesize($log_file) > $this->max_log_size) {
            $this->rotate_log($log_file);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $memory_usage = $this->format_file_size(memory_get_usage(true));
        $log_entry = sprintf(
            "[%s] [MEM: %s] %s\n",
            $timestamp,
            $memory_usage,
            $message
        );
        
        // Use file locking to prevent corruption in concurrent access
        $fp = @fopen($log_file, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $log_entry);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        } else {
            // Fallback without locking if file handle fails
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Rotate log file when it gets too large.
     *
     * @param string $log_file Path to the log file.
     * @return void
     */
    private function rotate_log($log_file) {
        $backup_file = $log_file . '.old';
        
        // Remove old backup if it exists
        if (file_exists($backup_file)) {
            @unlink($backup_file);
        }
        
        // Move current log to backup
        if (@rename($log_file, $backup_file)) {
            $this->log("Log rotated - previous log saved as " . basename($backup_file));
        }
    }

    /**
     * Get the file size in a human-readable format.
     *
     * @param int $bytes The file size in bytes.
     * @return string The human-readable file size.
     */
    public function format_file_size($bytes) {
        if ($bytes <= 0) return '0 B';
        
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Check if a directory is writable with enhanced diagnostics.
     *
     * @param string $dir The directory path.
     * @return bool Whether the directory is writable.
     */
    public function is_writable($dir) {
        // Check if directory exists
        if (!file_exists($dir)) {
            // Try to create it
            if (!wp_mkdir_p($dir)) {
                return false;
            }
        }
        
        // Check basic writability
        if (!is_writable($dir)) {
            return false;
        }
        
        // Try creating a temporary file to really test writability
        $temp_file = $dir . '/cm_write_test_' . time() . '.tmp';
        $test_data = 'write test';
        
        $result = false;
        
        if (@file_put_contents($temp_file, $test_data) !== false) {
            // Verify we can read it back
            if (@file_get_contents($temp_file) === $test_data) {
                $result = true;
            }
            @unlink($temp_file);
        }
        
        return $result;
    }

    /**
     * Generate a secure, unique filename with randomization.
     * PHP 5.4+ compatible version with enhanced entropy.
     *
     * @param string $type File type (hstgr, sql, metadata).
     * @return string Filename.
     */
    public function generate_secure_filename($type) {
        // Generate high-entropy random string
        $random_string = $this->generate_random_string(16);
        
        // Add timestamp with microseconds
        $timestamp = microtime(true);
        $timestamp_str = str_replace('.', '', (string)$timestamp);
        
        // Add process ID if available
        $pid = function_exists('getmypid') ? getmypid() : mt_rand(1000, 9999);
        
        // Create a timestamp with microseconds
        $date = date('Ymd-His');
        
        // Build the filename based on type with no domain information
        switch ($type) {
            case 'hstgr':
                return "content_{$random_string}_{$timestamp_str}_{$pid}_{$date}.{$this->file_extension}";
            case 'sql':
                // Use .sql.gz if gzip is available, otherwise .sql
                $extension = function_exists('gzopen') ? 'sql.gz' : 'sql';
                return "db_{$random_string}_{$timestamp_str}_{$pid}_{$date}.{$extension}";
            case 'metadata':
                return "meta_{$random_string}_{$timestamp_str}_{$pid}_{$date}.json";
            case 'log':
                return "log_{$random_string}_{$timestamp_str}_{$pid}_{$date}.txt";
            default:
                return "export_{$random_string}_{$timestamp_str}_{$pid}_{$date}.{$type}";
        }
    }

    /**
     * Generate cryptographically secure random string.
     *
     * @param int $length Length of the random string.
     * @return string Random string.
     */
    private function generate_random_string($length = 16) {
        if (function_exists('random_bytes')) {
            // PHP 7.0+
            return bin2hex(random_bytes($length / 2));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            // PHP 5.4+ with OpenSSL
            $is_strong = false;
            $bytes = openssl_random_pseudo_bytes($length / 2, $is_strong);
            if ($is_strong) {
                return bin2hex($bytes);
            }
        }
        
        // Fallback to less secure method for older PHP
        $chars = '0123456789abcdef';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[mt_rand(0, 15)];
        }
        return $result;
    }

    /**
     * Get the full paths for export files.
     *
     * @return array Array of file paths.
     */
    public function get_export_file_paths() {
        $base_dir = $this->get_export_dir();
        
        // Store filenames in an option so they persist
        $filenames = get_option('custom_migrator_filenames');
        
        if (!$filenames) {
            $filenames = array(
                'hstgr'    => $this->generate_secure_filename('hstgr'),
                'sql'      => $this->generate_secure_filename('sql'),
                'metadata' => $this->generate_secure_filename('metadata'),
                'log'      => $this->generate_secure_filename('log'),
            );
            update_option('custom_migrator_filenames', $filenames);
        }
        
        return array(
            'hstgr'    => $base_dir . '/' . $filenames['hstgr'],
            'sql'      => $base_dir . '/' . $filenames['sql'],
            'metadata' => $base_dir . '/' . $filenames['metadata'],
            'log'      => $base_dir . '/' . $filenames['log'],
        );
    }

    /**
     * Get the URLs for export files.
     *
     * @return array Array of file URLs.
     */
    public function get_export_file_urls() {
        $base_url = $this->get_export_url();
        $filenames = get_option('custom_migrator_filenames');
        
        if (!$filenames) {
            // If filenames aren't saved, get the paths which will generate them
            $this->get_export_file_paths();
            $filenames = get_option('custom_migrator_filenames');
        }
        
        return array(
            'hstgr'    => $base_url . '/' . $filenames['hstgr'],
            'sql'      => $base_url . '/' . $filenames['sql'],
            'metadata' => $base_url . '/' . $filenames['metadata'],
            'log'      => $base_url . '/' . $filenames['log'],
        );
    }

    /**
     * Calculate directory size with exclusions support.
     *
     * @param string $dir Directory path.
     * @param array $exclusions Array of paths to exclude.
     * @return int Total size in bytes.
     */
    public function get_directory_size($dir, $exclusions = array()) {
        $size = 0;
        
        if (!is_dir($dir) || !is_readable($dir)) {
            return 0;
        }
        
        try {
            foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $item) {
                // Check exclusions
                $excluded = false;
                foreach ($exclusions as $exclusion) {
                    if (strpos($item, $exclusion) === 0) {
                        $excluded = true;
                        break;
                    }
                }
                
                if ($excluded) {
                    continue;
                }
                
                if (is_file($item)) {
                    $size += filesize($item);
                } elseif (is_dir($item)) {
                    $size += $this->get_directory_size($item, $exclusions);
                }
            }
        } catch (Exception $e) {
            $this->log('Error calculating directory size: ' . $e->getMessage());
        }
        
        return $size;
    }

    /**
     * Get system disk space information.
     *
     * @param string $dir Directory to check (defaults to export directory).
     * @return array Disk space information.
     */
    public function get_disk_space_info($dir = null) {
        if (!$dir) {
            $dir = $this->get_export_dir();
        }
        
        $info = array(
            'free' => 0,
            'total' => 0,
            'used' => 0,
            'free_formatted' => '0 B',
            'total_formatted' => '0 B',
            'used_formatted' => '0 B'
        );
        
        if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
            $free = disk_free_space($dir);
            $total = disk_total_space($dir);
            
            if ($free !== false && $total !== false) {
                $info['free'] = $free;
                $info['total'] = $total;
                $info['used'] = $total - $free;
                $info['free_formatted'] = $this->format_file_size($free);
                $info['total_formatted'] = $this->format_file_size($total);
                $info['used_formatted'] = $this->format_file_size($info['used']);
            }
        }
        
        return $info;
    }

    /**
     * Check if there's enough disk space for export.
     *
     * @param int $estimated_size Estimated size needed for export.
     * @return array Result array with sufficient flag and details.
     */
    public function check_disk_space($estimated_size) {
        $disk_info = $this->get_disk_space_info();
        
        // Add 20% buffer for safety
        $required_space = $estimated_size * 1.2;
        
        if ($disk_info['free'] < $required_space) {
            return array(
                'sufficient' => false,
                'available' => $disk_info['free'],
                'required' => $required_space,
                'message' => sprintf(
                    'Insufficient disk space. Required: %s, Available: %s',
                    $this->format_file_size($required_space),
                    $this->format_file_size($disk_info['free'])
                )
            );
        }
        
        return array(
            'sufficient' => true,
            'available' => $disk_info['free'],
            'required' => $required_space,
            'message' => 'Sufficient disk space available'
        );
    }
}