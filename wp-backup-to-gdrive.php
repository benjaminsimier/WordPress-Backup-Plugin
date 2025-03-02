<?php
/**
 * Plugin Name: WP Backup to Google Drive
 * Plugin URI: https://example.com/wp-backup-to-gdrive
 * Description: Backup your WordPress files and database to Google Drive
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL-2.0+
 * Text Domain: wp-backup-to-gdrive
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('WP_BACKUP_GDRIVE_VERSION', '1.0.0');
define('WP_BACKUP_GDRIVE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_BACKUP_GDRIVE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once WP_BACKUP_GDRIVE_PLUGIN_DIR . 'vendor/autoload.php';
require_once WP_BACKUP_GDRIVE_PLUGIN_DIR . 'includes/class-wp-backup-gdrive.php';
require_once WP_BACKUP_GDRIVE_PLUGIN_DIR . 'includes/class-wp-backup-gdrive-admin.php';
require_once WP_BACKUP_GDRIVE_PLUGIN_DIR . 'includes/class-wp-backup-gdrive-backup.php';
require_once WP_BACKUP_GDRIVE_PLUGIN_DIR . 'includes/class-wp-backup-gdrive-google-api.php';

// Activation and deactivation hooks
register_activation_hook(__FILE__, 'wp_backup_gdrive_activate');
register_deactivation_hook(__FILE__, 'wp_backup_gdrive_deactivate');

/**
 * Plugin activation function
 */
function wp_backup_gdrive_activate() {
    // Create backup directory
    $backup_dir = WP_CONTENT_DIR . '/backups/wp-backup-gdrive';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
        // Create .htaccess to protect backups
        file_put_contents($backup_dir . '/.htaccess', 'deny from all');
    }

    // Create logs directory
    $logs_dir = $backup_dir . '/logs';
    if (!file_exists($logs_dir)) {
        wp_mkdir_p($logs_dir);
    }

    // Schedule backup event
    if (!wp_next_scheduled('wp_backup_gdrive_scheduled_backup')) {
        wp_schedule_event(time(), 'daily', 'wp_backup_gdrive_scheduled_backup');
    }
}

/**
 * Plugin deactivation function
 */
function wp_backup_gdrive_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('wp_backup_gdrive_scheduled_backup');
}

/**
 * Initialize the plugin
 */
function wp_backup_gdrive_init() {
    $plugin = new WP_Backup_GDrive();
    $plugin->run();
}
add_action('plugins_loaded', 'wp_backup_gdrive_init');

