<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @package CustomMigrator
 */

/**
 * The core plugin class.
 */
class Custom_Migrator_Core {

    /**
     * The single instance of the class.
     *
     * @var Custom_Migrator_Core
     */
    private static $instance = null;

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public function init() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_ajax_hooks();
        $this->define_cron_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @return void
     */
    private function load_dependencies() {
        // Admin class
        require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'admin/class-admin.php';
        
        // Include classes
        require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'includes/class-exporter.php';
        require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'includes/class-database.php';
        require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'includes/class-filesystem.php';
        require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'includes/class-metadata.php';
        require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'includes/class-s3-uploader.php';
        
        // Initialize the filesystem class for use throughout the plugin
        $this->filesystem = new Custom_Migrator_Filesystem();
    }

    /**
     * Register all of the hooks related to the admin area functionality.
     *
     * @return void
     */
    private function define_admin_hooks() {
        $admin = new Custom_Migrator_Admin();
        add_action( 'admin_menu', array( $admin, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_scripts' ) );
        add_action( 'admin_init', array( $admin, 'handle_form_submission' ) );
        
        // Add settings link to plugins page
        add_filter( 'plugin_action_links_' . plugin_basename( CUSTOM_MIGRATOR_PLUGIN_DIR . 'custom-migrator.php' ), 
                  array( $this, 'add_settings_link' ) );
    }

    /**
     * Register all of the hooks related to AJAX functionality.
     *
     * @return void
     */
    private function define_ajax_hooks() {
        // AJAX handlers
        add_action( 'wp_ajax_cm_start_export', array( $this, 'handle_start_export' ) );
        add_action( 'wp_ajax_cm_check_status', array( $this, 'handle_check_status' ) );
        add_action( 'wp_ajax_cm_run_export_now', array( $this, 'handle_run_export_now' ) );
        add_action( 'wp_ajax_cm_upload_to_s3', array( $this, 'handle_upload_to_s3' ) );
        add_action( 'wp_ajax_cm_check_s3_status', array( $this, 'handle_check_s3_status' ) );
        add_action( 'cm_run_export', array( $this, 'run_export' ) );
    }

    /**
     * Define cron-related hooks with improved monitoring.
     *
     * @return void
     */
    private function define_cron_hooks() {
        add_action( 'cm_run_export', array( $this, 'run_export' ) );
        add_action( 'cm_monitor_export', array( $this, 'monitor_export_progress' ) );
        add_action( 'cm_run_export_direct', array( $this, 'run_export_directly' ) );
        
        // Schedule monitoring if not already scheduled
        if (!wp_next_scheduled('cm_monitor_export')) {
            wp_schedule_event(time(), 'hourly', 'cm_monitor_export');
        }
    }

    /**
     * Monitor export progress and detect stuck processes.
     *
     * @return void
     */
    public function monitor_export_progress() {
        $status_file = $this->filesystem->get_status_file_path();
        
        if (!file_exists($status_file)) {
            return; // No export in progress
        }
        
        $status = trim(file_get_contents($status_file));
        $modified_time = filemtime($status_file);
        $current_time = time();
        
        // Check for stuck exports
        $stuck_statuses = ['starting', 'exporting', 'resuming'];
        $time_diff = $current_time - $modified_time;
        
        if (in_array($status, $stuck_statuses) && $time_diff > 600) { // 10 minutes
            $this->filesystem->log("Export appears stuck in '$status' state for $time_diff seconds. Attempting recovery.");
            
            // Try to restart the export
            $this->force_export_restart();
        }
        
        // Clean up old monitoring if export is done
        if ($status === 'done' || strpos($status, 'error:') === 0) {
            wp_clear_scheduled_hook('cm_monitor_export');
        }
    }

    /**
     * Force restart a stuck export.
     *
     * @return void
     */
    private function force_export_restart() {
        // Clear any existing scheduled events
        wp_clear_scheduled_hook('cm_run_export');
        
        // Update status to indicate restart
        $this->filesystem->write_status('restarting');
        $this->filesystem->log('Forcing export restart due to stuck process');
        
        // Schedule immediate restart
        wp_schedule_single_event(time() + 5, 'cm_run_export');
        
        // Force cron to run
        $this->trigger_cron_execution();
    }

    /**
     * Improved cron triggering with multiple fallback methods.
     *
     * @return void
     */
    private function trigger_cron_execution() {
        // Method 1: Standard WordPress spawn_cron
        if (function_exists('spawn_cron')) {
            spawn_cron();
            $this->filesystem->log('Triggered cron via spawn_cron()');
        }
        
        // Method 2: Direct HTTP request to wp-cron.php
        $cron_url = site_url('wp-cron.php');
        $this->non_blocking_request($cron_url . '?doing_wp_cron=1');
        $this->filesystem->log('Triggered cron via HTTP request');
        
        // Method 3: If cron is disabled, try direct execution
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $this->filesystem->log('WP_CRON disabled, scheduling direct execution');
            wp_schedule_single_event(time() + 2, 'cm_run_export_direct');
        }
    }

    /**
     * Direct export execution for environments with disabled cron.
     *
     * @return void
     */
    public function run_export_directly() {
        $this->filesystem->log('Running export directly (cron disabled)');
        $this->run_export();
    }

    /**
     * Handle the AJAX request to check S3 upload status.
     *
     * @return void
     */
    public function handle_check_s3_status() {
        // Security check
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        $s3_status_file = WP_CONTENT_DIR . '/hostinger-migration-archives/s3-upload-status.txt';

        if ( ! file_exists( $s3_status_file ) ) {
            wp_send_json_error( array( 'status' => 'not_started' ) );
        }

        $status = trim( file_get_contents( $s3_status_file ) );
        
        if ( $status === 'done' ) {
            wp_send_json_success( array(
                'status' => 'done',
                'message' => 'S3 upload completed successfully'
            ) );
        } elseif (strpos($status, 'error:') === 0) {
            // Return error status
            wp_send_json_error(array(
                'status' => 'error',
                'message' => substr($status, 6) // Remove 'error:' prefix
            ));
        } else {
            // Get current file being uploaded if it's in the format "uploading_filetype"
            $current_file = '';
            if (strpos($status, 'uploading_') === 0) {
                $current_file = substr($status, 10); // Remove 'uploading_' prefix
            }
            
            wp_send_json_success( array( 
                'status' => $status,
                'current_file' => $current_file,
                'message' => 'Upload in progress' . ($current_file ? ': ' . $current_file : '')
            ));
        }
    }

    /**
     * Handle S3 upload request.
     *
     * @return void
     */
    public function handle_upload_to_s3() {
        // Try to increase PHP execution time limit
        @set_time_limit(0);  // Try to remove the time limit
        @ini_set('max_execution_time', 3600); // Try to set to 1 hour
    
        // Security check
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }
        
        // Check if export is done
        $status_file = $this->filesystem->get_status_file_path();
        if ( ! file_exists( $status_file ) || trim( file_get_contents( $status_file ) ) !== 'done' ) {
            wp_send_json_error( array( 'message' => 'Export is not complete. Please wait for export to finish before uploading to S3.' ) );
            return;
        }
        
        // Get pre-signed URLs
        $s3_urls = array(
            'hstgr'    => isset( $_POST['s3_url_hstgr'] ) ? sanitize_text_field( $_POST['s3_url_hstgr'] ) : '',
            'sql'      => isset( $_POST['s3_url_sql'] ) ? sanitize_text_field( $_POST['s3_url_sql'] ) : '',
            'metadata' => isset( $_POST['s3_url_metadata'] ) ? sanitize_text_field( $_POST['s3_url_metadata'] ) : '',
        );
        
        // Check if at least one URL is provided
        if ( empty( $s3_urls['hstgr'] ) && empty( $s3_urls['sql'] ) && empty( $s3_urls['metadata'] ) ) {
            wp_send_json_error( array( 'message' => 'Please provide at least one pre-signed URL for upload.' ) );
            return;
        }
        
        // Initialize S3 uploader
        $s3_uploader = new Custom_Migrator_S3_Uploader();
        
        // Upload files to S3
        $result = $s3_uploader->upload_to_s3( $s3_urls );
        
        if ( $result['success'] ) {
            wp_send_json_success( array( 
                'message' => 'Files uploaded successfully to S3.',
                'details' => $result['messages'],
                'uploaded_files' => $result['uploaded']
            ) );
        } else {
            wp_send_json_error( array( 
                'message' => 'Error uploading files to S3. Please check the export log for details.',
                'details' => $result['messages']
            ) );
        }
    }

    /**
     * Handle direct run export request (bypassing cron).
     *
     * @return void
     */
    public function handle_run_export_now() {
        // Security check
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        // Start the export process directly
        $this->run_export();
        wp_send_json_success( array( 'message' => 'Export completed' ) );
    }

    /**
     * Handle the AJAX request to start export.
     *
     * @return void
     */
    public function handle_start_export() {
        // Security check
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        // Clean up any existing export
        $this->cleanup_existing_export();

        $base_dir = $this->filesystem->get_export_dir();
        
        // Create export directory if it doesn't exist
        if ( ! file_exists( $base_dir ) ) {
            try {
                $this->filesystem->create_export_dir();
            } catch ( Exception $e ) {
                wp_send_json_error( array( 'message' => $e->getMessage() ) );
                return;
            }
        }

        // Important: Delete old filenames to force regeneration with new secure names
        delete_option('custom_migrator_filenames');

        // Update export status
        $this->filesystem->write_status( 'starting' );

        // Clear any existing scheduled events
        wp_clear_scheduled_hook('cm_run_export');

        // Schedule for immediate execution and ensure WP Cron runs
        wp_schedule_single_event(time(), 'cm_run_export');
        
        // Start monitoring
        wp_clear_scheduled_hook('cm_monitor_export');
        wp_schedule_event(time() + 300, 'hourly', 'cm_monitor_export'); // Start monitoring after 5 minutes
        
        $this->trigger_cron_execution();

        $wp_content_size = $this->filesystem->get_directory_size( WP_CONTENT_DIR );
        wp_send_json_success(array(
            'message' => 'Export started successfully',
            'estimated_size' => $this->filesystem->format_file_size($wp_content_size)
        ));
    }

    /**
     * Clean up any existing export files and processes.
     *
     * @return void
     */
    private function cleanup_existing_export() {
        // Clear any pending scheduled events
        wp_clear_scheduled_hook('cm_run_export');
        wp_clear_scheduled_hook('cm_monitor_export');
        
        // Get export directory and files
        $export_dir = $this->filesystem->get_export_dir();
        
        // If directory doesn't exist, nothing to clean
        if (!file_exists($export_dir)) {
            return;
        }
        
        // Clean up status file
        $status_file = $this->filesystem->get_status_file_path();
        if (file_exists($status_file)) {
            @unlink($status_file);
        }
        
        // Clean up resume info file if it exists
        $resume_info_file = $export_dir . '/export-resume-info.json';
        if (file_exists($resume_info_file)) {
            @unlink($resume_info_file);
        }
        
        // Clean up export files if they exist
        $file_paths = $this->filesystem->get_export_file_paths();
        foreach ($file_paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        
        // Log cleanup
        $this->filesystem->log("Cleaned up previous export files");
    }

    /**
     * Handle the AJAX request to check export status.
     *
     * @return void
     */
    public function handle_check_status() {
        // Security check
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        $status_file = $this->filesystem->get_status_file_path();
        $file_urls = $this->filesystem->get_export_file_urls();

        if ( ! file_exists( $status_file ) ) {
            wp_send_json_error( array( 'status' => 'not_started' ) );
        }

        $status = trim( file_get_contents( $status_file ) );
        $modified_time = filemtime($status_file);
        $current_time = time();
        $time_diff = $current_time - $modified_time;
        
        // Enhanced stuck detection
        if (in_array($status, ['starting', 'exporting', 'resuming'])) {
            if ($time_diff > 300) { // 5 minutes for starting
                $this->filesystem->log("Export appears stuck in '$status' state for $time_diff seconds");
                
                // Try to restart if really stuck
                if ($time_diff > 600) { // 10 minutes
                    $this->force_export_restart();
                }
                
                wp_send_json_success(array(
                    'status' => $status . '_stuck',
                    'message' => "Export may be stuck (no activity for " . round($time_diff/60, 1) . " minutes). Attempting recovery...",
                    'time_since_update' => $time_diff
                ));
                return;
            }
        }
        
        if ( $status === 'done' ) {
            // Get file information
            $file_paths = $this->filesystem->get_export_file_paths();
            $file_info = array();
            
            foreach ( $file_paths as $type => $path ) {
                if ( file_exists( $path ) ) {
                    $file_info[$type] = array(
                        'size' => $this->filesystem->format_file_size( filesize( $path ) ),
                        'raw_size' => filesize( $path ),
                        'modified' => date( 'Y-m-d H:i:s', filemtime( $path ) ),
                    );
                }
            }
            
            // Clean up monitoring when done
            wp_clear_scheduled_hook('cm_monitor_export');
            
            wp_send_json_success( array(
                'status'          => 'done',
                'hstgr_download'  => $file_urls['hstgr'],
                'sql_download'    => $file_urls['sql'],
                'metadata'        => $file_urls['metadata'],
                'log'             => $file_urls['log'],
                'file_info'       => $file_info,
            ) );
        } elseif ($status === 'paused') {
            // Get resume information 
            $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
            $resume_info = file_exists($resume_info_file) ? json_decode(file_get_contents($resume_info_file), true) : array();
            
            $files_processed = isset($resume_info['files_processed']) ? (int)$resume_info['files_processed'] : 0;
            $bytes_processed = isset($resume_info['bytes_processed']) ? (int)$resume_info['bytes_processed'] : 0;
            
            // Get log for more details
            $log_file = $this->filesystem->get_log_file_path();
            $recent_log = '';
            
            if (file_exists($log_file)) {
                $log_content = file_get_contents($log_file);
                $log_lines = explode("\n", $log_content);
                $recent_log = implode("\n", array_slice($log_lines, -5)); // Get last 5 lines
            }
            
            // Ensure resume is scheduled
            if (!wp_next_scheduled('cm_run_export')) {
                wp_schedule_single_event(time() + 5, 'cm_run_export');
                $this->trigger_cron_execution();
            }
            
            wp_send_json_success(array( 
                'status' => 'paused_resuming',
                'message' => sprintf(
                    'Export is paused after processing %d files (%.2f MB) and will resume automatically', 
                    $files_processed, 
                    $bytes_processed / (1024 * 1024)
                ),
                'recent_log' => $recent_log,
                'progress' => array(
                    'files_processed' => $files_processed,
                    'bytes_processed' => $this->filesystem->format_file_size($bytes_processed),
                    'last_update' => isset($resume_info['last_update']) ? date('Y-m-d H:i:s', $resume_info['last_update']) : ''
                )
            ));
        } elseif (strpos($status, 'error:') === 0) {
            // Return error status
            wp_clear_scheduled_hook('cm_monitor_export');
            wp_send_json_error(array(
                'status' => 'error',
                'message' => substr($status, 6) // Remove 'error:' prefix
            ));
        } else {
            // Try to provide more detailed progress info
            $log_file = $this->filesystem->get_log_file_path();
            $recent_log = '';
            
            if (file_exists($log_file)) {
                $log_content = file_get_contents($log_file);
                $log_lines = explode("\n", $log_content);
                $recent_log = implode("\n", array_slice($log_lines, -3)); // Get last 3 lines
            }
            
            wp_send_json_success(array( 
                'status' => $status,
                'recent_log' => $recent_log,
                'time_since_update' => $time_diff
            ));
        }
    }

    /**
     * Make a non-blocking HTTP request to a URL.
     * 
     * @param string $url The URL to request
     * @return void
     */
    private function non_blocking_request($url) {
        // Non-blocking request
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        );
        
        wp_remote_get($url, $args);
    }

    /**
     * Run the export process.
     *
     * @return void
     */
    public function run_export() {
        // Detect execution environment
        $is_background = $this->is_background_execution();
        $this->filesystem->log('Export execution started (background: ' . ($is_background ? 'yes' : 'no') . ')');
        
        // Set execution environment
        $this->setup_execution_environment();

        try {
            // Check if we are resuming a paused export
            $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
            $is_resume = file_exists($resume_info_file);
            
            if ($is_resume) {
                $this->filesystem->write_status('resuming');
                $this->filesystem->log('Resuming export process');
            } else {
                // Update status to confirm we're running
                $this->filesystem->write_status('exporting');
                $this->filesystem->log('Export process is running');
            }
            
            // Add heartbeat to prevent stuck detection
            add_action('shutdown', array($this, 'update_export_heartbeat'));
            
            // Run the export
            $exporter = new Custom_Migrator_Exporter();
            $result = $exporter->export();
            
            if (!$result) {
                $this->filesystem->write_status('error: Export failed to complete successfully');
                $this->filesystem->log('Export failed to complete successfully');
            }
        } catch (Exception $e) {
            // Log the error and update the status
            $error_message = 'Export failed with error: ' . $e->getMessage();
            $this->filesystem->write_status('error: ' . $error_message);
            $this->filesystem->log($error_message);
            
            // Add additional debugging information if possible
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->filesystem->log('Error trace: ' . $e->getTraceAsString());
            }
        } catch (Error $e) {
            // Handle PHP 7+ Fatal Errors
            $error_message = 'Export failed with fatal error: ' . $e->getMessage();
            $this->filesystem->write_status('error: ' . $error_message);
            $this->filesystem->log($error_message);
            $this->filesystem->log('Error trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Update heartbeat to show export is still active.
     *
     * @return void
     */
    public function update_export_heartbeat() {
        $status_file = $this->filesystem->get_status_file_path();
        if (file_exists($status_file)) {
            $status = trim(file_get_contents($status_file));
            if (in_array($status, ['exporting', 'resuming'])) {
                // Touch the file to update modification time
                touch($status_file);
            }
        }
    }

    /**
     * Detect if we're running in background.
     *
     * @return bool Whether we're running in background.
     */
    private function is_background_execution() {
        return (
            (defined('DOING_CRON') && DOING_CRON) ||
            (defined('DOING_AJAX') && DOING_AJAX) ||
            !isset($_SERVER['HTTP_HOST'])
        );
    }

    /**
     * Setup execution environment for background processing.
     *
     * @return void
     */
    private function setup_execution_environment() {
        // Set time limit
        if (function_exists('set_time_limit') && !ini_get('safe_mode')) {
            @set_time_limit(0);
        }
        
        // Increase memory limit
        $current_limit = $this->get_memory_limit_bytes();
        $target_limit = max($current_limit, 1024 * 1024 * 1024); // At least 1GB
        @ini_set('memory_limit', $this->format_bytes($target_limit));
        
        // Disable output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Enable garbage collection
        if (function_exists('gc_enable')) {
            gc_enable();
        }
        
        // Log environment setup
        $this->filesystem->log('Environment: Memory=' . ini_get('memory_limit') . ', Time=' . ini_get('max_execution_time') . ', Safe Mode=' . (ini_get('safe_mode') ? 'On' : 'Off'));
    }

    /**
     * Attempt to increase memory limit for the export process.
     *
     * @return void
     */
    private function increase_memory_limit() {
        $current_limit = ini_get('memory_limit');
        $current_limit_int = $this->return_bytes($current_limit);
        
        // Try to increase to 512M if current limit is lower
        if ($current_limit_int < 536870912) {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
     * Get memory limit in bytes.
     *
     * @return int Memory limit in bytes.
     */
    private function get_memory_limit_bytes() {
        $limit = ini_get('memory_limit');
        if ($limit == -1) return PHP_INT_MAX;
        
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
     * Convert memory limit string to bytes.
     *
     * @param string $size_str Memory limit string like '256M'.
     * @return int Size in bytes.
     */
    private function return_bytes($size_str) {
        $size_str = trim($size_str);
        $unit = strtolower(substr($size_str, -1));
        $size = (int)$size_str;
        
        switch ($unit) {
            case 'g':
                $size *= 1024;
                // Fall through
            case 'm':
                $size *= 1024;
                // Fall through
            case 'k':
                $size *= 1024;
        }
        
        return $size;
    }

    /**
     * Format bytes to human readable format.
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted string.
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . 'K';
        return $bytes . 'B';
    }

    /**
     * Add settings link to the plugins page.
     *
     * @param array $links Plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . CUSTOM_MIGRATOR_ADMIN_URL . '">' . __('Export Site', 'custom-migrator') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Returns the singleton instance of this class.
     *
     * @return Custom_Migrator_Core The singleton instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}