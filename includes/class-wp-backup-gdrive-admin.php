<?php
/**
 * Admin functionality
 */
class WP_Backup_GDrive_Admin {
    
    /**
     * Add plugin menu
     */
    public function add_plugin_menu() {
        add_management_page(
            'WP Backup to Google Drive',
            'WP Backup to GDrive',
            'manage_options',
            'wp-backup-gdrive',
            array($this, 'display_plugin_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wp_backup_gdrive_settings', 'wp_backup_gdrive_settings');
        
        add_settings_section(
            'wp_backup_gdrive_general_section',
            'General Settings',
            array($this, 'general_section_callback'),
            'wp_backup_gdrive_settings'
        );
        
        add_settings_field(
            'backup_frequency',
            'Backup Frequency',
            array($this, 'backup_frequency_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_general_section'
        );
        
        add_settings_field(
            'backup_files',
            'Backup Files',
            array($this, 'backup_files_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_general_section'
        );
        
        add_settings_field(
            'backup_database',
            'Backup Database',
            array($this, 'backup_database_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_general_section'
        );
        
        add_settings_field(
            'backup_retention',
            'Backup Retention',
            array($this, 'backup_retention_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_general_section'
        );
        
        add_settings_section(
            'wp_backup_gdrive_google_section',
            'Google Drive Settings',
            array($this, 'google_section_callback'),
            'wp_backup_gdrive_settings'
        );
        
        add_settings_field(
            'google_client_id',
            'Google Client ID',
            array($this, 'google_client_id_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_google_section'
        );
        
        add_settings_field(
            'google_client_secret',
            'Google Client Secret',
            array($this, 'google_client_secret_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_google_section'
        );

        add_settings_field(
            'google_tokens',
            'Google OAuth Tokens',
            array($this, 'google_tokens_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_google_section'
        );

        add_settings_field(
            'google_folder_selection',
            'Google Drive Folder',
            array($this, 'google_folder_selection_callback'),
            'wp_backup_gdrive_settings',
            'wp_backup_gdrive_google_section'
        );
    }
    
    /**
     * Display plugin page
     */
    public function display_plugin_page() {
        ?>
        <div class="wrap">
            <h1>WP Backup to Google Drive</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=wp-backup-gdrive&tab=settings" class="nav-tab <?php echo empty($_GET['tab']) || $_GET['tab'] === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=wp-backup-gdrive&tab=backup" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'backup' ? 'nav-tab-active' : ''; ?>">Backup Now</a>
                <a href="?page=wp-backup-gdrive&tab=logs" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
            </h2>
            
            <div class="tab-content">
                <?php
                $tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
                
                switch ($tab) {
                    case 'backup':
                        $this->display_backup_tab();
                        break;
                    case 'logs':
                        $this->display_logs_tab();
                        break;
                    default:
                        $this->display_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display settings tab
     */
    private function display_settings_tab() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('wp_backup_gdrive_settings');
            do_settings_sections('wp_backup_gdrive_settings');
            submit_button();
            ?>
        </form>
        <?php
    }
    
    /**
     * Display backup tab
     */
    private function display_backup_tab() {
        ?>
        <div class="backup-now-container">
            <h3>Run Manual Backup</h3>
            <p>Click the button below to start a manual backup of your WordPress site.</p>
            
            <div class="backup-options">
                <label>
                    <input type="checkbox" id="backup-files" checked> Backup Files
                </label>
                <label>
                    <input type="checkbox" id="backup-database" checked> Backup Database
                </label>
            </div>
            
            <button id="start-backup" class="button button-primary">Start Backup</button>
            
            <div id="backup-progress" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-bar-fill" style="width: 0%;"></div>
                </div>
                <div id="backup-status">Preparing backup...</div>
            </div>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                $('#start-backup').on('click', function() {
                    var backupFiles = $('#backup-files').is(':checked');
                    var backupDatabase = $('#backup-database').is(':checked');
                    
                    if (!backupFiles && !backupDatabase) {
                        alert('Please select at least one backup option.');
                        return;
                    }
                    
                    $('#backup-progress').show();
                    $(this).prop('disabled', true);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wp_backup_gdrive_manual_backup',
                            backup_files: backupFiles,
                            backup_database: backupDatabase,
                            nonce: '<?php echo wp_create_nonce('wp_backup_gdrive_manual_backup'); ?>'
                        },
                        success: function(response) {
                            $('#backup-status').html('Backup completed successfully!');
                            $('.progress-bar-fill').css('width', '100%');
                            $('#start-backup').prop('disabled', false);
                        },
                        error: function() {
                            $('#backup-status').html('Backup failed. Please check the logs.');
                            $('#start-backup').prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Display logs tab
     */
    private function display_logs_tab() {
        $logs_dir = wp_upload_dir()['basedir'] . '/wp-backup-gdrive/logs';
        $logs = array();
        
        if (file_exists($logs_dir)) {
            $log_files = glob($logs_dir . '/*.log');
            
            foreach ($log_files as $log_file) {
                $logs[] = array(
                    'filename' => basename($log_file),
                    'date' => date('Y-m-d H:i:s', filemtime($log_file)),
                    'size' => size_format(filesize($log_file)),
                    'path' => $log_file
                );
            }
        }
        
        ?>
        <div class="logs-container">
            <h3>Backup Logs</h3>
            
            <?php if (empty($logs)): ?>
                <p>No logs found.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['date']); ?></td>
                                <td><?php echo esc_html($log['filename']); ?></td>
                                <td><?php echo esc_html($log['size']); ?></td>
                                <td>
                                    <a href="#" class="view-log" data-log="<?php echo esc_attr($log['path']); ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div id="log-viewer" style="display: none;">
                    <h3>Log Content</h3>
                    <pre id="log-content"></pre>
                </div>
                
                <script>
                    jQuery(document).ready(function($) {
                        $('.view-log').on('click', function(e) {
                            e.preventDefault();
                            var logFile = $(this).data('log');
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'wp_backup_gdrive_view_log',
                                    log_file: logFile,
                                    nonce: '<?php echo wp_create_nonce('wp_backup_gdrive_view_log'); ?>'
                                },
                                success: function(response) {
                                    $('#log-content').text(response);
                                    $('#log-viewer').show();
                                }
                            });
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * General section callback
     */
    public function general_section_callback() {
        echo '<p>Configure your backup settings below.</p>';
    }
    
    /**
     * Google section callback
     */
    public function google_section_callback() {
        echo '<p>Configure your Google Drive API settings below. You need to create a project in the <a href="https://console.developers.google.com/" target="_blank">Google Developers Console</a> and enable the Google Drive API.</p>';
    }
    
    /**
     * Backup frequency callback
     */
    public function backup_frequency_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $frequency = isset($options['backup_frequency']) ? $options['backup_frequency'] : 'daily';
        ?>
        <select name="wp_backup_gdrive_settings[backup_frequency]">
            <option value="hourly" <?php selected($frequency, 'hourly'); ?>>Hourly</option>
            <option value="twicedaily" <?php selected($frequency, 'twicedaily'); ?>>Twice Daily</option>
            <option value="daily" <?php selected($frequency, 'daily'); ?>>Daily</option>
            <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Weekly</option>
            <option value="monthly" <?php selected($frequency, 'monthly'); ?>>Monthly</option>
        </select>
        <?php
    }
    
    /**
     * Backup files callback
     */
    public function backup_files_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $backup_files = isset($options['backup_files']) ? $options['backup_files'] : 1;
        ?>
        <label>
            <input type="checkbox" name="wp_backup_gdrive_settings[backup_files]" value="1" <?php checked($backup_files, 1); ?>>
            Include WordPress files in backup
        </label>
        <?php
    }
    
    /**
     * Backup database callback
     */
    public function backup_database_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $backup_database = isset($options['backup_database']) ? $options['backup_database'] : 1;
        ?>
        <label>
            <input type="checkbox" name="wp_backup_gdrive_settings[backup_database]" value="1" <?php checked($backup_database, 1); ?>>
            Include WordPress database in backup
        </label>
        <?php
    }
    
    /**
     * Backup retention callback
     */
    public function backup_retention_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $backup_retention = isset($options['backup_retention']) ? $options['backup_retention'] : 5;
        ?>
        <input type="number" name="wp_backup_gdrive_settings[backup_retention]" value="<?php echo esc_attr($backup_retention); ?>" min="1" max="100">
        <p class="description">Number of backups to keep before deleting old ones.</p>
        <?php
    }
    
    /**
     * Google client ID callback
     */
    public function google_client_id_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $client_id = isset($options['google_client_id']) ? $options['google_client_id'] : '';
        ?>
        <input type="text" name="wp_backup_gdrive_settings[google_client_id]" value="<?php echo esc_attr($client_id); ?>" class="regular-text">
        <?php
    }
    
    /**
     * Google client secret callback
     */
    public function google_client_secret_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $client_secret = isset($options['google_client_secret']) ? $options['google_client_secret'] : '';
        ?>
        <input type="password" name="wp_backup_gdrive_settings[google_client_secret]" value="<?php echo esc_attr($client_secret); ?>" class="regular-text">
        <?php
    }
    
    /**
     * Google auth button callback
     */
    public function google_auth_button_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $is_authorized = isset($options['google_access_token']) && !empty($options['google_access_token']);
        
        if ($is_authorized) {
            echo '<p><strong>Status:</strong> <span style="color: green;">Connected to Google Drive</span></p>';
            echo '<button type="button" id="disconnect-google" class="button">Disconnect from Google Drive</button>';
        } else {
            echo '<p><strong>Status:</strong> <span style="color: red;">Not connected to Google Drive</span></p>';
            echo '<button type="button" id="connect-google" class="button button-primary">Connect to Google Drive</button>';
        }
        
        ?>
        <script>
            jQuery(document).ready(function($) {
                $('#connect-google').on('click', function() {
                    var clientId = $('input[name="wp_backup_gdrive_settings[google_client_id]"]').val();
                    var clientSecret = $('input[name="wp_backup_gdrive_settings[google_client_secret]"]').val();
                    
                    if (!clientId || !clientSecret) {
                        alert('Please enter your Google Client ID and Client Secret first.');
                        return;
                    }
                    
                    var authWindow = window.open('<?php echo admin_url('admin-ajax.php?action=wp_backup_gdrive_auth_url'); ?>', 'googleAuth', 'width=600,height=700');
                    
                    if (authWindow) {
                        authWindow.focus();
                    } else {
                        alert('Popup blocked! Please allow popups for this site to connect to Google Drive.');
                    }
                });
                
                $('#disconnect-google').on('click', function() {
                    if (confirm('Are you sure you want to disconnect from Google Drive?')) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wp_backup_gdrive_disconnect',
                                nonce: '<?php echo wp_create_nonce('wp_backup_gdrive_disconnect'); ?>'
                            },
                            success: function(response) {
                                location.reload();
                            }
                        });
                    }
                });
            });
        </script>
        <?php
    }

    // Add the callback method for OAuth Playground token
    /**
     * OAuth Playground token callback
     */
    public function google_tokens_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $refresh_token = isset($options['google_refresh_token']) ? $options['google_refresh_token'] : '';
        $access_token = isset($options['google_access_token']) ? $options['google_access_token'] : '';
        $is_authorized = isset($options['google_refresh_token']) && !empty($options['google_refresh_token']);
        ?>
        <div class="oauth-playground-instructions">
            <p>To get your Google Drive OAuth tokens:</p>
            <ol>
                <li>Go to <a href="https://developers.google.com/oauthplayground" target="_blank">Google OAuth Playground</a></li>
                <li>Click the gear icon (⚙️) in the top right and check "Use your own OAuth credentials"</li>
                <li>Enter your Client ID and Client Secret from above</li>
                <li>In the left panel, find "Drive API v3" and select "https://www.googleapis.com/auth/drive.file"</li>
                <li>Click "Authorize APIs" and complete the authorization</li>
                <li>After authorization, in Step 2, click "Exchange authorization code for tokens"</li>
                <li>Copy both the "Refresh token" and "Access token" from the response</li>
                <li>Paste them in the fields below</li>
            </ol>
        </div>
        
        <div class="token-fields">
            <div class="token-field">
                <label>
                    <strong>Refresh Token</strong> (long-term token)<br>
                    <input type="text" 
                           name="wp_backup_gdrive_settings[google_refresh_token]" 
                           value="<?php echo esc_attr($refresh_token); ?>"
                           class="regular-text" 
                           placeholder="Paste refresh token here"
                           style="width: 100%; font-family: monospace;">
                </label>
            </div>
            
            <div class="token-field">
                <label>
                    <strong>Access Token</strong> (short-term token)<br>
                    <input type="text" 
                           name="wp_backup_gdrive_settings[google_access_token]" 
                           value="<?php echo esc_attr($access_token); ?>"
                           class="regular-text" 
                           placeholder="Paste access token here"
                           style="width: 100%; font-family: monospace;">
                </label>
            </div>
        </div>

        <?php if ($is_authorized): ?>
            <p class="token-status">
                <strong>Status:</strong> <span style="color: green;">✓ Connected to Google Drive</span>
                <button type="button" id="disconnect-google" class="button">Disconnect</button>
            </p>
        <?php endif; ?>
        
        <script>
            jQuery(document).ready(function($) {
                $('#disconnect-google').on('click', function() {
                    if (confirm('Are you sure you want to disconnect from Google Drive?')) {
                        $('input[name="wp_backup_gdrive_settings[google_refresh_token]"]').val('');
                        $('input[name="wp_backup_gdrive_settings[google_access_token]"]').val('');
                        $(this).closest('form').submit();
                    }
                });
            });
        </script>
        <?php
    }

    // Add the callback method for folder selection
    /**
     * Google folder selection callback
     */
    public function google_folder_selection_callback() {
        $options = get_option('wp_backup_gdrive_settings');
        $is_authorized = isset($options['google_refresh_token']) && !empty($options['google_refresh_token']);
        $selected_folder_id = isset($options['google_folder_id']) ? $options['google_folder_id'] : '';
        $selected_folder_name = isset($options['google_folder_name']) ? $options['google_folder_name'] : '';
        
        if (!$is_authorized) {
            echo '<p>Please connect to Google Drive first by entering your tokens above.</p>';
            return;
        }
        
        // Get Google API instance
        $google_api = new WP_Backup_GDrive_Google_API();
        $google_api->init();
        
        // Get folders
        $folders = $google_api->list_drive_folders();
        ?>
        <div class="folder-selection">
            <div class="current-folder">
                <?php if ($selected_folder_id && $selected_folder_name): ?>
                    <p><strong>Current backup folder:</strong> <?php echo esc_html($selected_folder_name); ?></p>
                <?php endif; ?>
            </div>

            <div class="folder-actions">
                <div class="select-folder">
                    <p>Select an existing folder or create a new one:</p>

                    <select id="google-folder-select" class="regular-text">
                        <option value="">-- Select a folder --</option>
                        <?php foreach ($folders as $folder): ?>
                            <option value="<?php echo esc_attr($folder->getId()); ?>" 
                                    <?php selected($selected_folder_id, $folder->getId()); ?>>
                                <?php echo esc_html($folder->getName()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="button" id="select-folder" class="button button-primary">Use Selected Folder</button>
                </div>

                <div class="create-folder" style="margin-top: 15px;">
                    <input type="text" id="new-folder-name" class="regular-text" placeholder="New folder name">
                    <button type="button" id="create-folder" class="button">Create New Folder</button>
                </div>
            </div>
            
            <div id="folder-selection-status"></div>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                // Handle folder selection
                $('#select-folder').on('click', function() {
                    var folderId = $('#google-folder-select').val();
                    var folderName = $('#google-folder-select option:selected').text();
                    
                    if (!folderId) {
                        alert('Please select a folder first.');
                        return;
                    }
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wp_backup_gdrive_select_folder',
                            folder_id: folderId,
                            folder_name: folderName,
                            nonce: '<?php echo wp_create_nonce('wp_backup_gdrive_select_folder'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#folder-selection-status')
                                    .html('<div class="notice notice-success inline"><p>✓ Folder selected successfully</p></div>')
                                    .show();
                                $('.current-folder').html('<p><strong>Current backup folder:</strong> ' + folderName + '</p>');
                            } else {
                                $('#folder-selection-status')
                                    .html('<div class="notice notice-error inline"><p>✗ Error selecting folder</p></div>')
                                    .show();
                            }
                        },
                        error: function() {
                            $('#folder-selection-status')
                                .html('<div class="notice notice-error inline"><p>✗ Error selecting folder</p></div>')
                                .show();
                        }
                    });
                });

                // Handle folder creation
                jQuery(document).ready(function($) {
                    $('#create-folder').on('click', function() {
                        let folderName = $('#new-folder-name').val().trim();
                        let nonce = $(this).data('nonce'); // Get nonce from button

                        if (!folderName) {
                            $('#folder-selection-status')
                                .html('<div class="notice notice-error inline"><p>✗ Please enter a folder name.</p></div>')
                                .show();
                            return;
                        }

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wp_backup_gdrive_create_folder',
                                folder_name: folderName,
                                nonce: '<?php echo wp_create_nonce('wp_backup_gdrive_create_folder'); ?>' // Nonce generated properly
                            },
                            beforeSend: function() {
                                $('#create-folder').prop('disabled', true).text('Creating...');
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Add new folder to select dropdown
                                    $('#google-folder-select').append(
                                        $('<option>', {
                                            value: response.data.id,
                                            text: response.data.name
                                        })
                                    );
                                    $('#new-folder-name').val('');
                                    $('#folder-selection-status')
                                        .html('<div class="notice notice-success inline"><p>✓ Folder created successfully</p></div>')
                                        .show();
                                } else {
                                    $('#folder-selection-status')
                                        .html('<div class="notice notice-error inline"><p>✗ Error creating folder</p></div>')
                                        .show();
                                }
                            },
                            error: function() {
                                $('#folder-selection-status')
                                    .html('<div class="notice notice-error inline"><p>✗ Error creating folder</p></div>')
                                    .show();
                            },
                            complete: function() {
                                $('#create-folder').prop('disabled', false).text('Create New Folder');
                            }
                        });
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Enqueue styles
     */
    public function enqueue_styles($hook) {
        if ('tools_page_wp-backup-gdrive' !== $hook) {
            return;
        }
        
        wp_enqueue_style('wp-backup-gdrive-admin', WP_BACKUP_GDRIVE_PLUGIN_URL . 'assets/css/admin.css', array(), WP_BACKUP_GDRIVE_VERSION);
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if ('tools_page_wp-backup-gdrive' !== $hook) {
            return;
        }
        
        wp_enqueue_script('wp-backup-gdrive-admin', WP_BACKUP_GDRIVE_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WP_BACKUP_GDRIVE_VERSION, true);

        wp_localize_script('wp-backup-gdrive-admin', 'wpBackupGDrive', array(
            'nonce'    => wp_create_nonce('wp_backup_gdrive_manual_backup'),
            'ajaxurl'  => admin_url('admin-ajax.php')
        ));
    }
}

