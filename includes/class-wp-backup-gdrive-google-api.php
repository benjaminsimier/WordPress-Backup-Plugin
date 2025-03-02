<?php
/**
 * Google Drive API functionality
 */
class WP_Backup_GDrive_Google_API {
    
    /**
     * Google API client
     */
    private $client;
    
    /**
     * Google Drive service
     */
    private $service;
    
    /**
     * Initialize the Google API
     */
    public function init() {
        try {
            // Check if we need to load Google API client
            if (!class_exists('Google_Client')) {
                if (!file_exists(WP_BACKUP_GDRIVE_PLUGIN_DIR . 'vendor/autoload.php')) {
                    throw new Exception('Google API client not found. Please run composer install.');
                }
                require_once WP_BACKUP_GDRIVE_PLUGIN_DIR . 'vendor/autoload.php';
            }
            
            // Create Google API client
            $this->client = new Google_Client();
            $this->client->setApplicationName('WP Backup to Google Drive');
            $this->client->setScopes(array('https://www.googleapis.com/auth/drive.file'));
            $this->client->setAccessType('offline');
            $this->client->setPrompt('consent');
            
            // Set client ID and secret
            $options = get_option('wp_backup_gdrive_settings');
            
            if (!isset($options['google_client_id']) || !isset($options['google_client_secret'])) {
                throw new Exception('Client ID and Client Secret are required.');
            }
            
            $this->client->setClientId($options['google_client_id']);
            $this->client->setClientSecret($options['google_client_secret']);
            
            // Set tokens if available
            if (isset($options['google_refresh_token']) && !empty($options['google_refresh_token'])) {
                try {
                    // Set the refresh token
                    $this->client->setAccessToken([
                        'refresh_token' => $options['google_refresh_token'],
                        'access_token' => $options['google_access_token'] ?? null
                    ]);
                    
                    // If token is expired, refresh it
                    if ($this->client->isAccessTokenExpired()) {
                        $this->client->fetchAccessTokenWithRefreshToken($options['google_refresh_token']);
                        
                        // Save new access token
                        $token = $this->client->getAccessToken();
                        if (isset($token['access_token'])) {
                            $options['google_access_token'] = $token['access_token'];
                            update_option('wp_backup_gdrive_settings', $options);
                        }
                    }
                } catch (Exception $e) {
                    error_log('Google Drive API Error (Token Refresh): ' . $e->getMessage());
                    
                    // Clear tokens on error
                    unset($options['google_access_token']);
                    update_option('wp_backup_gdrive_settings', $options);
                    throw new Exception('Failed to refresh access token. Please re-authenticate.');
                }
            }
            
            // Create Google Drive service
            $this->service = new Google_Service_Drive($this->client);
            
        } catch (Exception $e) {
            error_log('Google Drive API Error (Init): ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if Google API is authorized
     */
    public function is_authorized(): bool {
        try {
            if ($this->client === null) {
                return false;
            }
    
            $accessToken = $this->client->getAccessToken();
            return $accessToken !== null && !$this->client->isAccessTokenExpired();
        } catch (\Exception $e) {
            error_log('Google Drive API Error (Auth Check): ' . $e->getMessage());
            return false;
        }
    }    
    
    /**
     * Upload file to Google Drive
     */
    public function upload_file($file_path, $file_name, $log_file = null) {
        try {
            if (!$this->is_authorized()) {
                throw new Exception('Not authorized with Google Drive');
            }
            
            if (!file_exists($file_path)) {
                throw new Exception('File not found: ' . $file_path);
            }

            if (!is_readable($file_path)) {
                throw new Exception('File not readable: ' . $file_path);
            }
            
            // Get backup folder ID
            $folder_id = $this->get_backup_folder_id();
            if (!$folder_id) {
                throw new Exception('Backup folder not found or not accessible');
            }
            
            // Verify folder exists and is accessible
            try {
                $folder = $this->service->files->get($folder_id, ['fields' => 'id, name']);
                $this->log($log_file, "Using folder: " . $folder->getName() . " (ID: " . $folder->getId() . ")");
            } catch (Exception $e) {
                throw new Exception('Cannot access folder: ' . $e->getMessage());
            }
            
            // Create file metadata
            $file_metadata = new Google_Service_Drive_DriveFile(array(
                'name' => $file_name,
                'parents' => array($folder_id)
            ));
            
            // Get file size
            $file_size = filesize($file_path);
            $this->log($log_file, "File size: " . size_format($file_size));
            
            // Upload file in chunks if it's large
            if ($file_size > 5 * 1024 * 1024) { // If file is larger than 5MB
                $this->log($log_file, "Large file detected, using chunked upload");
                
                $chunkSizeBytes = 1 * 1024 * 1024; // 1MB chunks
                
                // Create upload session
                $client = $this->service->getClient();
                $client->setDefer(true);
                $request = $this->service->files->create($file_metadata, array(
                    'uploadType' => 'resumable',
                    'fields' => 'id, name, size'
                ));
                
                // Get resumable upload URI
                $media = new Google_Http_MediaFileUpload(
                    $client,
                    $request,
                    'application/zip',
                    null,
                    true,
                    $chunkSizeBytes
                );
                $media->setFileSize($file_size);
                
                // Upload chunks
                $status = false;
                $handle = fopen($file_path, 'rb');
                while (!$status && !feof($handle)) {
                    $chunk = fread($handle, $chunkSizeBytes);
                    $status = $media->nextChunk($chunk);
                    $this->log($log_file, "Uploaded " . number_format(ftell($handle) / $file_size * 100, 2) . "%");
                }
                fclose($handle);
                
                $client->setDefer(false);
                
                if ($status) {
                    $this->log($log_file, "Chunked upload successful - File ID: " . $status->getId());
                    return $status->getId();
                }
            } else {
                // Upload small file directly
                $this->log($log_file, "Starting file upload: " . $file_name);
                
                $file = $this->service->files->create($file_metadata, array(
                    'data' => file_get_contents($file_path),
                    'mimeType' => 'application/zip',
                    'uploadType' => 'multipart',
                    'fields' => 'id, name, size'
                ));
                
                if ($file) {
                    $this->log($log_file, "Upload successful - File ID: " . $file->getId());
                    return $file->getId();
                }
            }
            
            throw new Exception('Upload failed - No file ID returned');
            
        } catch (Exception $e) {
            $this->log($log_file, "Error uploading file: " . $e->getMessage());
            error_log('Google Drive API Error (Upload): ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get backup folder ID
     */
    private function get_backup_folder_id() {
        try {
            $options = get_option('wp_backup_gdrive_settings');
            
            if (isset($options['google_folder_id']) && !empty($options['google_folder_id'])) {
                // Verify folder still exists and is accessible
                $folder = $this->service->files->get($options['google_folder_id'], ['fields' => 'id']);
                return $folder->getId();
            }
            
            // If no folder is selected or not accessible, create a new one
            return $this->create_backup_folder();
            
        } catch (Exception $e) {
            error_log('Google Drive API Error (Get Folder): ' . $e->getMessage());
            
            // Clear invalid folder ID
            $options = get_option('wp_backup_gdrive_settings');
            unset($options['google_folder_id']);
            unset($options['google_folder_name']);
            update_option('wp_backup_gdrive_settings', $options);
            
            // Try to create a new folder
            return $this->create_backup_folder();
        }
    }
    
    /**
     * Create backup folder
     */
    private function create_backup_folder() {
        try {
            // Create new folder
            $folder_metadata = new Google_Service_Drive_DriveFile(array(
                'name' => 'WordPress Backups',
                'mimeType' => 'application/vnd.google-apps.folder'
            ));
            
            $folder = $this->service->files->create($folder_metadata, array(
                'fields' => 'id, name'
            ));
            
            if ($folder) {
                // Save folder ID
                $options = get_option('wp_backup_gdrive_settings');
                $options['google_folder_id'] = $folder->getId();
                $options['google_folder_name'] = $folder->getName();
                update_option('wp_backup_gdrive_settings', $options);
                
                return $folder->getId();
            }
            
            throw new Exception('Failed to create backup folder');
            
        } catch (Exception $e) {
            error_log('Google Drive API Error (Create Folder): ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * List available Google Drive folders
     */
    public function list_drive_folders() {
        try {
            if (!$this->is_authorized()) {
                throw new Exception('Not authorized with Google Drive');
            }
            
            // Search for all folders
            $query = "mimeType='application/vnd.google-apps.folder' and trashed=false";
            $pageToken = null;
            $folders = array();
            
            do {
                $params = array(
                    'q' => $query,
                    'spaces' => 'drive',
                    'fields' => 'nextPageToken, files(id, name, parents)',
                    'pageSize' => 1000
                );
                
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }
                
                $results = $this->service->files->listFiles($params);
                $folders = array_merge($folders, $results->getFiles());
                $pageToken = $results->getNextPageToken();
            } while ($pageToken != null);
            
            // Sort folders by name
            usort($folders, function($a, $b) {
                return strcasecmp($a->getName(), $b->getName());
            });
            
            return $folders;
            
        } catch (Exception $e) {
            error_log('Google Drive API Error (List Folders): ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Create a new folder in Google Drive
     */
    public function create_folder() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_backup_gdrive_create_folder')) {
                throw new Exception('Invalid nonce');
            }
    
            // Get folder name
            $folder_name = isset($_POST['folder_name']) ? sanitize_text_field($_POST['folder_name']) : '';
    
            if (empty($folder_name)) {
                throw new Exception('Please provide a folder name');
            }
    
            // Create folder in Google Drive
            $folder = $this->createDriveFolder($folder_name);
    
            if (!$folder) {
                throw new Exception('Failed to create folder');
            }
    
            // Return success response
            wp_send_json_success([
                'id' => $folder->getId(),
                'name' => $folder->getName(),
                'message' => 'Folder created successfully'
            ]);
        } catch (Exception $e) {
            error_log('Google Drive AJAX Error (Create Folder): ' . $e->getMessage());
            wp_send_json_error(['error' => $e->getMessage()]);
            exit;
        }
    }
    

    public function createDriveFolder($folder_name) {
        try {
            // Ensure Google API is authenticated
            if (!$this->is_authorized()) {
                throw new Exception('Google Drive client is not authorized.');
            }
    
            // Initialize Google Drive service if missing
            if (!$this->service) {
                $this->service = new Google_Service_Drive($this->client);
            }
    
            // Create folder metadata
            $folderMetadata = new Google_Service_Drive_DriveFile([
                'name' => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder'
            ]);
    
            // Create folder in Google Drive
            $folder = $this->service->files->create($folderMetadata, ['fields' => 'id, name']);
    
            return $folder;
        } catch (Exception $e) {
            error_log('Google Drive API Error (Create Folder): ' . $e->getMessage());
            return false;
        }
    }    
    
    /**
     * Select folder handler
     */
    public function select_folder() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_backup_gdrive_select_folder')) {
                throw new Exception('Invalid nonce');
            }
            
            // Get folder details
            $folder_id = isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '';
            $folder_name = isset($_POST['folder_name']) ? sanitize_text_field($_POST['folder_name']) : '';
            
            if (empty($folder_id)) {
                throw new Exception('Invalid folder ID');
            }
            
            // Verify folder exists and is accessible
            try {
                $folder = $this->service->files->get($folder_id, ['fields' => 'id, name']);
                
                if (!$folder) {
                    throw new Exception('Folder not found or not accessible');
                }
                
                // Save folder details
                $options = get_option('wp_backup_gdrive_settings', array());
                $options['google_folder_id'] = $folder->getId();
                $options['google_folder_name'] = $folder->getName();
                
                // Make sure update_option is working
                $update_result = update_option('wp_backup_gdrive_settings', $options);
                
                // Debug log to verify the update happened
                error_log('Update result: ' . ($update_result ? 'success' : 'failed') . 
                          ' - Folder ID: ' . $folder->getId() . 
                          ' - Folder Name: ' . $folder->getName());
                
                wp_send_json_success(array(
                    'message' => 'Folder selected successfully',
                    'folder' => array(
                        'id' => $folder->getId(),
                        'name' => $folder->getName()
                    ),
                    'update_result' => $update_result
                ));
            } catch (Google_Service_Exception $e) {
                throw new Exception('Google API Error: ' . $e->getMessage());
            }
            
        } catch (Exception $e) {
            error_log('Google Drive API Error (Select Folder): ' . $e->getMessage());
            wp_send_json_error(array('error' => $e->getMessage()));
            exit;
        }
    }
    
    /**
     * Process OAuth Playground tokens
     */
    public function process_oauth_token($refresh_token) {
        try {
            if (empty($refresh_token)) {
                throw new Exception('Refresh token is required');
            }
            
            // Fetch new access token using refresh token
            $this->client->fetchAccessTokenWithRefreshToken($refresh_token);
            
            if ($this->client->getAccessToken()) {
                // Save both refresh token and access token
                $options = get_option('wp_backup_gdrive_settings');
                $options['google_refresh_token'] = $refresh_token;
                $options['google_access_token'] = $this->client->getAccessToken()['access_token'];
                update_option('wp_backup_gdrive_settings', $options);
                
                return true;
            }
            
            throw new Exception('Failed to get access token');
            
        } catch (Exception $e) {
            error_log('Google Drive API Error (Process Token): ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Manage Google Drive backups
     */
    public function manage_drive_backups($retention, $log_file) {
        try {
            // Get backup folder ID
            $folder_id = $this->get_backup_folder_id();
            
            if (!$folder_id) {
                throw new Exception('Backup folder not found');
            }
            
            // Get all backup files
            $query = "mimeType='application/zip' and '" . $folder_id . "' in parents and trashed=false";
            $results = $this->service->files->listFiles(array(
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name, createdTime)'
            ));
            
            $files = $results->getFiles();
            
            // Sort by creation time (newest first)
            usort($files, function($a, $b) {
                return strtotime($b->getCreatedTime()) - strtotime($a->getCreatedTime());
            });
            
            // Remove old backups
            if (count($files) > $retention) {
                $files_to_remove = array_slice($files, $retention);
                
                foreach ($files_to_remove as $file) {
                    $this->service->files->delete($file->getId());
                    $this->log($log_file, "Removed old Google Drive backup: " . $file->getName());
                }
            }
            
        } catch (Exception $e) {
            $this->log($log_file, "Error managing Google Drive backups: " . $e->getMessage());
            error_log('Google Drive API Error (Manage Backups): ' . $e->getMessage());
        }
    }
    
    /**
     * Log message to file if provided
     */
    private function log($log_file, $message) {
        if ($log_file) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
        }
    }
}

