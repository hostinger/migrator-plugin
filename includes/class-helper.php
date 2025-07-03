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
     * Compiled exclusion patterns for performance.
     *
     * @var array
     */
    private static $compiled_exclusions = null;

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
            $wp_content_dir . '/plugins/all-in-one-wp-migration',
            $wp_content_dir . '/plugins/updraftplus',
            
            // Note: Removed blanket mu-plugins exclusion - now only exclude specific hosting plugins
            
            // Backup files and archives (prevent backup-of-backup scenarios)
            // Note: Individual backup files are detected by extension only
            
            // Cache directories
            $wp_content_dir . '/cache',
            $wp_content_dir . '/wp-cache',
            $wp_content_dir . '/et_cache',
            $wp_content_dir . '/w3tc',
            
            // Note: Removed wp-rocket-config and w3tc-config exclusions to match AI1WM
            // Cache plugin configurations contain important user settings that should be migrated
            
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
            // NOTE: Keeping Elementor CSS - excluding it can cause serious styling issues
            // while auto-regeneration is not guaranteed. Better UX to preserve styling.
            $wp_content_dir . '/uploads/civicrm', // CiviCRM uploads (following AI1WM exclusion)
            
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
     * This is the main exclusion method - optimized for performance.
     *
     * @param string $file_path The file path to check.
     * @return bool True if the file should be excluded, false otherwise.
     */
    public static function is_file_excluded($file_path) {
        $compiled = self::get_compiled_exclusions();
        
        // Fast directory prefix matching (most common case)
        foreach ($compiled['directory_prefixes'] as $prefix) {
            if (strpos($file_path, $prefix) === 0) {
                return true;
            }
        }
        
        // Fast extension matching using single regex
        if (preg_match($compiled['extension_regex'], $file_path)) {
            return true;
        }
        
        // Fast cache file matching using single regex - test both full path and basename
        if (preg_match($compiled['cache_regex'], $file_path)) {
            return true;
        }

        // Also test just the basename for filename-specific patterns
        $basename = basename($file_path);
        if (preg_match($compiled['cache_regex'], $basename)) {
            return true;
        }

        return false;
    }

    /**
     * Check if entire directory should be excluded (for early directory exclusion).
     *
     * @param string $dir_path Directory path to check.
     * @return bool True if the entire directory should be excluded.
     */
    public static function is_directory_excluded($dir_path) {
        $compiled = self::get_compiled_exclusions();
        
        // Normalize directory path
        $normalized_path = rtrim($dir_path, '/') . '/';
        
        // Check if this directory matches any exclusion prefix
        foreach ($compiled['directory_prefixes'] as $prefix) {
            if (strpos($normalized_path, $prefix) === 0 || strpos($prefix, $normalized_path) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get compiled exclusion patterns for high-performance filtering.
     *
     * @return array Compiled exclusion data.
     */
    public static function get_compiled_exclusions() {
        if (self::$compiled_exclusions === null) {
            self::$compiled_exclusions = self::compile_exclusion_patterns();
        }
        
        return self::$compiled_exclusions;
    }

    /**
     * Compile exclusion patterns for maximum performance.
     *
     * @return array Compiled exclusion data.
     */
    private static function compile_exclusion_patterns() {
        $exclusion_paths = self::get_exclusion_paths();
        
        // Create directory prefixes with trailing slashes
        $directory_prefixes = array();
        foreach ($exclusion_paths as $path) {
            $directory_prefixes[] = rtrim($path, '/') . '/';
        }
        
        // Sort by length (longest first) for efficient matching
        usort($directory_prefixes, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        // Compile extension patterns into single regex
        $backup_extensions = array('wpress', 'bak', 'backup', 'old');
        $log_extensions = array('log'); // Log files
        $cache_patterns = array(
            '\.less\.cache$',  // LESS cache files
            '\.sqlite$',       // SQLite files
            'error_log$',      // Error log files
            'node_modules\/'   // Node.js modules directory
        );
        
        // Add UpdraftPlus and other backup plugin exclusions
        $updraftplus_patterns = array(
            // UpdraftPlus backup files: backup_YYYY-MM-DD-HHMM_sitename_12charhex-entity.zip
            'backup_[\-0-9]{15}_.*_[0-9a-f]{12}-(plugins|themes|uploads|mu-plugins|others|db)([0-9]+)?\.zip$',
            'backup_[\-0-9]{15}_.*_[0-9a-f]{12}-db([0-9]+)?\.gz$',
            'backup_[\-0-9]{15}_.*_[0-9a-f]{12}-db([0-9]+)?\.gz\.crypt$',
            // UpdraftPlus log files
            'log\.[0-9a-f]{12}\.txt$',
            // UpdraftPlus temporary files
            'updraftplus-.*\.tmp$',
            '\.zip\.tmp\.',
            'pclzip-[a-f0-9]+\.(?:tmp|gz)$',
            // Generic backup file patterns
            '.*backup.*\.zip$',
            '.*backup.*\.tar\.gz$',
            '.*backup.*\.sql$',
            '.*backup.*\.sql\.gz$',
            // Specific problematic files (will match basename)
            'uploads\.zip$',  // Remove ^ anchor - will match basename
            'plugins\.zip$',
            'themes\.zip$',
            'database\.sql$',
            'database\.sql\.gz$'
        );
        
        // Merge all patterns
        $all_cache_patterns = array_merge($cache_patterns, $updraftplus_patterns);
        
        // Create optimized regex patterns
        $extension_regex = '/\.(' . implode('|', array_map('preg_quote', array_merge($backup_extensions, $log_extensions))) . ')$/i';
        $cache_regex = '/(' . implode('|', $all_cache_patterns) . ')/i';
        
        return array(
            'directory_prefixes' => $directory_prefixes,
            'extension_regex' => $extension_regex,
            'cache_regex' => $cache_regex
        );
        }

    /**
     * Reset compiled exclusions cache.
     */
    public static function reset_compiled_exclusions() {
        self::$compiled_exclusions = null;
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
     * Encode a string for safe binary storage (handles international characters)
     *
     * @param  string $value The string to encode
     * @return string URL-encoded string safe for binary storage
     */
    public static function encode_for_binary($value) {
        return urlencode($value);
    }

    /**
     * Decode a string from binary storage (handles international characters)
     *
     * @param  string $value The URL-encoded string from binary storage
     * @return string Decoded original string
     */
    public static function decode_from_binary($value) {
        return urldecode(trim($value, "\0"));
    }

    /**
     * Internationalization-friendly version of basename()
     *
     * @param  string $path   File path
     * @param  string $suffix If the filename ends in suffix this will also be cut off
     * @return string
     */
    private static function safe_basename($path, $suffix = '') {
        return urldecode(basename(str_replace(array('%2F', '%5C'), '/', urlencode($path)), $suffix));
    }

    /**
     * Internationalization-friendly version of dirname()
     *
     * @param  string $path File path
     * @return string
     */
    private static function safe_dirname($path) {
        return urldecode(dirname(str_replace(array('%2F', '%5C'), '/', urlencode($path))));
    }

    /**
     * Create a binary block for archive files with safe international character handling.
     *
     * @param string $filename     File name.
     * @param int    $file_size    File size in bytes.
     * @param int    $file_date    File modification time (Unix timestamp).
     * @param string $file_path    Directory path.
     * @return string Binary block data.
     * @throws Exception If block creation fails.
     */
    public static function create_binary_block($filename, $file_size, $file_date, $file_path) {
        $format = self::get_binary_block_format();
        
        // Apply safe character handling to both filename and path using centralized methods
        // Note: filename is already just the filename, file_path is already the directory path
        $name = self::encode_for_binary($filename);
        $path = self::encode_for_binary($file_path);
        
        // Create binary block with safe character encoding
        $block = pack($format['pack'], $name, $file_size, $file_date, $path);
        
        return $block;
    }

    /**
     * Parse a binary block from archive files with safe character handling.
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
        
        // Clean up padding from fixed-length strings and decode using centralized methods
        $data['filename'] = self::decode_from_binary($data['filename']);
        $data['path'] = self::decode_from_binary($data['path']);
        
        // Construct full file path
        $data['filename'] = ($data['path'] === '.' ? $data['filename'] : $data['path'] . DIRECTORY_SEPARATOR . $data['filename']);
        
        // Set directory path
        $data['path'] = ($data['path'] === '.' ? '' : $data['path']);
        
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