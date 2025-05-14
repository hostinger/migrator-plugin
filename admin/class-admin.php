<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package CustomMigrator
 */

/**
 * The admin-specific functionality of the plugin.
 */
class Custom_Migrator_Admin {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * File extension for the migration archive.
     *
     * @var string
     */
    private $file_extension = 'hstgr';

    /**
     * Constructor for the admin class.
     */
    public function __construct() {
        $this->filesystem = new Custom_Migrator_Filesystem();
    }

    /**
     * Handle form submissions from the admin page.
     *
     * @return void
     */
    public function handle_form_submission() {
        // Check if we're on our plugin page and if the form was submitted
        if ( isset( $_POST['start_export'] ) && isset( $_POST['custom_migrator_nonce'] ) ) {
            // Verify nonce
            if ( ! wp_verify_nonce( $_POST['custom_migrator_nonce'], 'custom_migrator_action' ) ) {
                wp_die( 'Security check failed' );
            }
            
            // Check user permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'You do not have sufficient permissions to perform this action' );
            }
            
            // Initialize export process
            $base_dir = $this->filesystem->get_export_dir();
            
            // Create export directory if it doesn't exist
            if ( ! file_exists( $base_dir ) ) {
                try {
                    $this->filesystem->create_export_dir();
                } catch ( Exception $e ) {
                    wp_die( 'Error creating export directory: ' . $e->getMessage() );
                }
            }
            
            // Force regeneration of secure filenames
            delete_option('custom_migrator_filenames');
            
            // Update export status
            $this->filesystem->write_status( 'starting' );
            
            // Schedule the export to run in the background
            wp_schedule_single_event( time() + 1, 'cm_run_export' );
            
            // Redirect to the same page to prevent form resubmission
            wp_redirect( add_query_arg( 'export_started', '1', CUSTOM_MIGRATOR_ADMIN_URL ) );
            exit;
        }
        
        // Check if S3 upload form was submitted
        if ( isset( $_POST['upload_to_s3'] ) && isset( $_POST['custom_migrator_s3_nonce'] ) ) {
            // Verify nonce
            if ( ! wp_verify_nonce( $_POST['custom_migrator_s3_nonce'], 'custom_migrator_s3_action' ) ) {
                wp_die( 'Security check failed' );
            }
            
            // Check user permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'You do not have sufficient permissions to perform this action' );
            }
            
            // Get pre-signed URLs
            $s3_urls = array(
                'hstgr'    => sanitize_text_field( $_POST['s3_url_hstgr'] ),
                'sql'      => sanitize_text_field( $_POST['s3_url_sql'] ),
                'metadata' => sanitize_text_field( $_POST['s3_url_metadata'] ),
            );
            
            // Check if at least one URL is provided
            if ( empty( $s3_urls['hstgr'] ) && empty( $s3_urls['sql'] ) && empty( $s3_urls['metadata'] ) ) {
                wp_die( 'Please provide at least one pre-signed URL for upload.' );
            }
            
            // Initialize S3 uploader
            require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'includes/class-s3-uploader.php';
            $s3_uploader = new Custom_Migrator_S3_Uploader();
            
            // Upload files to S3
            $result = $s3_uploader->upload_to_s3( $s3_urls );
            
            // Redirect based on result
            if ( $result['success'] ) {
                wp_redirect( add_query_arg( 's3_upload', 'success', CUSTOM_MIGRATOR_ADMIN_URL ) );
            } else {
                wp_redirect( add_query_arg( 's3_upload', 'error', CUSTOM_MIGRATOR_ADMIN_URL ) );
            }
            exit;
        }
    }

    /**
     * Add plugin admin menu.
     */
    public function add_admin_menu() {
        add_menu_page(
            'Hostinger Migrator',
            'Hostinger Migrator',
            'manage_options',
            'custom-migrator',
            array( $this, 'display_admin_page' ),
            'dashicons-migrate',
            100
        );
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_scripts( $hook ) {
        if ( 'toplevel_page_custom-migrator' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'custom-migrator-admin',
            CUSTOM_MIGRATOR_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            CUSTOM_MIGRATOR_VERSION
        );

        wp_enqueue_script(
            'custom-migrator-admin',
            CUSTOM_MIGRATOR_PLUGIN_URL . 'admin/js/script.js',
            array( 'jquery' ),
            CUSTOM_MIGRATOR_VERSION,
            true
        );

        wp_localize_script(
            'custom-migrator-admin',
            'cm_ajax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'custom_migrator_nonce' ),
            )
        );
    }

    /**
     * Display the admin page.
     */
    public function display_admin_page() {
        $export_dir = $this->filesystem->get_export_dir();
        
        // Check if the export directory exists and is writable
        $dir_exists = file_exists( $export_dir );
        $is_writable = $dir_exists ? $this->filesystem->is_writable( $export_dir ) : true;
        
        // Check if WP_CONTENT_DIR is readable
        $content_dir_readable = is_readable( WP_CONTENT_DIR );
        
        // Check if there's an existing export (Check for ANY files, not requiring ALL)
        $has_export = false;
        $export_files = array();
        
        if ( $dir_exists ) {
            $file_paths = $this->filesystem->get_export_file_paths();
            $file_urls = $this->filesystem->get_export_file_urls();
            
            // Check each file individually and add to export_files if it exists
            if ( file_exists( $file_paths['hstgr'] ) ) {
                $has_export = true;
                $export_files['hstgr_file'] = array(
                    'name' => 'WordPress Content (.' . $this->file_extension . ')',
                    'url'  => $file_urls['hstgr'],
                    'size' => $this->filesystem->format_file_size( filesize( $file_paths['hstgr'] ) ),
                );
            }
            
            if ( file_exists( $file_paths['sql'] ) ) {
                $has_export = true;
                $export_files['sql_file'] = array(
                    'name' => 'Database (Compressed SQL .gz)',
                    'url'  => $file_urls['sql'],
                    'size' => $this->filesystem->format_file_size( filesize( $file_paths['sql'] ) ),
                );
            }
            
            if ( file_exists( $file_paths['metadata'] ) ) {
                $has_export = true;
                $export_files['meta_file'] = array(
                    'name' => 'Metadata (.json)',
                    'url'  => $file_urls['metadata'],
                    'size' => $this->filesystem->format_file_size( filesize( $file_paths['metadata'] ) ),
                );
            }
            
            // Add log file if it exists
            if ( file_exists( $file_paths['log'] ) ) {
                $has_export = true;
                $export_files['log_file'] = array(
                    'name' => 'Export Log (.txt)',
                    'url'  => $file_urls['log'],
                    'size' => $this->filesystem->format_file_size( filesize( $file_paths['log'] ) ),
                );
            }
        }
        
        // Get status for determining if export is done
        $current_status = '';
        $status_file = $this->filesystem->get_status_file_path();
        if ( file_exists( $status_file ) ) {
            $current_status = trim( file_get_contents( $status_file ) );
        }
        
        // Get system information
        $max_execution_time = ini_get( 'max_execution_time' );
        $memory_limit = ini_get( 'memory_limit' );
        $upload_max_filesize = ini_get( 'upload_max_filesize' );
        
        // Get WP content size estimate - using accurate calculation method
        $wp_content_size = $this->get_accurate_wp_content_size();
        $wp_content_size_formatted = $this->filesystem->format_file_size($wp_content_size);
        
        // Include the view
        include CUSTOM_MIGRATOR_PLUGIN_DIR . 'admin/views/admin-display.php';
    }

    /**
     * Get accurate wp-content size by excluding paths that won't be exported.
     *
     * @return int Size in bytes.
     */
    private function get_accurate_wp_content_size() {
        $wp_content_dir = WP_CONTENT_DIR;
        $total_size = 0;
        
        // Define paths to exclude - match those from class-exporter.php
        $exclusion_paths = [
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
        
        // Allow filtering of exclusion paths (match the exporter)
        $exclusion_paths = apply_filters('custom_migrator_export_exclusion_paths', $exclusion_paths);
        
        // Process directories recursively except excluded ones
        $total_size = $this->calculate_directory_size($wp_content_dir, $exclusion_paths);
        
        return $total_size;
    }

    /**
     * Calculate directory size recursively excluding specified paths.
     *
     * @param string $directory Directory to calculate size for
     * @param array $exclusion_paths Paths to exclude
     * @return int Size in bytes
     */
    private function calculate_directory_size($directory, $exclusion_paths) {
        $size = 0;
        
        // Ensure directory exists and is readable
        if (!is_dir($directory) || !is_readable($directory)) {
            return 0;
        }
        
        $items = new DirectoryIterator($directory);
        
        foreach ($items as $item) {
            // Skip dots
            if ($item->isDot()) {
                continue;
            }
            
            $path = $item->getPathname();
            
            // Check if path is in exclusion list
            $is_excluded = false;
            foreach ($exclusion_paths as $excluded_path) {
                if (strpos($path, $excluded_path) === 0) {
                    $is_excluded = true;
                    break;
                }
            }
            
            if ($is_excluded) {
                continue;
            }
            
            if ($item->isFile()) {
                $size += $item->getSize();
            } else if ($item->isDir()) {
                $size += $this->calculate_directory_size($path, $exclusion_paths);
            }
        }
        
        return $size;
    }
}