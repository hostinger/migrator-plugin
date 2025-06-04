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
     * The admin handler.
     *
     * @var Custom_Migrator_Admin
     */
    private $admin;

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
        $this->admin = new Custom_Migrator_Admin();
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
        
        // Add custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
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
        add_action( 'wp_ajax_cm_process_export_step', array( $this, 'process_export_step' ) );
        add_action( 'wp_ajax_cm_force_continue', array( $this, 'handle_force_continue' ) );
        add_action( 'wp_ajax_cm_run_export_now', array( $this, 'handle_run_export_now' ) );
        add_action( 'wp_ajax_cm_upload_to_s3', array( $this, 'handle_upload_to_s3' ) );
        add_action( 'wp_ajax_cm_check_s3_status', array( $this, 'handle_check_s3_status' ) );
        add_action( 'wp_ajax_cm_debug_status', array( $this, 'handle_debug_status' ) );
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
        add_action( 'cm_process_export_step_fallback', array( $this, 'process_export_step_fallback' ) );
        
        // Enhanced monitoring: Schedule frequent checks (every 1 minute for faster detection)
        if (!wp_next_scheduled('cm_monitor_export')) {
            wp_schedule_event(time(), 'custom_migrator_1min', 'cm_monitor_export');
        }
    }

    /**
     * Monitor export progress and detect stuck processes.
     * Enhanced to detect failed resume attempts on paused exports.
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
        $time_diff = $current_time - $modified_time;
        
        // Check for stuck exports with optimized timeouts
        $stuck_statuses = ['starting', 'exporting', 'resuming'];
        if (in_array($status, $stuck_statuses) && $time_diff > 120) { // 2 minutes timeout
            $this->filesystem->log("Export appears stuck in '$status' state for $time_diff seconds. Attempting recovery.");
            
            // Try to restart the export
            $this->force_export_restart();
            return;
        }
        
        // ENHANCED: Check for failed resume on paused exports
        if ($status === 'paused') {
            $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
            
            if (file_exists($resume_info_file)) {
                $resume_data = json_decode(file_get_contents($resume_info_file), true);
                $last_update = isset($resume_data['last_update']) ? $resume_data['last_update'] : $modified_time;
                $pause_age = $current_time - $last_update;
                
                // If paused for more than 30 seconds without resume, force resume (reduced from 60s)
                if ($pause_age > 30) {
                    $files_processed = isset($resume_data['files_processed']) ? $resume_data['files_processed'] : 0;
                    $this->filesystem->log("Paused export detected ($files_processed files processed, {$pause_age}s since last update). Forcing resume.");
                    
                    // Enhanced resume: Multiple attempts with different methods
                    $this->force_resume_paused_export();
                    return;
                }
            }
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
     * Handle direct run export request (simple automation support).
     *
     * @return void
     */
    public function handle_run_export_now() {
        // Check if this is a background request
        $is_background = isset($_REQUEST['background_mode']) && $_REQUEST['background_mode'] === '1';
        
        if (!$is_background) {
            // For foreground requests, use standard WordPress security
            if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
                wp_send_json_error( array( 'message' => 'Security check failed' ) );
            }
        }
        // Background requests are triggered by authenticated requests, so they don't need additional auth
        
        $this->filesystem->log('Processing export request (background: ' . ($is_background ? 'yes' : 'no') . ')');
        
        // Set proper execution environment for background processing
        if ($is_background) {
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }
        }
        
        // Start the export process directly
        $this->run_export();
        
        if ($is_background) {
            // For background requests, don't send JSON response
            exit();
        } else {
            wp_send_json_success( array( 'message' => 'Export completed' ) );
        }
    }

    /**
     * Handle the AJAX request to start export process.
     *
     * @return void
     */
    public function handle_start_export() {
        // Security check - more lenient for automation
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

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

        // Check current export status
        $status_file = $this->filesystem->get_status_file_path();
        $current_status = '';
        if (file_exists($status_file)) {
            $current_status = trim(file_get_contents($status_file));
        }
        
        // Check if there are existing files and we should resume instead of restart
        $file_paths = $this->filesystem->get_export_file_paths();
        $should_resume = false;
        
        // Resume if export is paused or there are incomplete files
        if ($current_status === 'paused' || (file_exists($file_paths['hstgr']) && filesize($file_paths['hstgr']) > 0 && $current_status !== 'done')) {
            $should_resume = true;
            $this->filesystem->log('Export needs to be resumed - status: ' . $current_status);
        }
        
        if ($should_resume) {
            // Resume via immediate HTTP request - no cron dependency
            $this->filesystem->log('Initiating immediate background resume for paused/incomplete export');
            $this->schedule_immediate_background_resume();
        } else {
            // Clean up any existing export and start fresh
            $this->cleanup_existing_export();
            
            // Important: Delete old filenames to force regeneration with new secure names
            delete_option('custom_migrator_filenames');

            // Update export status and immediately start background processing
            $this->filesystem->write_status( 'starting' );
            $this->filesystem->log('Export started, initiating immediate background processing');
            
            // Use immediate HTTP request instead of cron scheduling
            $this->schedule_immediate_background_start();
        }

        $wp_content_size = $this->filesystem->get_directory_size( WP_CONTENT_DIR );
        wp_send_json_success(array(
            'message' => $should_resume ? 'Resuming paused export immediately' : 'Export started and processing immediately in background',
            'estimated_size' => $this->filesystem->format_file_size($wp_content_size),
            'automation_ready' => true,
            'background_processing' => true,
            'processing_method' => 'immediate_http_request',
            'current_status' => $current_status,
            'monitor_endpoint' => admin_url('admin-ajax.php?action=cm_check_status')
        ));
    }

    /**
     * Schedule immediate HTTP request for background processing (simple).
     *
     * @param array $params Export parameters.
     * @return void
     */
    private function schedule_immediate_http_request($params) {
        $ajax_url = admin_url('admin-ajax.php');
        
        $request_params = array(
            'action' => 'cm_run_export_now',
            'background_mode' => '1'
        );
        
        $request_url = add_query_arg($request_params, $ajax_url);
        
        // Method 1: Immediate HTTP request
        $this->non_blocking_request($request_url);
        
        // Method 2: Schedule backup requests
        wp_schedule_single_event(time() + 1, 'cm_run_export');
        wp_schedule_single_event(time() + 3, 'cm_run_export');
        
        $this->filesystem->log('Simple HTTP request scheduling initiated (no secret keys)');
    }

    /**
     * Schedule immediate background start (no secret keys needed).
     *
     * @return void
     */
    private function schedule_immediate_background_start() {
        $ajax_url = admin_url('admin-ajax.php');
        
        $request_params = array(
            'action' => 'cm_run_export_now',
            'background_mode' => '1'
        );
        
        $request_url = add_query_arg($request_params, $ajax_url);
        
        // Method 1: Immediate HTTP request
        $this->non_blocking_request($request_url);
        
        // Method 2: Backup cron events
        wp_schedule_single_event(time() + 1, 'cm_run_export');
        wp_schedule_single_event(time() + 3, 'cm_run_export');
        
        // Method 3: Trigger cron execution
        $this->trigger_cron_execution();
        
        $this->filesystem->log('Simple background start initiated (no secret keys required)');
    }

    /**
     * Schedule immediate background resume (no secret keys needed).
     *
     * @return void
     */
    private function schedule_immediate_background_resume() {
        $ajax_url = admin_url('admin-ajax.php');
        
        $request_params = array(
            'action' => 'cm_run_export_now',
            'background_mode' => '1'
        );
        
        $request_url = add_query_arg($request_params, $ajax_url);
        
        // Method 1: Immediate HTTP request
        $this->non_blocking_request($request_url);
        
        // Method 2: Backup cron events
        wp_schedule_single_event(time() + 1, 'cm_run_export');
        wp_schedule_single_event(time() + 3, 'cm_run_export');
        
        // Method 3: Trigger cron execution
        $this->trigger_cron_execution();
        
        $this->filesystem->log('Simple background resume initiated (no secret keys required)');
    }

    /**
     * Spawn a cURL request as ultimate fallback.
     *
     * @param string $url The URL to request.
     * @return void
     */
    private function spawn_curl_request($url) {
        // Only use cURL if available and on Unix-like systems
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            return;
        }
        
        // Check if we're on a Unix-like system
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }
        
        // Escape the URL for shell execution
        $escaped_url = escapeshellarg($url);
        
        // Create a cURL command that runs in background
        $curl_cmd = "curl -s -m 5 --connect-timeout 2 " . $escaped_url . " > /dev/null 2>&1 &";
        
        // Execute the command in background
        @exec($curl_cmd);
        
        $this->filesystem->log('Spawned background cURL request as ultimate fallback');
    }

    /**
     * Test if background HTTP requests are working.
     *
     * @return bool Whether HTTP requests appear to be working.
     */
    private function test_background_http() {
        $test_url = admin_url('admin-ajax.php?action=cm_debug_status&test=1');
        
        $args = array(
            'timeout'   => 2,
            'blocking'  => true, // Blocking for test
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        );
        
        $response = wp_remote_get($test_url, $args);
        
        if (is_wp_error($response)) {
            $this->filesystem->log('HTTP test failed: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $this->filesystem->log('HTTP test result: response code ' . $response_code);
        
        return $response_code === 200;
    }

    /**
     * Process export step by step (simple automation).
     *
     * @param array $params Export parameters.
     * @return array Updated parameters.
     */
    public function process_export_step($params = array()) {
        // Get params from request if not provided
        if (empty($params)) {
            $params = stripslashes_deep(array_merge($_GET, $_POST));
        }

        // Detect execution context
        $is_cron = defined('DOING_CRON') && DOING_CRON;
        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
        $is_background = $is_cron || !$is_ajax;
        
        // Simple security check for non-background requests
        if (!$is_background && !current_user_can('manage_options')) {
            $this->filesystem->log('Security check failed - user lacks permissions');
            return $params;
        }

        $current_step = isset($params['step']) ? $params['step'] : 'unknown';
        $this->filesystem->log('Processing export step: ' . $current_step . ' (background: ' . ($is_background ? 'yes' : 'no') . ')');
        
        // Save current step for tracking
        $step_file = $this->filesystem->get_export_dir() . '/export-step.txt';
        file_put_contents($step_file, $current_step);
        
        $this->setup_execution_environment();

        try {
            switch ($params['step']) {
                case 'init':
                    $params = $this->export_init($params);
                    break;
                case 'content':
                    $params = $this->export_content($params);
                    break;
                case 'database':
                    $params = $this->export_database($params);
                    break;
                case 'metadata':
                    $params = $this->export_metadata($params);
                    break;
                case 'finalize':
                    $params = $this->export_finalize($params);
                    break;
                default:
                    $params['completed'] = true;
                    break;
            }

            // Continue to next step if not completed
            if (!isset($params['completed']) || !$params['completed']) {
                $this->continue_export($params);
            } else {
                // Step completed, continue to next step if not done
                if ($params['step'] !== 'finalize') {
                    $this->filesystem->log('Step ' . $params['step'] . ' completed, scheduling next step');
                    $params['completed'] = false; // Reset for next step
                    $this->continue_export($params);
                }
            }

        } catch (Exception $e) {
            $this->filesystem->write_status('error: ' . $e->getMessage());
            $this->filesystem->log('Export step failed: ' . $e->getMessage());
        }

        return $params;
    }

    /**
     * Continue export process with true background processing (no cron dependency).
     *
     * @param array $params Export parameters.
     * @return void
     */
    private function continue_export($params) {
        $this->filesystem->log("Starting immediate background continuation for step: " . $params['step']);
        
        // Method 1: Immediate HTTP request to self (most reliable)
        $this->schedule_immediate_http_request($params);
        
        // Method 2: Schedule immediate processing
        wp_schedule_single_event(time() + 1, 'cm_run_export');
        
        // Method 3: Force immediate cron execution
        $this->trigger_cron_execution();
        
        $this->filesystem->log("Background processing initiated for step: " . $params['step']);
    }

    /**
     * Initialize export process.
     *
     * @param array $params Export parameters.
     * @return array Updated parameters.
     */
    private function export_init($params) {
        $this->filesystem->log('Starting export initialization');
        $this->filesystem->write_status('initializing');

        // Initialize export environment
        $exporter = new Custom_Migrator_Exporter();
        $exporter->prepare_export();

        $params['step'] = 'content';
        $params['completed'] = true;
        
        $this->filesystem->log('Export initialization complete, proceeding to content step');
        return $params;
    }

    /**
     * Export wp-content files.
     *
     * @param array $params Export parameters.
     * @return array Updated parameters.
     */
    private function export_content($params) {
        $this->filesystem->log('Starting wp-content export');
        $this->filesystem->write_status('exporting');

        $exporter = new Custom_Migrator_Exporter();
        $result = $exporter->export_content_only();

        if ($result === 'paused') {
            // Content export is paused, stay on content step and let resume logic handle it
            $this->filesystem->log('wp-content export paused, staying on content step');
            $params['step'] = 'content'; // Stay on same step
            $params['completed'] = false;
            return $params;
        }

        if (!$result) {
            throw new Exception('Content export failed');
        }

        $params['step'] = 'database';
        $params['completed'] = false;
        
        $this->filesystem->log('wp-content export complete');
        return $params;
    }

    /**
     * Export database.
     *
     * @param array $params Export parameters.
     * @return array Updated parameters.
     */
    private function export_database($params) {
        $this->filesystem->log('Starting database export');
        $this->filesystem->write_status('exporting_database');

        $exporter = new Custom_Migrator_Exporter();
        $result = $exporter->export_database_only();

        if (!$result) {
            throw new Exception('Database export failed');
        }

        $params['step'] = 'metadata';
        $params['completed'] = false;
        
        $this->filesystem->log('Database export complete');
        return $params;
    }

    /**
     * Generate metadata files.
     *
     * @param array $params Export parameters.
     * @return array Updated parameters.
     */
    private function export_metadata($params) {
        $this->filesystem->log('Starting metadata generation');
        $this->filesystem->write_status('generating_metadata');

        $exporter = new Custom_Migrator_Exporter();
        $result = $exporter->generate_metadata_only();

        if (!$result) {
            throw new Exception('Metadata generation failed');
        }

        $params['step'] = 'finalize';
        $params['completed'] = false;
        
        $this->filesystem->log('Metadata generation complete');
        return $params;
    }

    /**
     * Finalize export process.
     *
     * @param array $params Export parameters.
     * @return array Updated parameters.
     */
    private function export_finalize($params) {
        $this->filesystem->log('Finalizing export');
        $this->filesystem->write_status('finalizing');

        // Verify all files exist
        $file_paths = $this->filesystem->get_export_file_paths();
        foreach ($file_paths as $type => $path) {
            if (!file_exists($path)) {
                throw new Exception("Missing export file: $type");
            }
        }

        $this->filesystem->write_status('done');
        $this->filesystem->log('Export completed successfully');

        $params['completed'] = true;
        return $params;
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
        
        // Enhanced stuck detection for all processing statuses
        $processing_statuses = ['starting', 'initializing', 'exporting', 'exporting_database', 'generating_metadata', 'finalizing', 'resuming'];
        if (in_array($status, $processing_statuses)) {
            // Optimized timeouts for 10-second processing windows
            if ($time_diff > 60) { // 1 minute timeout for starting/initializing
                $this->filesystem->log("Export appears stuck in '$status' state for $time_diff seconds");
                
                // Try to restart if really stuck
                if ($time_diff > 120) { 
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
            // Try to provide more detailed progress info with user-friendly messages
            $log_file = $this->filesystem->get_log_file_path();
            $recent_log = '';
            
            if (file_exists($log_file)) {
                $log_content = file_get_contents($log_file);
                $log_lines = explode("\n", $log_content);
                $recent_log = implode("\n", array_slice($log_lines, -3)); // Get last 3 lines
            }
            
            // Provide user-friendly status messages
            $status_messages = array(
                'starting' => 'Starting export process...',
                'initializing' => 'Initializing export environment...',
                'exporting' => 'Exporting wp-content files...',
                'exporting_database' => 'Exporting database...',
                'generating_metadata' => 'Generating metadata files...',
                'finalizing' => 'Finalizing export...',
                'resuming' => 'Resuming export process...'
            );
            
            $message = isset($status_messages[$status]) ? $status_messages[$status] : 'Processing: ' . $status;
            
            wp_send_json_success(array( 
                'status' => $status,
                'message' => $message,
                'recent_log' => $recent_log,
                'time_since_update' => $time_diff
            ));
        }
    }

    /**
     * Make a non-blocking HTTP request with multiple attempts for reliability.
     *
     * @param string $url The URL to request.
     * @return void
     */
    private function non_blocking_request($url) {
        // Method 1: Quick non-blocking request (0.5 second timeout)
        $args_quick = array(
            'timeout'   => 0.5,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'redirection' => 0
        );
        
        wp_remote_get($url, $args_quick);
        
        // Method 2: Slightly longer timeout (1 second) as backup
        $args_backup = array(
            'timeout'   => 1,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'redirection' => 0
        );
        
        // Small delay then send backup request
        usleep(100000); // 0.1 seconds
        wp_remote_get($url, $args_backup);
        
        $this->filesystem->log('Sent multiple HTTP requests for reliability');
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

    /**
     * Fallback method to process export steps via cron if HTTP requests fail.
     *
     * @param array $params Export parameters.
     * @return void
     */
    public function process_export_step_fallback($params) {
        $this->filesystem->log('Using fallback method to process step: ' . (isset($params['step']) ? $params['step'] : 'unknown'));
        
        // Check current export status first
        $status_file = $this->filesystem->get_status_file_path();
        $current_status = '';
        if (file_exists($status_file)) {
            $current_status = trim(file_get_contents($status_file));
        }
        
        // If export is paused, we need to resume via run_export, not step processing
        if ($current_status === 'paused') {
            $this->filesystem->log('Export is paused, scheduling resume via run_export instead of step processing');
            wp_schedule_single_event(time() + 2, 'cm_run_export');
            $this->trigger_cron_execution();
            return;
        }
        
        // Check if the step is still needed by examining the actual files
        $file_paths = $this->filesystem->get_export_file_paths();
        $current_step = isset($params['step']) ? $params['step'] : '';
        
        $step_needed = true;
        
        switch ($current_step) {
            case 'database':
                // Check if database file already exists and is complete
                if ((file_exists($file_paths['sql']) || file_exists($file_paths['sql'] . '.gz'))) {
                    $step_needed = false;
                    $this->filesystem->log('Database file already exists, skipping database fallback');
                }
                break;
                
            case 'metadata':
                // Check if metadata file already exists and is complete
                if (file_exists($file_paths['metadata'])) {
                    $step_needed = false;
                    $this->filesystem->log('Metadata file already exists, skipping metadata fallback');
                }
                break;
                
            case 'finalize':
                // Check if export is already done
                if ($current_status === 'done') {
                    $step_needed = false;
                    $this->filesystem->log('Export already completed, skipping finalize fallback');
                }
                break;
                
            case 'content':
                // For content step, only skip if export is actually done or content is truly complete
                // Don't skip just because .hstgr file exists - it might be incomplete (paused export)
                if ($current_status === 'done') {
                    $step_needed = false;
                    $this->filesystem->log('Export is done, skipping content fallback');
                } else {
                    $this->filesystem->log('Content step needed - export status: ' . $current_status);
                }
                break;
                
            case 'init':
                // Only skip init if we're past initialization
                if (in_array($current_status, ['exporting', 'exporting_database', 'generating_metadata', 'finalizing', 'done'])) {
                    $step_needed = false;
                    $this->filesystem->log('Already past initialization, skipping init fallback');
                }
                break;
        }
        
        if (!$step_needed) {
            $this->filesystem->log('Step ' . $current_step . ' not needed, skipping fallback');
            return;
        }
        
        // Mark this as an auto-continue request to bypass security checks
        $params['auto_continue'] = true;
        $params['is_fallback'] = true;
        
        // Process the step using the normal method
        $this->filesystem->log('Processing fallback for step: ' . $current_step);
        $this->process_export_step($params);
    }

    /**
     * Resume export from where it left off based on step tracking.
     *
     * @return void
     */
    public function resume_export_from_current_state() {
        $this->filesystem->log('Attempting to resume export from current state');
        
        // Check step tracking file first
        $step_file = $this->filesystem->get_export_dir() . '/export-step.txt';
        $next_step = 'init';
        
        if (file_exists($step_file)) {
            $tracked_step = trim(file_get_contents($step_file));
            if (!empty($tracked_step)) {
                $next_step = $tracked_step;
                $this->filesystem->log('Found tracked step: ' . $tracked_step);
            }
        }
        
        // Also check file existence to determine step (fallback)
        $file_paths = $this->filesystem->get_export_file_paths();
        
        if (file_exists($file_paths['hstgr']) && filesize($file_paths['hstgr']) > 0) {
            $this->filesystem->log('Found .hstgr file, considering database step');
            if ($next_step === 'init' || $next_step === 'content') {
                $next_step = 'database';
            }
        }
        if ((file_exists($file_paths['sql']) || file_exists($file_paths['sql'] . '.gz')) && filesize($file_paths['hstgr']) > 0) {
            $this->filesystem->log('Found database file, considering metadata step');
            if (in_array($next_step, ['init', 'content', 'database'])) {
                $next_step = 'metadata';
            }
        }
        if (file_exists($file_paths['metadata']) && filesize($file_paths['metadata']) > 0) {
            $this->filesystem->log('Found metadata file, considering finalize step');
            if (in_array($next_step, ['init', 'content', 'database', 'metadata'])) {
                $next_step = 'finalize';
            }
        }
        
        // Create resume parameters
        $params = array(
            'step' => $next_step,
            'completed' => false,
            'is_resume' => true,
            'start_time' => time()
        );
        
        $this->filesystem->log('Resuming export from step: ' . $next_step);
        $this->process_export_step($params);
    }

    /**
     * Handle force continue export request.
     *
     * @return void
     */
    public function handle_force_continue() {
        // Security check
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'custom_migrator_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        $this->filesystem->log('Force continue requested by user');
        
        // Clear any pending scheduled events that might be stuck
        wp_clear_scheduled_hook('cm_process_export_step_fallback');
        
        // Resume from current state
        $this->resume_export_from_current_state();
        
        wp_send_json_success(array(
            'message' => 'Export force-continued successfully'
        ));
    }

    /**
     * Resume export from where it left off via background processing.
     *
     * @return void
     */
    private function schedule_background_resume() {
        $this->filesystem->log('Scheduling background resume');
        
        // Check step tracking file first
        $step_file = $this->filesystem->get_export_dir() . '/export-step.txt';
        $next_step = 'init';
        
        if (file_exists($step_file)) {
            $tracked_step = trim(file_get_contents($step_file));
            if (!empty($tracked_step)) {
                $next_step = $tracked_step;
                $this->filesystem->log('Found tracked step: ' . $tracked_step);
            }
        }
        
        // Also check file existence to determine step (fallback)
        $file_paths = $this->filesystem->get_export_file_paths();
        
        if (file_exists($file_paths['hstgr']) && filesize($file_paths['hstgr']) > 0) {
            $this->filesystem->log('Found .hstgr file, considering database step');
            if ($next_step === 'init' || $next_step === 'content') {
                $next_step = 'database';
            }
        }
        if ((file_exists($file_paths['sql']) || file_exists($file_paths['sql'] . '.gz')) && filesize($file_paths['hstgr']) > 0) {
            $this->filesystem->log('Found database file, considering metadata step');
            if (in_array($next_step, ['init', 'content', 'database'])) {
                $next_step = 'metadata';
            }
        }
        if (file_exists($file_paths['metadata']) && filesize($file_paths['metadata']) > 0) {
            $this->filesystem->log('Found metadata file, considering finalize step');
            if (in_array($next_step, ['init', 'content', 'database', 'metadata'])) {
                $next_step = 'finalize';
            }
        }
        
        // Create resume parameters
        $params = array(
            'step' => $next_step,
            'completed' => false,
            'is_resume' => true,
            'start_time' => time()
        );
        
        $this->filesystem->log('Resuming export from step: ' . $next_step);
        $this->process_export_step($params);
    }

    /**
     * Handle debug status request.
     *
     * @return void
     */
    public function handle_debug_status() {
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
        
        // Enhanced stuck detection for all processing statuses
        $processing_statuses = ['starting', 'initializing', 'exporting', 'exporting_database', 'generating_metadata', 'finalizing', 'resuming'];
        if (in_array($status, $processing_statuses)) {
            // Optimized timeouts for 10-second processing windows
            if ($time_diff > 60) { // 1 minute timeout for starting/initializing
                $this->filesystem->log("Export appears stuck in '$status' state for $time_diff seconds");
                
                // Try to restart if really stuck
                if ($time_diff > 120) { 
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
            // Try to provide more detailed progress info with user-friendly messages
            $log_file = $this->filesystem->get_log_file_path();
            $recent_log = '';
            
            if (file_exists($log_file)) {
                $log_content = file_get_contents($log_file);
                $log_lines = explode("\n", $log_content);
                $recent_log = implode("\n", array_slice($log_lines, -3)); // Get last 3 lines
            }
            
            // Provide user-friendly status messages
            $status_messages = array(
                'starting' => 'Starting export process...',
                'initializing' => 'Initializing export environment...',
                'exporting' => 'Exporting wp-content files...',
                'exporting_database' => 'Exporting database...',
                'generating_metadata' => 'Generating metadata files...',
                'finalizing' => 'Finalizing export...',
                'resuming' => 'Resuming export process...'
            );
            
            $message = isset($status_messages[$status]) ? $status_messages[$status] : 'Processing: ' . $status;
            
            wp_send_json_success(array( 
                'status' => $status,
                'message' => $message,
                'recent_log' => $recent_log,
                'time_since_update' => $time_diff
            ));
        }
    }

    /**
     * Force resume a paused export with multiple fallback methods.
     *
     * @return void
     */
    private function force_resume_paused_export() {
        // Method 1: Multiple HTTP requests (more aggressive)
        $ajax_url = admin_url('admin-ajax.php');
        $request_params = array(
            'action' => 'cm_run_export_now',
            'background_mode' => '1'
        );
        
        for ($i = 0; $i < 3; $i++) {
            wp_remote_post($ajax_url, array(
                'method'    => 'POST',
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => false,
                'headers'   => array('Connection' => 'close'),
                'body'      => $request_params,
            ));
        }
        
        // Method 2: Staggered cron scheduling (multiple attempts)
        wp_schedule_single_event(time() + 5, 'cm_run_export');   // 5 seconds
        wp_schedule_single_event(time() + 15, 'cm_run_export');  // 15 seconds  
        wp_schedule_single_event(time() + 30, 'cm_run_export');  // 30 seconds
        wp_schedule_single_event(time() + 60, 'cm_run_export');  // 1 minute
        wp_schedule_single_event(time() + 120, 'cm_run_export'); // 2 minutes
        
        // Method 3: Enhanced cron triggering
        $this->trigger_cron_execution();
        
        $this->filesystem->log('Forced resume for paused export with multiple fallback methods');
    }

    /**
     * Add custom cron schedules.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public function add_custom_cron_schedules($schedules) {
        $schedules['custom_migrator_1min'] = array(
            'interval' => 1 * 60, // 1 minute
            'display' => __('Every 1 minute', 'custom-migrator')
        );
        $schedules['custom_migrator_5min'] = array(
            'interval' => 5 * 60, // 5 minutes (kept for compatibility)
            'display' => __('Every 5 minutes', 'custom-migrator')
        );
        return $schedules;
    }
}