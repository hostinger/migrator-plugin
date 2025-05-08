<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * This file is executed when the plugin is deleted through the WordPress admin interface.
 * It cleans up all plugin data, including export files and directories.
 *
 * @package CustomMigrator
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up all plugin data
 */
function custom_migrator_cleanup() {
    // Remove plugin options
    delete_option('custom_migrator_filenames');
    delete_option('custom_migrator_access_token');
    delete_option('custom_migrator_auth');
    delete_option('custom_migrator_export_subdir'); // If you kept this option
    
    // Get the path to clean up
    $export_dir = WP_CONTENT_DIR . '/hostinger-migration-archives'; // New location
    
    // Clean up export directory
    if (file_exists($export_dir) && is_dir($export_dir)) {
        custom_migrator_delete_directory($export_dir);
        
        // Log the cleanup action
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Custom Migrator plugin uninstalled and export directory removed: ' . $export_dir);
        }
    }
}

/**
 * Recursively delete a directory and all its contents
 *
 * @param string $dir Directory path
 * @return bool True on success
 */
function custom_migrator_delete_directory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    // Get all items in the directory
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        
        if (is_dir($path)) {
            // Recursively delete subdirectories
            custom_migrator_delete_directory($path);
        } else {
            // Delete files
            unlink($path);
        }
    }
    
    // Finally remove the directory itself
    return rmdir($dir);
}

// Run cleanup
custom_migrator_cleanup();