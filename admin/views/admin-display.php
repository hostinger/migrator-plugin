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
    </h1>
    
    <?php
    // Show success message if export was started
    if ( isset( $_GET['export_started'] ) && $_GET['export_started'] === '1' ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             esc_html__( 'Export process started. It will run in the background. Check back in a few minutes.', 'custom-migrator' ) . 
             '</p></div>';
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
            $log_file_path = $this->get_secure_log_file_path();
            if (file_exists($log_file_path)) {
                $log_file_url = $this->get_secure_log_file_url();
                echo '<p>' . 
                     sprintf( 
                         esc_html__( 'For more details, please check the %s.', 'custom-migrator' ),
                         '<a href="' . esc_url($log_file_url) . '" download>' . esc_html__('export log', 'custom-migrator') . '</a>'
                     ) . 
                     '</p>';
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
                <input type="submit" name="start_export" id="start-export" class="button button-primary" value="<?php esc_attr_e('Start Export', 'custom-migrator'); ?>" <?php disabled( ! $is_writable || ! $content_dir_readable ); ?>>
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
        </div>
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
        
        <div class="server-paths">
            <h3><?php esc_html_e( 'Server Paths', 'custom-migrator' ); ?></h3>
            <p><?php esc_html_e( 'Hosting provider information:', 'custom-migrator' ); ?></p>
            <div class="server-paths-info">
                <code><?php echo esc_html($export_dir); ?></code>
            </div>
        </div>
        
        <div class="import-instructions">
            <h3><?php esc_html_e( 'How to Import', 'custom-migrator' ); ?></h3>
            <p><?php esc_html_e( 'To import this site on your new server:', 'custom-migrator' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Download all files listed above', 'custom-migrator' ); ?></li>
                <li><?php esc_html_e( 'Install WordPress on your new server', 'custom-migrator' ); ?></li>
                <li><?php esc_html_e( 'Decompress the .sql.gz file using gunzip or a similar tool', 'custom-migrator' ); ?></li>
                <li><?php esc_html_e( 'Import the SQL file using phpMyAdmin or similar database tool', 'custom-migrator' ); ?></li>
                <li><?php esc_html_e( 'Extract the content from the .hstgr file to your new wp-content directory', 'custom-migrator' ); ?></li>
                <li><?php esc_html_e( 'Update your wp-config.php file with the database credentials', 'custom-migrator' ); ?></li>
                <li><?php esc_html_e( 'Update site URL in database if your domain has changed', 'custom-migrator' ); ?></li>
            </ol>
        </div>
    </div>
    
    <div class="custom-migrator-section">
        <h2><?php esc_html_e( 'System Information', 'custom-migrator' ); ?></h2>
        <table class="widefat">
            <tr>
                <th><?php esc_html_e( 'WordPress Version', 'custom-migrator' ); ?></th>
                <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'PHP Version', 'custom-migrator' ); ?></th>
                <td><?php echo esc_html( phpversion() ); ?></td>
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
            <tr>
                <th><?php esc_html_e( 'Security', 'custom-migrator' ); ?></th>
                <td>
                    <span class="dashicons dashicons-shield-alt" style="color: green;"></span>
                    <?php esc_html_e( 'Secure randomized filenames', 'custom-migrator' ); ?>
                </td>
            </tr>
        </table>
    </div>
</div>