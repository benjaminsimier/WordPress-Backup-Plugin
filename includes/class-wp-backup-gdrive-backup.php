<?php
/**
 * Backup functionality
 */
class WP_Backup_GDrive_Backup {
    
    /**
     * The backup directory
     */
    private $backup_dir;
    
    /**
     * The logs directory
     */
    private $logs_dir;
    
    /**
     * The Google API instance
     */
    private $google_api;
    
    /**
     * Initialize the backup
     */
    public function __construct() {
        // Set backup directory in wp-content
        $this->backup_dir = WP_CONTENT_DIR . '/backups/wp-backup-gdrive';
        $this->logs_dir = $this->backup_dir . '/logs';
        
        // Create backup directory if it doesn't exist
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            // Create .htaccess to protect backups
            file_put_contents($this->backup_dir . '/.htaccess', 'deny from all');
        }
        
        // Create logs directory if it doesn't exist
        if (!file_exists($this->logs_dir)) {
            wp_mkdir_p($this->logs_dir);
        }
        
        $this->google_api = new WP_Backup_GDrive_Google_API();
    }
    
    /**
     * Run backup
     */
    public function run_backup() {
        // Check if this is an AJAX request
        $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
    
        // Verify nonce if this is an AJAX request
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_backup_gdrive_create_folder')) {
            throw new Exception('Invalid nonce');
        }
    
        // Get backup options
        $options = get_option('wp_backup_gdrive_settings');
    
        // Check if folder is selected
        if (!isset($options['google_folder_id']) || empty($options['google_folder_id'])) {
            if ($is_ajax) {
                wp_send_json_error('Please select a backup folder in Google Drive first');
            }
            return false;
        }
    
        // Determine what to backup based on passed $data
        $backup_files = isset($$_POST['backup_files']) && $$_POST['backup_files'] === 'true';
        $backup_database = isset($$_POST['backup_database']) && $$_POST['backup_database'] === 'true';
    
        // Start logging
        $log_file = $this->logs_dir . '/backup-' . date('Y-m-d-H-i-s') . '.log';
        $this->log($log_file, 'Starting backup process');
    
        // Verify Google Drive connection
        try {
            if (!$this->google_api->is_authorized()) {
                $this->log($log_file, 'Error: Not connected to Google Drive');
                if ($is_ajax) {
                    wp_send_json_error('Not connected to Google Drive');
                }
                return false;
            }
        } catch (Exception $e) {
            $this->log($log_file, 'Error: Google Drive authorization failed - ' . $e->getMessage());
            if ($is_ajax) {
                wp_send_json_error('Google Drive authorization failed: ' . $e->getMessage());
            }
            return false;
        }
    
        // Create backup filename with timestamp
        $timestamp = date('Y-m-d-H-i-s');
        $site_name = sanitize_title_with_dashes(get_bloginfo('name'));
        $backup_filename = $site_name . '-backup-' . $timestamp;
        $backup_path = $this->backup_dir . '/' . $backup_filename;
    
        // Ensure backup directory exists and is writable
        if (!file_exists($this->backup_dir)) {
            if (!wp_mkdir_p($this->backup_dir)) {
                $this->log($log_file, 'Error: Failed to create backup directory');
                if ($is_ajax) {
                    wp_send_json_error('Failed to create backup directory');
                }
                return false;
            }
            // Protect the backup directory
            file_put_contents($this->backup_dir . '/.htaccess', 'deny from all');
        }
    
        if (!is_writable($this->backup_dir)) {
            $this->log($log_file, 'Error: Backup directory is not writable');
            if ($is_ajax) {
                wp_send_json_error('Backup directory is not writable');
            }
            return false;
        }
    
        // Create temporary directory for backup files
        $temp_dir = $backup_path . '-temp';
        if (!file_exists($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                $this->log($log_file, 'Error: Failed to create temporary directory');
                if ($is_ajax) {
                    wp_send_json_error('Failed to create temporary directory');
                }
                return false;
            }
        }
    
        $this->log($log_file, 'Created temporary directory: ' . $temp_dir);
    
        try {
            // Backup database if enabled
            if ($backup_database) {
                $this->log($log_file, 'Starting database backup');
                $db_file = $this->backup_database($temp_dir, $log_file);
                if ($db_file) {
                    $this->log($log_file, 'Database backup completed: ' . $db_file);
                } else {
                    throw new Exception('Database backup failed');
                }
            }
    
            // Backup files if enabled
            if ($backup_files) {
                $this->log($log_file, 'Starting files backup');
                $this->backup_files($temp_dir, $log_file);
                $this->log($log_file, 'Files backup completed');
            }
    
            // Create zip archive
            $this->log($log_file, 'Creating zip archive');
            $zip_file = $backup_path . '.zip';
            $zip_created = $this->create_zip_archive($temp_dir, $zip_file, $log_file);
    
            if (!$zip_created) {
                throw new Exception('Failed to create zip archive');
            }
    
            // Verify zip file exists and is readable
            if (!file_exists($zip_file) || !is_readable($zip_file)) {
                throw new Exception('Zip file not found or not readable: ' . $zip_file);
            }
    
            // Get file size for logging
            $zip_size = filesize($zip_file);
            $this->log($log_file, 'Zip file created: ' . basename($zip_file) . ' (Size: ' . size_format($zip_size) . ')');
    
            // Upload to Google Drive
            $this->log($log_file, 'Uploading to Google Drive: ' . basename($zip_file));
            $upload_result = $this->google_api->upload_file($zip_file, basename($zip_file), $log_file);
    
            if (!$upload_result) {
                throw new Exception('Failed to upload backup to Google Drive');
            }
    
            $this->log($log_file, 'Upload to Google Drive completed successfully');
    
            // Clean up
            $this->log($log_file, 'Cleaning up temporary files');
            $this->cleanup($temp_dir, $zip_file, $log_file);
    
            // Manage backup retention
            $this->manage_backup_retention($log_file);
    
            $this->log($log_file, 'Backup process completed successfully');
    
            // Send JSON response if this is an AJAX request
            if ($is_ajax) {
                wp_send_json_success(array(
                    'message' => 'Backup completed successfully',
                    'log_file' => basename($log_file)
                ));
            }
    
            return true;
    
        } catch (Exception $e) {
            $this->log($log_file, 'Error: ' . $e->getMessage());
    
            // Clean up on error
            if (file_exists($temp_dir)) {
                $this->remove_directory($temp_dir);
            }
    
            if ($is_ajax) {
                wp_send_json_error($e->getMessage());
            } else {
                // For non-AJAX requests, return false to indicate failure
                return false;
            }
        }
    }
    

    /**
     * Create zip archive
     */
    private function create_zip_archive($source_dir, $destination_zip, $log_file) {
        if (class_exists('ZipArchive')) {
            $this->log($log_file, 'Using ZipArchive to create zip file');
            
            $zip = new ZipArchive();
            
            if ($zip->open($destination_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $source_dir = rtrim($source_dir, '/');
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($source_dir),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                $total_files = 0;
                $failed_files = 0;
                
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $file_path = $file->getRealPath();
                        $relative_path = substr($file_path, strlen($source_dir) + 1);
                        
                        if ($zip->addFile($file_path, $relative_path)) {
                            $total_files++;
                        } else {
                            $failed_files++;
                            $this->log($log_file, 'Failed to add file to zip: ' . $relative_path);
                        }
                    }
                }
                
                if ($zip->close()) {
                    $this->log($log_file, "Zip file created successfully with $total_files files" . 
                        ($failed_files > 0 ? " ($failed_files files failed)" : ""));
                    return true;
                } else {
                    $this->log($log_file, 'Failed to close zip file');
                    return false;
                }
            } else {
                $this->log($log_file, 'Failed to create zip file');
                return false;
            }
        } else {
            // Fallback to PclZip
            $this->log($log_file, 'ZipArchive not available, using PclZip');
            
            require_once(ABSPATH . 'wp-admin/includes/class-pclzip.php');
            
            $zip = new PclZip($destination_zip);
            $result = $zip->create($source_dir, PCLZIP_OPT_REMOVE_PATH, $source_dir);
            
            if ($result === 0) {
                $this->log($log_file, 'PclZip error: ' . $zip->errorInfo(true));
                return false;
            }
            
            $this->log($log_file, 'Zip file created successfully using PclZip');
            return true;
        }
    }
}