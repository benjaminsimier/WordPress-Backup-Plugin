<?php
/**
 * Main plugin class
 */
class WP_Backup_GDrive {
    
    /**
     * The admin instance
     */
    protected $admin;
    
    /**
     * The backup instance
     */
    protected $backup;
    
    /**
     * The Google API instance
     */
    protected $google_api;
    
    /**
     * Initialize the plugin
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_backup_hooks();
    }
    
    /**
     * Load dependencies
     */
    private function load_dependencies() {
        $this->admin = new WP_Backup_GDrive_Admin();
        $this->backup = new WP_Backup_GDrive_Backup();
        $this->google_api = new WP_Backup_GDrive_Google_API();
    }
    
    /**
     * Define admin hooks
     */
    private function define_admin_hooks() {
        add_action('admin_menu', array($this->admin, 'add_plugin_menu'));
        add_action('admin_init', array($this->admin, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_scripts'));
        add_action('wp_ajax_wp_backup_gdrive_select_folder', array($this->google_api, 'select_folder'));
        add_action('wp_ajax_wp_backup_gdrive_create_folder', array($this->google_api, 'create_folder'));
    }
    
    /**
     * Define backup hooks
     */
    private function define_backup_hooks() {
        add_action('wp_backup_gdrive_scheduled_backup', array($this->backup, 'run_backup'));
        add_action('wp_ajax_wp_backup_gdrive_manual_backup', array($this->backup, 'run_backup'));
    }
    
    /**
     * Run the plugin
     */
    public function run() {
        // Initialize Google API
        $this->google_api->init();
    }
}

