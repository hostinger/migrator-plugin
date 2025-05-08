<?php
/**
 * The class responsible for generating metadata.
 *
 * @package CustomMigrator
 */

/**
 * Metadata class.
 */
class Custom_Migrator_Metadata {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->filesystem = new Custom_Migrator_Filesystem();
    }

    /**
     * Generate metadata for the export.
     *
     * @return array The export metadata.
     */
    public function generate() {
        global $wp_version, $wpdb;

        // Get active theme data
        $active_theme = wp_get_theme();
        $parent_theme = $active_theme->parent() ? $active_theme->parent() : null;

        // Get active plugins data
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_slugs = array();
        
        if ( ! empty( $active_plugins ) ) {
            foreach ( $active_plugins as $plugin ) {
                // Extract plugin slug from plugin path
                $plugin_slug = explode( '/', $plugin )[0];
                $plugin_slugs[] = $plugin_slug;
            }
        }

        // Get multisite active network plugins if applicable
        $network_plugins = array();
        if ( is_multisite() ) {
            $network_active_plugins = get_site_option( 'active_sitewide_plugins', array() );
            if ( ! empty( $network_active_plugins ) ) {
                foreach ( array_keys( $network_active_plugins ) as $plugin ) {
                    $plugin_slug = explode( '/', $plugin )[0];
                    $network_plugins[] = $plugin_slug;
                }
            }
        }

        // Calculate wp-content size
        $wp_content_size = $this->filesystem->get_directory_size(WP_CONTENT_DIR);
        $wp_content_size_formatted = $this->filesystem->format_file_size($wp_content_size);

        // Build metadata array
        $metadata = array(
            'site_info' => array(
                'site_url'     => get_site_url(),
                'home_url'     => get_home_url(),
                'wp_version'   => $wp_version,
                'php_version'  => phpversion(),
                'table_prefix' => $wpdb->prefix,
                'is_multisite' => is_multisite(),
                'site_title'   => get_bloginfo( 'name' ),
                'site_desc'    => get_bloginfo( 'description' ),
                'charset'      => get_bloginfo( 'charset' ),
                'language'     => get_bloginfo( 'language' ),
                'admin_email'  => get_bloginfo( 'admin_email' ),
            ),
            'export_info' => array(
                'created_at'   => gmdate( 'c' ),
                'created_by'   => 'Hostinger Migrator v' . CUSTOM_MIGRATOR_VERSION,
                'wp_content_version' => md5( WP_CONTENT_DIR . time() ),
                'wp_content_size' => $wp_content_size,
                'wp_content_size_formatted' => $wp_content_size_formatted,
            ),
            'themes' => array(
                'active_theme' => array(
                    'slug'      => $active_theme->get_stylesheet(),
                    'name'      => $active_theme->get( 'Name' ),
                    'version'   => $active_theme->get( 'Version' ),
                    'theme_uri' => $active_theme->get( 'ThemeURI' ),
                    'author'    => $active_theme->get( 'Author' ),
                ),
                'parent_theme' => $parent_theme ? array(
                    'slug'      => $parent_theme->get_stylesheet(),
                    'name'      => $parent_theme->get( 'Name' ),
                    'version'   => $parent_theme->get( 'Version' ),
                    'theme_uri' => $parent_theme->get( 'ThemeURI' ),
                    'author'    => $parent_theme->get( 'Author' ),
                ) : null,
            ),
            'plugins' => array(
                'active_plugins'        => $plugin_slugs,
                'network_plugins'       => $network_plugins,
                'active_plugins_count'  => count( $plugin_slugs ),
                'network_plugins_count' => count( $network_plugins ),
            ),
            'database' => array(
                'charset'      => defined( 'DB_CHARSET' ) ? DB_CHARSET : '',
                'collate'      => defined( 'DB_COLLATE' ) ? DB_COLLATE : '',
                'tables_count' => $this->get_tables_count(),
                'total_size_mb'=> $this->get_database_size(),
                'total_size_bytes' => $this->get_database_size(true),
                'total_size_formatted' => $this->filesystem->format_file_size($this->get_database_size(true)),
            ),
            'system' => array(
                'max_execution_time' => ini_get( 'max_execution_time' ),
                'memory_limit'       => ini_get( 'memory_limit' ),
                'post_max_size'      => ini_get( 'post_max_size' ),
                'upload_max_filesize'=> ini_get( 'upload_max_filesize' ),
                'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '',
                'os'                 => PHP_OS,
            ),
        );

        return $metadata;
    }

    /**
     * Get the total number of database tables.
     *
     * @return int The number of tables in the database.
     */
    private function get_tables_count() {
        global $wpdb;
        return count($wpdb->get_results("SHOW TABLES", ARRAY_N));
    }
    
    /**
     * Get the total size of the database.
     *
     * @param bool $in_bytes Whether to return the size in bytes (true) or MB (false).
     * @return float|int The total database size in MB or bytes.
     */
    private function get_database_size($in_bytes = false) {
        global $wpdb;
        
        $size_query = $wpdb->prepare(
            "SELECT SUM(data_length + index_length) AS size_bytes 
             FROM information_schema.TABLES 
             WHERE table_schema = %s",
            DB_NAME
        );
        
        $size = $wpdb->get_var($size_query);
        
        if ($in_bytes) {
            return $size ? (int)$size : 0;
        } else {
            $size_mb = $size ? round($size / 1024 / 1024, 2) : 0;
            return $size_mb;
        }
    }
}