<?php
/**
 * The helper class for common migration functions.
 *
 * @package CustomMigrator
 */

/**
 * Helper class.
 */
class Custom_Migrator_Helper {

    /**
     * Default exclusion paths.
     *
     * @var array
     */
    private static $exclusion_paths = null;

    /**
     * Get exclusion paths for export.
     *
     * @return array Array of paths to exclude from export.
     */
    public static function get_exclusion_paths() {
        if (self::$exclusion_paths === null) {
            self::$exclusion_paths = self::build_exclusion_paths();
        }
        
        return self::$exclusion_paths;
    }

    /**
     * Build the default exclusion paths.
     *
     * @return array Array of exclusion paths.
     */
    private static function build_exclusion_paths() {
        $wp_content_dir = WP_CONTENT_DIR;

        $paths = [
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
            
            // Note: Removed blanket mu-plugins exclusion - now only exclude specific hosting plugins
            
            // Backup files and archives (prevent backup-of-backup scenarios)
            // Note: Individual backup files are detected by extension only
            
            // Cache directories
            $wp_content_dir . '/cache',
            $wp_content_dir . '/wp-cache',
            $wp_content_dir . '/et_cache',
            $wp_content_dir . '/w3tc',
            $wp_content_dir . '/wp-rocket-config',
            $wp_content_dir . '/w3tc-config', // W3 Total Cache config (following AI1WM)
            
            // Hosting-specific mu-plugins (following AI1WM exclusions)
            $wp_content_dir . '/mu-plugins/endurance-page-cache.php',
            $wp_content_dir . '/mu-plugins/endurance-php-edge.php', 
            $wp_content_dir . '/mu-plugins/endurance-browser-cache.php',
            $wp_content_dir . '/mu-plugins/gd-system-plugin.php', // GoDaddy
            $wp_content_dir . '/mu-plugins/wp-stack-cache.php', // WP Engine
            $wp_content_dir . '/mu-plugins/wpcomsh-loader.php', // WordPress.com
            $wp_content_dir . '/mu-plugins/wpcomsh', // WordPress.com helper
            $wp_content_dir . '/mu-plugins/mu-plugin.php', // WP Engine system plugin
            $wp_content_dir . '/mu-plugins/wpe-wp-sign-on-plugin.php', // WP Engine
            $wp_content_dir . '/mu-plugins/wpengine-security-auditor.php', // WP Engine
            $wp_content_dir . '/mu-plugins/aaa-wp-cerber.php', // WP Cerber Security
            $wp_content_dir . '/mu-plugins/sqlite-database-integration', // SQLite integration
            $wp_content_dir . '/mu-plugins/0-sqlite.php', // SQLite zero config
            
            // Plugin-specific cache and generated files (following AI1WM)
            // NOTE: Removed elementor/css exclusion - these files are essential for website styling
            $wp_content_dir . '/uploads/civicrm', // CiviCRM uploads
            
            // Temporary directories
            $wp_content_dir . '/temp',
            $wp_content_dir . '/tmp'
        ];

        // Allow filtering of exclusion paths
        return apply_filters('custom_migrator_export_exclusion_paths', $paths);
    }

    /**
     * Check if a file path should be excluded from export.
     *
     * @param string $file_path The file path to check.
     * @return bool True if the file should be excluded, false otherwise.
     */
    public static function is_file_excluded($file_path) {
        // Check path-based exclusions first
        $exclusion_paths = self::get_exclusion_paths();
        
        foreach ($exclusion_paths as $excluded_path) {
            // Ensure we're matching directories, not just filename prefixes
            // Add directory separator to ensure exact directory match
            if (strpos($file_path, $excluded_path . '/') === 0 || $file_path === $excluded_path) {
                return true;
            }
        }
        
        // Check backup file patterns
        if (self::is_backup_file($file_path)) {
            return true;
        }
        
        // Check cache file extensions (following AI1WM approach)
        if (self::is_cache_file($file_path)) {
            return true;
        }
        
        return false;
    }

    /**
     * Add custom exclusion paths.
     *
     * @param array $additional_paths Array of additional paths to exclude.
     * @return void
     */
    public static function add_exclusion_paths($additional_paths) {
        if (!is_array($additional_paths)) {
            return;
        }
        
        $current_paths = self::get_exclusion_paths();
        self::$exclusion_paths = array_merge($current_paths, $additional_paths);
    }

    /**
     * Reset exclusion paths to defaults.
     *
     * @return void
     */
    public static function reset_exclusion_paths() {
        self::$exclusion_paths = null;
    }

    /**
     * Get exclusion paths count.
     *
     * @return int Number of exclusion paths.
     */
    public static function get_exclusion_count() {
        return count(self::get_exclusion_paths());
    }

    /**
     * Check if exclusion paths contain a specific pattern.
     *
     * @param string $pattern Pattern to search for.
     * @return bool True if pattern is found in any exclusion path.
     */
    public static function has_exclusion_pattern($pattern) {
        $exclusion_paths = self::get_exclusion_paths();
        
        foreach ($exclusion_paths as $path) {
            if (strpos($path, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }



    /**
     * Check if a file appears to be a backup file based on extension only.
     * Based on All-in-One WP Migration's more conservative approach.
     *
     * @param string $file_path The file path.
     * @return bool True if file appears to be a backup.
     */
    public static function is_backup_file($file_path) {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        // Conservative backup file extensions (following AI1WM approach)
        // Note: Removed .sql as legitimate SQL files should be preserved
        $backup_extensions = array(
            'wpress',  // WordPress migration archives
            'bak', 'backup', 'old'  // Clear backup indicators
        );
        
        return in_array($extension, $backup_extensions);
    }

    /**
     * Check if a file appears to be a cache file based on extension.
     * Based on All-in-One WP Migration's cache exclusions.
     *
     * @param string $file_path The file path.
     * @return bool True if file appears to be a cache file.
     */
    public static function is_cache_file($file_path) {
        $filename = basename($file_path);
        
        // Cache file extensions (following AI1WM approach)
        if (substr($filename, -11) === '.less.cache') {
            return true; // LESS cache files
        }
        
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($extension === 'sqlite') {
            return true; // SQLite database files (often used for caching)
        }
        
        return false;
    }



    /**
     * Format bytes for human-readable display.
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted size string.
     */
    public static function format_bytes($bytes) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get unified binary block format for archive files.
     * 
     * This ensures all exporters use the same binary format for compatibility.
     *
     * @return array Binary block format configuration.
     */
    public static function get_binary_block_format() {
        return array(
            'pack' => 'a255VVa4112',  // filename(255), size(4), date(4), path(4112) = 4375 bytes total
            'unpack' => 'a255filename/Vsize/Vdate/a4112path',  // For unpack: named fields
            'size' => 4375,  // Total block size in bytes
            'fields' => array(
                'filename' => 255,  // Maximum filename length
                'size' => 4,        // File size (32-bit unsigned)
                'date' => 4,        // Modification time (32-bit unsigned)
                'path' => 4112      // Maximum path length
            )
        );
    }

    /**
     * Create a binary block for archive files.
     *
     * @param string $filename     File name (will be truncated to 254 chars if needed).
     * @param int    $file_size    File size in bytes.
     * @param int    $file_date    File modification time (Unix timestamp).
     * @param string $file_path    File path (will be truncated to 4111 chars if needed).
     * @return string Binary block data.
     * @throws Exception If block creation fails.
     */
    public static function create_binary_block($filename, $file_size, $file_date, $file_path) {
        $format = self::get_binary_block_format();
        
        // Truncate strings to fit the fixed-size fields
        if (strlen($filename) > 254) {
            $filename = substr($filename, 0, 254);
        }
        if (strlen($file_path) > 4111) {
            $file_path = substr($file_path, 0, 4111);
        }
        
        // Create binary block
        $block = pack($format['pack'], $filename, $file_size, $file_date, $file_path);
        
        // Verify block size is correct
        if (strlen($block) !== $format['size']) {
            throw new Exception(sprintf(
                'Binary block size mismatch for %s. Expected: %d, Got: %d',
                $filename,
                $format['size'],
                strlen($block)
            ));
        }
        
        return $block;
    }

    /**
     * Parse a binary block from archive files.
     *
     * @param string $block Binary block data.
     * @return array|false Parsed data array or false on failure.
     */
    public static function parse_binary_block($block) {
        $format = self::get_binary_block_format();
        
        if (strlen($block) !== $format['size']) {
            return false;
        }
        
        $data = @unpack($format['unpack'], $block);
        
        if ($data === false) {
            return false;
        }
        
        // Clean up null bytes from fixed-length strings
        $data['filename'] = rtrim($data['filename'], "\0");
        $data['path'] = rtrim($data['path'], "\0");
        
        return $data;
    }

    /**
     * Get the size of a binary block.
     *
     * @return int Block size in bytes.
     */
    public static function get_binary_block_size() {
        $format = self::get_binary_block_format();
        return $format['size'];
    }

    /**
     * Validate that all required export files exist.
     * Simple check to prevent marking export as complete when files are missing.
     * 
     * @param array $file_paths Array with keys: hstgr, sql, metadata
     * @return array Array of missing files (empty if all exist)
     */
    public static function validate_export_files($file_paths) {
        $missing = array();

        // Check archive file (.hstgr)
        if (!file_exists($file_paths['hstgr'])) {
            $missing[] = basename($file_paths['hstgr']);
        }

        // Check database file - it has a unique name and can be either .sql or .sql.gz
        $sql_file = $file_paths['sql'];
        $sql_gz_file = $sql_file . '.gz';
        
        if (!file_exists($sql_file) && !file_exists($sql_gz_file)) {
            // Show the actual filename that should exist
            $missing[] = basename($sql_file) . ' (or compressed .gz version)';
        }

        // Check metadata file (.json)
        if (!file_exists($file_paths['metadata'])) {
            $missing[] = basename($file_paths['metadata']);
        }

        return $missing;
    }
} 