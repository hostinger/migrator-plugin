<?php
/**
 * Plugin Name: Hostinger Migrator
 * Description: Exports wp-content as a .hstgr file and the database as a separate .sql.gz file with metadata in .json
 * Version: 1.0
 * Author: Your Name
 * License: GPL-2.0+
 * Text Domain: custom-migrator
 *
 * @package CustomMigrator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants.
define( 'CUSTOM_MIGRATOR_VERSION', '1.0.0' );
define( 'CUSTOM_MIGRATOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CUSTOM_MIGRATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CUSTOM_MIGRATOR_ADMIN_URL', admin_url( 'admin.php?page=custom-migrator' ) );
define( 'CUSTOM_MIGRATOR_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once CUSTOM_MIGRATOR_PLUGIN_DIR . 'includes/class-core.php';

/**
 * Register activation and deactivation hooks
 */
register_activation_hook( __FILE__, 'custom_migrator_activate' );
register_deactivation_hook( __FILE__, 'custom_migrator_deactivate' );

/**
 * Plugin activation function
 */
function custom_migrator_activate() {
    // Create necessary directories or set initial options if needed
    $filesystem = new Custom_Migrator_Filesystem();
    $filesystem->create_export_dir();
}

/**
 * Plugin deactivation function
 */
function custom_migrator_deactivate() {
    // Nothing to do on deactivation
}

/**
 * Begins execution of the plugin.
 */
function run_custom_migrator() {
    // Initialize the core class
    $plugin = new Custom_Migrator_Core();
    $plugin->init();
}

// Start the plugin
run_custom_migrator();