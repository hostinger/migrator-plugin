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
                throw new Exception('Cannot create export directory');
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
     * Write the export status to the status file.
     *
     * @param string $status The export status.
     */
    public function write_status($status) {
        $status_file = $this->get_status_file_path();
        file_put_contents($status_file, $status);
        $this->log("Export status: $status");
    }

    /**
     * Write a message to the log file.
     *
     * @param string $message The message to log.
     */
    public function log($message) {
        $log_file = $this->get_log_file_path();
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message\n";
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }

    /**
     * Get the file size in a human-readable format.
     *
     * @param int $bytes The file size in bytes.
     * @return string The human-readable file size.
     */
    public function format_file_size($bytes) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Check if a directory is writable.
     *
     * @param string $dir The directory path.
     * @return bool Whether the directory is writable.
     */
    public function is_writable($dir) {
        // Try creating a temporary file to really test writability
        $temp_file = $dir . '/cm_write_test_' . time() . '.tmp';
        $result = false;
        
        if (@file_put_contents($temp_file, 'test')) {
            @unlink($temp_file);
            $result = true;
        }
        
        return $result;
    }

    /**
     * Generate a secure, unique filename with randomization.
     * PHP 5.4+ compatible version.
     *
     * @param string $type File type (hstgr, sql, metadata).
     * @return string Filename.
     */
    public function generate_secure_filename($type) {
        // Generate a random string (16 characters) - PHP 5.4+ compatible
        if (function_exists('random_bytes')) {
            // PHP 7.0+
            $random_string = bin2hex(random_bytes(8));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            // PHP 5.4+ with OpenSSL
            $random_string = bin2hex(openssl_random_pseudo_bytes(8));
        } else {
            // Fallback for older PHP without proper CSPRNG
            $random_string = md5(uniqid(mt_rand(), true));
        }
        
        // Create a timestamp with microseconds
        $timestamp = microtime(true);
        $timestamp_str = str_replace('.', '', (string)$timestamp);
        
        // Add date stamp in a more complex format
        $date = date('Ymd-His');
        
        // Build the filename based on type with no domain information
        switch ($type) {
            case 'hstgr':
                return "content_{$random_string}_{$timestamp_str}_{$date}.{$this->file_extension}";
            case 'sql':
                // Use .sql.gz if gzip is available, otherwise .sql
                $extension = function_exists('gzopen') ? 'sql.gz' : 'sql';
                return "db_{$random_string}_{$timestamp_str}_{$date}.{$extension}";
            case 'metadata':
                return "meta_{$random_string}_{$timestamp_str}_{$date}.json";
            case 'log':
                return "log_{$random_string}_{$timestamp_str}_{$date}.txt";
            default:
                return "export_{$random_string}_{$timestamp_str}_{$date}.{$type}";
        }
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
     * Calculate directory size.
     *
     * @param string $dir Directory path.
     * @return int Total size in bytes.
     */
    public function get_directory_size($dir) {
        $size = 0;
        
        foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $item) {
            if (is_file($item)) {
                $size += filesize($item);
            } else {
                $size += $this->get_directory_size($item);
            }
        }
        
        return $size;
    }
}