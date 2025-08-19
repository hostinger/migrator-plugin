<?php
/**
 * Admin page display template.
 *
 * @package CustomMigrator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}
?>

<div class="wrap custom-migrator">
    <h1>
        <span class="dashicons dashicons-migrate" style="font-size: 30px; height: 30px; width: 30px; padding-right: 10px;"></span>
        <?php echo esc_html( get_admin_page_title() ); ?>
        <button type="button" id="delete-plugin" class="button button-secondary" style="background-color: #dc3232; border-color: #dc3232; color: white; float: right; margin-top: 5px;">
            <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
            <?php esc_html_e( 'Delete Plugin', 'custom-migrator' ); ?>
        </button>
    </h1>
    
    <div id="delete-plugin-status" style="margin-bottom: 15px; display: none;"></div>
    
    <?php
    // Show success message if export was started
    if ( isset( $_GET['export_started'] ) && $_GET['export_started'] === '1' ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             esc_html__( 'Export process started. It will run in the background. Check back in a few minutes.', 'custom-migrator' ) . 
             '</p></div>';
    }
    
    // Show S3 upload success/error message
    if ( isset( $_GET['s3_upload'] ) ) {
        if ( $_GET['s3_upload'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__( 'Files successfully uploaded to S3.', 'custom-migrator' ) . 
                 '</p></div>';
        } elseif ( $_GET['s3_upload'] === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . 
                 esc_html__( 'Error uploading files to S3. Please check the export log for details.', 'custom-migrator' ) . 
                 '</p></div>';
        }
    }
    
    // Show error message if there was an export error
    $status_file = $export_dir . '/export-status.txt';
    if ( file_exists( $status_file ) ) {
        $current_status = trim( file_get_contents( $status_file ) );
        
        if ( strpos($current_status, 'error:') === 0 ) {
            $error_message = substr($current_status, 6); // Remove 'error:' prefix
            echo '<div class="notice notice-error"><p>' . 
                 sprintf( esc_html__( 'Export failed: %s', 'custom-migrator' ), '<strong>' . esc_html( $error_message ) . '</strong>' ) . 
                 '</p>';
            
            // Add a link to download the log file if available
            $log_file_path = $this->filesystem->get_log_file_path();
            if (file_exists($log_file_path)) {
                $log_file_urls = $this->filesystem->get_export_file_urls();
                $log_file_url = isset($log_file_urls['log']) ? $log_file_urls['log'] : '';
                if ($log_file_url) {
                    echo '<p>' . 
                         sprintf( 
                             esc_html__( 'For more details, please check the %s.', 'custom-migrator' ),
                             '<a href="' . esc_url($log_file_url) . '" download>' . esc_html__('export log', 'custom-migrator') . '</a>'
                         ) . 
                         '</p>';
                }
            }
            
            echo '</div>';
        }
        // Show status message if we have an ongoing export
        elseif ( $current_status !== 'done' && $current_status !== 'error' && !empty( $current_status ) ) {
            echo '<div class="notice notice-info"><p>' . 
                 sprintf( esc_html__( 'Export status: %s', 'custom-migrator' ), '<strong>' . esc_html( $current_status ) . '</strong>' ) . 
                 '</p></div>';
        }
    }
    ?>

    <div class="custom-migrator-section">
        <h2><?php esc_html_e( 'Export WordPress Site', 'custom-migrator' ); ?></h2>
        <p><?php esc_html_e( 'This tool will export your WordPress site content (wp-content folder) and database for migration purposes.', 'custom-migrator' ); ?></p>
        
        <?php if ( ! $is_writable ) : ?>
            <div class="notice notice-error">
                <p>
                    <?php 
                    printf(
                        esc_html__( 'Error: The directory %s is not writable. Please check your file permissions.', 'custom-migrator' ),
                        '<code>' . esc_html( $export_dir ) . '</code>'
                    ); 
                    ?>
                </p>
            </div>
        <?php endif; ?>
        
        <?php if ( ! $content_dir_readable ) : ?>
            <div class="notice notice-error">
                <p>
                    <?php 
                    printf(
                        esc_html__( 'Error: The WordPress content directory %s is not readable. Please check your file permissions.', 'custom-migrator' ),
                        '<code>' . esc_html( WP_CONTENT_DIR ) . '</code>'
                    ); 
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <?php 
        // Check for WP-Cron status
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        if ($cron_disabled) : 
        ?>
            <div class="notice notice-warning">
                <p>
                    <?php esc_html_e('Notice: WordPress cron is disabled on this site. The export will run directly instead of in the background, which may take longer for the page to load. For large sites, this might cause timeout issues.', 'custom-migrator'); ?>
                </p>
            </div>
        <?php endif; ?>
        
        <div class="custom-migrator-info">
            <p>
                <?php 
                printf(
                    esc_html__( 'Estimated export size: %s (wp-content folder size)', 'custom-migrator' ),
                    '<strong>' . esc_html( $wp_content_size_formatted ) . '</strong>'
                ); 
                ?>
            </p>
        </div>
        
        <div class="custom-migrator-controls">
            <form method="post" id="export-form">
                <?php wp_nonce_field('custom_migrator_action', 'custom_migrator_nonce'); ?>
                <button type="button" name="start_export" id="start-export" class="button button-primary" <?php disabled( ! $is_writable || ! $content_dir_readable ); ?>>
                    <?php esc_html_e('Start Export', 'custom-migrator'); ?>
                </button>
                <button type="button" id="start-fallback-export" class="button button-secondary" style="margin-left: 10px;" <?php disabled( ! $is_writable || ! $content_dir_readable ); ?>
                        onclick="this.disabled=true; this.innerHTML='Starting...'; startFallbackExport(); return false;">
                    <?php esc_html_e('Start Export - Fallback', 'custom-migrator'); ?>
                </button>
            </form>
            
            <div id="export-progress" style="margin-top: 20px; <?php echo ($current_status && $current_status !== 'done' && strpos($current_status, 'error:') !== 0) ? '' : 'display: none;'; ?>">
                <div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div>
                <span id="export-status-text">
                    <?php 
                    if ($current_status && $current_status !== 'done' && strpos($current_status, 'error:') !== 0) {
                        echo esc_html(ucfirst($current_status)) . '...';
                    } else {
                        echo esc_html__('Starting...', 'custom-migrator');
                    }
                    ?>
                </span>
                <div id="export-log-preview" style="margin-top: 10px; color: #666; font-style: italic; display: none;"></div>
            </div>
            
            <!-- Fallback Export Progress -->
            <div id="fallback-progress" style="margin-top: 20px; display: none;">
                <div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div>
                <span id="fallback-status-text"><?php esc_html_e('Starting fallback export...', 'custom-migrator'); ?></span>
                <div id="fallback-step-info" style="margin-top: 10px; color: #666; font-style: italic;"></div>
            </div>
            
            <!-- Export Status Display -->
            <div id="export-status-display" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa; font-family: monospace; font-size: 12px; color: #333; display: none;">
                <strong>Export Status:</strong> <span id="export-status-content">-</span>
            </div>
        </div>
        
        <!-- Fallback export function is defined in script.js -->
    </div>
    
    <div id="export-results" class="custom-migrator-section" style="display: <?php echo $has_export ? 'block' : 'none'; ?>;">
        <h2><?php esc_html_e( 'Export Files', 'custom-migrator' ); ?></h2>
        <p><?php esc_html_e( 'The following files have been created for your site migration:', 'custom-migrator' ); ?></p>
        
        <table class="widefat" id="export-files-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'File', 'custom-migrator' ); ?></th>
                    <th><?php esc_html_e( 'Size', 'custom-migrator' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'custom-migrator' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( $has_export ) : ?>
                    <?php foreach ( $export_files as $file_type => $file ) : 
                        $filename = basename($file['url']);
                        
                        // Set button ID based on file type
                        $button_id = '';
                        if ($file_type === 'hstgr_file') {
                            $button_id = 'id="download-content"';
                        } elseif ($file_type === 'sql_file') {
                            $button_id = 'id="download-db"';
                        } elseif ($file_type === 'meta_file') {
                            $button_id = 'id="download-meta"';
                        } elseif ($file_type === 'log_file') {
                            $button_id = 'id="download-log"';
                        }
                    ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $file['name'] ); ?>
                                <br>
                                <small class="description" style="word-break: break-all; display: inline-block; max-width: 100%;">
                                    <?php echo esc_html($filename); ?>
                                </small>
                            </td>
                            <td><?php echo esc_html( $file['size'] ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $file['url'] ); ?>" class="button button-secondary" <?php echo $button_id; ?> download="<?php echo esc_attr($filename); ?>">
                                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                    <?php esc_html_e( 'Download', 'custom-migrator' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- S3 Upload Section -->
        <?php if ($has_export && $current_status === 'done'): ?>
        <div class="s3-upload-section">
            <h3><?php esc_html_e('Upload to S3', 'custom-migrator'); ?></h3>
            <p><?php esc_html_e('You can upload your export files to Amazon S3 using pre-signed URLs.', 'custom-migrator'); ?></p>
            
            <form method="post" id="s3-upload-form">
                <?php wp_nonce_field('custom_migrator_s3_action', 'custom_migrator_s3_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Content File (.hstgr) URL', 'custom-migrator'); ?></th>
                        <td>
                            <input type="text" name="s3_url_hstgr" class="large-text" placeholder="https://your-bucket.s3.amazonaws.com/path/to/file?AWSAccessKeyId=...">
                            <p class="description"><?php esc_html_e('Enter the pre-signed URL for the content (.hstgr) file.', 'custom-migrator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Database File (.sql.gz) URL', 'custom-migrator'); ?></th>
                        <td>
                            <input type="text" name="s3_url_sql" class="large-text" placeholder="https://your-bucket.s3.amazonaws.com/path/to/file?AWSAccessKeyId=...">
                            <p class="description"><?php esc_html_e('Enter the pre-signed URL for the database (.sql.gz) file.', 'custom-migrator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Metadata File (.json) URL', 'custom-migrator'); ?></th>
                        <td>
                            <input type="text" name="s3_url_metadata" class="large-text" placeholder="https://your-bucket.s3.amazonaws.com/path/to/file?AWSAccessKeyId=...">
                            <p class="description"><?php esc_html_e('Enter the pre-signed URL for the metadata (.json) file.', 'custom-migrator'); ?></p>
                        </td>
                    </tr>
                </table>
                <div id="s3-upload-status" style="margin-bottom: 15px; display: none;"></div>
                
                <!-- S3 Upload Status Display -->
                <div id="s3-upload-status-display" style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #00a32a; font-family: monospace; font-size: 12px; color: #333; display: none;">
                    <strong>S3 Upload Status:</strong> <span id="s3-upload-status-content">-</span>
                </div>
                
                <p class="submit">
                    <input type="submit" name="upload_to_s3" id="upload-to-s3" class="button button-primary" value="<?php esc_attr_e('Upload to S3', 'custom-migrator'); ?>">
                    <span class="spinner" id="s3-upload-spinner" style="float: none; margin-top: 4px;"></span>
                </p>
            </form>
        </div>
        <?php endif; ?>
        

    </div>
    
    <div class="custom-migrator-section">
        <h2><?php esc_html_e( 'System Information', 'custom-migrator' ); ?></h2>
        <table class="widefat">
            <tr>
                <th><?php esc_html_e( 'WordPress Version', 'custom-migrator' ); ?></th>
                <td id="wordpress-version"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'PHP Version', 'custom-migrator' ); ?></th>
                <td id="php-version"><?php echo esc_html( phpversion() ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Table Prefix', 'custom-migrator' ); ?></th>
                <td id="table-prefix"><?php echo esc_html( $table_prefix ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Max Execution Time', 'custom-migrator' ); ?></th>
                <td>
                    <?php 
                    echo esc_html( $max_execution_time ) . ' ' . esc_html__( 'seconds', 'custom-migrator' );
                    if ( $max_execution_time < 300 ) {
                        echo ' <span class="notice-warning">' . esc_html__( '(Recommended: 300 or higher for large sites)', 'custom-migrator' ) . '</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Memory Limit', 'custom-migrator' ); ?></th>
                <td>
                    <?php 
                    echo esc_html( $memory_limit ); 
                    if ( strpos( $memory_limit, 'M' ) !== false && (int) $memory_limit < 256 ) {
                        echo ' <span class="notice-warning">' . esc_html__( '(Recommended: 256M or higher for large sites)', 'custom-migrator' ) . '</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Upload Max Filesize', 'custom-migrator' ); ?></th>
                <td><?php echo esc_html( $upload_max_filesize ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Export Directory', 'custom-migrator' ); ?></th>
                <td>
                    <?php 
                    echo esc_html( $export_dir ); 
                    if ( $dir_exists ) {
                        echo ' <span class="dashicons dashicons-yes" style="color: green;"></span>';
                    } else {
                        echo ' <span class="dashicons dashicons-no" style="color: red;"></span> ' . esc_html__( '(Will be created during export)', 'custom-migrator' );
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'WordPress Cron', 'custom-migrator' ); ?></th>
                <td>
                    <?php if ($cron_disabled): ?>
                        <span class="dashicons dashicons-warning" style="color: orange;"></span>
                        <?php esc_html_e('Disabled - using direct processing instead', 'custom-migrator'); ?>
                    <?php else: ?>
                        <span class="dashicons dashicons-yes" style="color: green;"></span>
                        <?php esc_html_e('Enabled and working', 'custom-migrator'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>