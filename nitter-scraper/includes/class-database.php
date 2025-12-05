<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nitter_Database {
    
    private $wpdb;
    private $db_version = '1.2.0';
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    public function create_tables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        
        $accounts_table = $this->wpdb->prefix . 'nitter_accounts';
        $accounts_sql = "CREATE TABLE $accounts_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            account_url varchar(255) NOT NULL,
            account_username varchar(100) NOT NULL,
            retention_days int(11) NOT NULL DEFAULT 30,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            date_added datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_scraped datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY account_username (account_username)
        ) $charset_collate;";
        
        $tweets_table = $this->wpdb->prefix . 'nitter_tweets';
        $tweets_sql = "CREATE TABLE $tweets_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            account_id int(11) NOT NULL,
            tweet_id varchar(100) NOT NULL,
            tweet_text text,
            tweet_date datetime NOT NULL,
            images_count int(11) NOT NULL DEFAULT 0,
            date_scraped datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            feed_status enum('published','pending') NOT NULL DEFAULT 'published',
            date_published datetime NULL,
            in_feed tinyint(1) NOT NULL DEFAULT 0,
            last_feed_check datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tweet_id (tweet_id),
            KEY account_id (account_id),
            KEY feed_status (feed_status),
            KEY date_published (date_published),
            KEY in_feed (in_feed)
        ) $charset_collate;";
        
        $images_table = $this->wpdb->prefix . 'nitter_images';
        $images_sql = "CREATE TABLE $images_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            tweet_id int(11) NOT NULL,
            image_url varchar(500) NOT NULL,
            wordpress_attachment_id int(11) NULL,
            media_type enum('image','gif','video_failed') NOT NULL DEFAULT 'image',
            imgbb_url varchar(500) NULL,
            imgbb_delete_url varchar(500) NULL,
            original_video_url varchar(500) NULL,
            video_duration int(11) NULL COMMENT 'Duration in seconds',
            conversion_attempts int(11) NOT NULL DEFAULT 0,
            conversion_status enum('pending','processing','completed','failed','skipped','skipped_too_complex') NULL,
            file_size_bytes bigint(20) NULL,
            date_saved datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tweet_id (tweet_id),
            KEY media_type (media_type),
            KEY conversion_status (conversion_status)
        ) $charset_collate;";
        
        $instances_table = $this->wpdb->prefix . 'nitter_instances';
        $instances_sql = "CREATE TABLE $instances_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            instance_url varchar(255) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            last_used datetime NULL,
            response_time int(11) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY instance_url (instance_url)
        ) $charset_collate;";
        
        $logs_table = $this->wpdb->prefix . 'nitter_logs';
        $logs_sql = "CREATE TABLE $logs_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            log_type enum('scrape_image','scrape_video','conversion','upload','cron','system','feed','feed_reconciliation','other') NOT NULL DEFAULT 'other',
            message text NOT NULL,
            date_created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY log_type (log_type),
            KEY date_created (date_created)
        ) $charset_collate;";
        
        $settings_table = $this->wpdb->prefix . 'nitter_settings';
        $settings_sql = "CREATE TABLE $settings_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            setting_key varchar(100) NOT NULL,
            setting_value text NULL,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($accounts_sql);
        dbDelta($tweets_sql);
        dbDelta($images_sql);
        dbDelta($instances_sql);
        dbDelta($logs_sql);
        dbDelta($settings_sql);
        
        $this->run_phase1_migrations();
        $this->run_phase2_migrations();
        
        // DO NOT insert default instances - user must add their own
        
        $this->insert_default_settings();
    }
    
    private function run_phase1_migrations() {
        $tweets_table = $this->wpdb->prefix . 'nitter_tweets';
        
        $columns = $this->wpdb->get_results("SHOW COLUMNS FROM $tweets_table");
        $column_names = array();
        foreach ($columns as $column) {
            $column_names[] = $column->Field;
        }
        
        if (!in_array('feed_status', $column_names)) {
            $this->wpdb->query("ALTER TABLE $tweets_table ADD COLUMN feed_status enum('published','pending') NOT NULL DEFAULT 'published' AFTER date_scraped");
            $this->add_log('system', 'Phase 1: Added feed_status column');
        }
        
        if (!in_array('date_published', $column_names)) {
            $this->wpdb->query("ALTER TABLE $tweets_table ADD COLUMN date_published datetime NULL AFTER feed_status");
            $this->add_log('system', 'Phase 1: Added date_published column');
        }
        
        if (!in_array('in_feed', $column_names)) {
            $this->wpdb->query("ALTER TABLE $tweets_table ADD COLUMN in_feed tinyint(1) NOT NULL DEFAULT 0 AFTER date_published");
            $this->add_log('system', 'Phase 1: Added in_feed column');
        }
        
        if (!in_array('last_feed_check', $column_names)) {
            $this->wpdb->query("ALTER TABLE $tweets_table ADD COLUMN last_feed_check datetime NULL AFTER in_feed");
            $this->add_log('system', 'Phase 1: Added last_feed_check column');
        }
        
        $backfill_count = $this->wpdb->query(
            "UPDATE $tweets_table SET date_published = date_scraped WHERE date_published IS NULL AND feed_status = 'published'"
        );
        
        if ($backfill_count > 0) {
            $this->add_log('system', "Phase 1: Backfilled date_published for $backfill_count existing tweets");
        }
    }
    
    private function run_phase2_migrations() {
        $logs_table = $this->wpdb->prefix . 'nitter_logs';
        $images_table = $this->wpdb->prefix . 'nitter_images';
        
        $log_columns = $this->wpdb->get_results("SHOW COLUMNS FROM $logs_table WHERE Field = 'log_type'");
        if (empty($log_columns)) {
            $this->wpdb->query("ALTER TABLE $logs_table ADD COLUMN log_type enum('scrape_image','scrape_video','conversion','upload','cron','system','feed','feed_reconciliation','other') NOT NULL DEFAULT 'other' AFTER id");
            $this->add_log('system', 'Phase 2: Added log_type column to logs table');
        } else {
            $current_type = $log_columns[0]->Type;
            if (strpos($current_type, 'feed_reconciliation') === false) {
                $this->wpdb->query("ALTER TABLE $logs_table MODIFY COLUMN log_type enum('scrape_image','scrape_video','conversion','upload','cron','system','feed','feed_reconciliation','other') NOT NULL DEFAULT 'other'");
                $this->add_log('system', 'Phase 2: Updated log_type enum values');
            }
        }
        
        $image_columns = $this->wpdb->get_results("SHOW COLUMNS FROM $images_table WHERE Field = 'conversion_status'");
        if (!empty($image_columns)) {
            $current_type = $image_columns[0]->Type;
            if (strpos($current_type, 'skipped_too_complex') === false) {
                $this->wpdb->query("ALTER TABLE $images_table MODIFY COLUMN conversion_status enum('pending','processing','completed','failed','skipped','skipped_too_complex') NULL");
                $this->add_log('system', 'Phase 2: Updated conversion_status enum to include skipped_too_complex');
            }
        }
    }
    
    private function insert_default_settings() {
        $default_settings = array(
            'enable_video_scraping' => '0',
            'ytdlp_path' => 'C:\\Users\\destro\\bin\\yt-dlp.exe',
            'ffmpeg_path' => 'C:\\Users\\destro\\bin\\ffmpeg.exe',
            'ffprobe_path' => 'C:\\Users\\destro\\bin\\ffprobe.exe',
            'max_video_duration' => '90',
            'max_gif_size_mb' => '20',
            'imgbb_api_key' => '',
            'auto_delete_local_files' => '1',
            'temp_folder_path' => 'C:\\xampp\\htdocs\\wp-content\\uploads\\nitter-temp',
            'parallel_video_count' => '5',
            'first_pass_abort_threshold_mb' => '70',
            'log_retention_days' => '7',
            'use_system_cron' => '1',
            'system_cron_interval' => '60'
        );
        
        $settings_table = $this->wpdb->prefix . 'nitter_settings';
        
        $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '$settings_table'") === $settings_table;
        if (!$table_exists) {
            return;
        }
        
        foreach ($default_settings as $key => $value) {
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT IGNORE INTO $settings_table (setting_key, setting_value) VALUES (%s, %s)",
                $key,
                $value
            ));
        }
    }
    
    public function get_setting($key, $default = null) {
        $table = $this->wpdb->prefix . 'nitter_settings';
        
        $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            return $default;
        }
        
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT setting_value FROM $table WHERE setting_key = %s",
            $key
        ));
        
        return $value !== null ? $value : $default;
    }
    
    public function update_setting($key, $value, $log = true) {
        $table = $this->wpdb->prefix . 'nitter_settings';
        
        $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            return false;
        }
        
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM $table WHERE setting_key = %s",
            $key
        ));
        
        if ($existing) {
            $result = $this->wpdb->update(
                $table,
                array('setting_value' => $value),
                array('setting_key' => $key),
                array('%s'),
                array('%s')
            );
        } else {
            $result = $this->wpdb->insert(
                $table,
                array(
                    'setting_key' => $key,
                    'setting_value' => $value
                ),
                array('%s', '%s')
            );
        }
        
        if ($result !== false && $log) {
            $this->add_log('system', "Setting updated: $key");
        }
        
        return $result;
    }
    
    public function get_all_settings() {
        $table = $this->wpdb->prefix . 'nitter_settings';
        
        $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            return array();
        }
        
        $results = $this->wpdb->get_results("SELECT setting_key, setting_value FROM $table");
        
        $settings = array();
        if ($results) {
            foreach ($results as $row) {
                $settings[$row->setting_key] = $row->setting_value;
            }
        }
        
        return $settings;
    }
    
    public function add_account($account_url, $account_username, $retention_days) {
        $table = $this->wpdb->prefix . 'nitter_accounts';
        
        $result = $this->wpdb->insert(
            $table,
            array(
                'account_url' => $account_url,
                'account_username' => $account_username,
                'retention_days' => $retention_days
            ),
            array('%s', '%s', '%d')
        );
        
        if ($result) {
            $this->add_log('system', "Account added: $account_username");
            return $this->wpdb->insert_id;
        }
        
        return false;
    }
    
    public function bulk_add_accounts($accounts_array) {
        $imported = 0;
        $duplicates = 0;
        $invalid = 0;
        
        foreach ($accounts_array as $account_data) {
            if (empty($account_data['username'])) {
                $invalid++;
                continue;
            }
            
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}nitter_accounts WHERE account_username = %s",
                $account_data['username']
            ));
            
            if ($exists) {
                $duplicates++;
                continue;
            }
            
            $url = 'https://twitter.com/' . $account_data['username'];
            $retention = isset($account_data['retention_days']) ? intval($account_data['retention_days']) : 30;
            
            $result = $this->add_account($url, $account_data['username'], $retention);
            
            if ($result) {
                $imported++;
            } else {
                $invalid++;
            }
        }
        
        return array(
            'imported' => $imported,
            'duplicates' => $duplicates,
            'invalid' => $invalid
        );
    }
    
    public function get_accounts() {
        $table = $this->wpdb->prefix . 'nitter_accounts';
        return $this->wpdb->get_results("SELECT * FROM $table ORDER BY date_added DESC");
    }
    
    public function get_account($id) {
        $table = $this->wpdb->prefix . 'nitter_accounts';
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }
    
    public function update_account_status($id, $is_active) {
        $table = $this->wpdb->prefix . 'nitter_accounts';
        return $this->wpdb->update(
            $table,
            array('is_active' => $is_active),
            array('id' => $id),
            array('%d'),
            array('%d')
        );
    }
    
    public function delete_account($id) {
        $account = $this->get_account($id);
        if (!$account) return false;
        
        $this->delete_account_data($id);
        
        $table = $this->wpdb->prefix . 'nitter_accounts';
        $result = $this->wpdb->delete($table, array('id' => $id), array('%d'));
        
        if ($result) {
            $this->add_log('system', "Account deleted: " . $account->account_username);
        }
        
        return $result;
    }
    
    public function update_last_scraped($account_id) {
        $table = $this->wpdb->prefix . 'nitter_accounts';
        return $this->wpdb->update(
            $table,
            array('last_scraped' => current_time('mysql')),
            array('id' => $account_id),
            array('%s'),
            array('%d')
        );
    }
    
    public function add_tweet($account_id, $tweet_id, $tweet_text, $tweet_date, $images_count = 0, $feed_status = 'published') {
        $table = $this->wpdb->prefix . 'nitter_tweets';
        
        $data = array(
            'account_id' => $account_id,
            'tweet_id' => $tweet_id,
            'tweet_text' => $tweet_text,
            'tweet_date' => $tweet_date,
            'images_count' => $images_count,
            'feed_status' => $feed_status
        );
        
        if ($feed_status === 'published') {
            $data['date_published'] = current_time('mysql');
        }
        
        return $this->wpdb->insert(
            $table,
            $data,
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );
    }
    
    public function publish_tweet($tweet_id) {
        $table = $this->wpdb->prefix . 'nitter_tweets';
        
        $result = $this->wpdb->update(
            $table,
            array(
                'feed_status' => 'published',
                'date_published' => current_time('mysql')
            ),
            array('id' => $tweet_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result) {
            $this->add_log('feed', "Tweet published to feed: ID $tweet_id");
        }
        
        return $result;
    }
    
    public function mark_tweets_in_feed($tweet_ids) {
        if (empty($tweet_ids)) {
            return false;
        }
        
        $table = $this->wpdb->prefix . 'nitter_tweets';
        $ids_placeholder = implode(',', array_fill(0, count($tweet_ids), '%d'));
        
        $sql = "UPDATE $table SET in_feed = 1, last_feed_check = NOW() WHERE id IN ($ids_placeholder)";
        
        return $this->wpdb->query($this->wpdb->prepare($sql, $tweet_ids));
    }
    
    public function get_orphaned_tweets($hours = 12) {
        $table = $this->wpdb->prefix . 'nitter_tweets';
        
        $cutoff = date('Y-m-d H:i:s', strtotime("-$hours hours"));
        
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table 
             WHERE feed_status = 'published' 
             AND date_scraped >= %s 
             AND in_feed = 0",
            $cutoff
        ));
    }
    
    public function get_tweets($account_id = null, $limit = 50, $offset = 0) {
        $tweets_table = $this->wpdb->prefix . 'nitter_tweets';
        $accounts_table = $this->wpdb->prefix . 'nitter_accounts';
        
        $where = "WHERE t.feed_status = 'published'";
        
        if ($account_id) {
            $where .= $this->wpdb->prepare(" AND t.account_id = %d", $account_id);
        }
        
        $sql = "SELECT t.*, a.account_username 
                FROM $tweets_table t 
                LEFT JOIN $accounts_table a ON t.account_id = a.id 
                $where 
                ORDER BY COALESCE(t.date_published, t.date_scraped) DESC 
                LIMIT %d OFFSET %d";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $limit, $offset));
    }
    
    public function get_tweet_images($tweet_id) {
        $table = $this->wpdb->prefix . 'nitter_images';
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE tweet_id = %d",
            $tweet_id
        ));
    }
    
    public function add_image($tweet_id, $image_url, $attachment_id = null) {
        $table = $this->wpdb->prefix . 'nitter_images';
        
        return $this->wpdb->insert(
            $table,
            array(
                'tweet_id' => $tweet_id,
                'image_url' => $image_url,
                'wordpress_attachment_id' => $attachment_id,
                'media_type' => 'image'
            ),
            array('%d', '%s', '%d', '%s')
        );
    }
    
    public function add_video_entry($tweet_id, $video_url) {
        $table = $this->wpdb->prefix . 'nitter_images';
        
        return $this->wpdb->insert(
            $table,
            array(
                'tweet_id' => $tweet_id,
                'image_url' => '',
                'original_video_url' => $video_url,
                'media_type' => 'gif',
                'conversion_status' => 'pending',
                'conversion_attempts' => 0
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d')
        );
    }
    
    public function update_video_conversion_status($id, $status, $error_message = null) {
        $table = $this->wpdb->prefix . 'nitter_images';
        
        $sql = $this->wpdb->prepare(
            "UPDATE $table SET conversion_status = %s, conversion_attempts = conversion_attempts + 1 WHERE id = %d",
            $status,
            $id
        );
        
        $result = $this->wpdb->query($sql);
        
        if ($error_message) {
            $this->add_log('conversion', "Video conversion $status (ID: $id): $error_message");
        }
        
        return $result;
    }
    
    public function update_gif_data($id, $imgbb_url, $imgbb_delete_url, $file_size, $duration) {
        $table = $this->wpdb->prefix . 'nitter_images';
        
        return $this->wpdb->update(
            $table,
            array(
                'imgbb_url' => $imgbb_url,
                'imgbb_delete_url' => $imgbb_delete_url,
                'file_size_bytes' => $file_size,
                'video_duration' => $duration,
                'conversion_status' => 'completed',
                'media_type' => 'gif'
            ),
            array('id' => $id),
            array('%s', '%s', '%d', '%d', '%s', '%s'),
            array('%d')
        );
    }
    
    public function get_pending_videos($limit = 5) {
        $table = $this->wpdb->prefix . 'nitter_images';
        
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table WHERE conversion_status = 'pending' AND media_type = 'gif' ORDER BY date_saved ASC LIMIT %d",
            $limit
        ));
    }
    
    public function get_active_instances() {
        $table = $this->wpdb->prefix . 'nitter_instances';
        return $this->wpdb->get_results("SELECT * FROM $table WHERE is_active = 1 ORDER BY last_used ASC");
    }
    
    public function update_instance_usage($id, $response_time = null) {
        $table = $this->wpdb->prefix . 'nitter_instances';
        return $this->wpdb->update(
            $table,
            array(
                'last_used' => current_time('mysql'),
                'response_time' => $response_time
            ),
            array('id' => $id),
            array('%s', '%d'),
            array('%d')
        );
    }
    
    public function add_log($type, $message) {
        $table = $this->wpdb->prefix . 'nitter_logs';
        
        $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            return false;
        }
        
        return $this->wpdb->insert(
            $table,
            array(
                'log_type' => $type,
                'message' => $message
            ),
            array('%s', '%s')
        );
    }
    
    public function get_logs($limit = 100, $log_type = null) {
        $table = $this->wpdb->prefix . 'nitter_logs';
        
        if ($log_type && $log_type !== 'all') {
            return $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT * FROM $table WHERE log_type = %s ORDER BY date_created DESC LIMIT %d",
                $log_type,
                $limit
            ));
        }
        
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table ORDER BY date_created DESC LIMIT %d",
            $limit
        ));
    }
    
    public function clear_logs() {
        $table = $this->wpdb->prefix . 'nitter_logs';
        $result = $this->wpdb->query("TRUNCATE TABLE $table");
        
        if ($result !== false) {
            $this->add_log('system', 'All logs cleared manually');
        }
        
        return $result;
    }
    
    public function delete_old_logs() {
        $table = $this->wpdb->prefix . 'nitter_logs';
        $retention_days = intval($this->get_setting('log_retention_days', 7));
        
        if ($retention_days === 0) {
            return 0;
        }
        
        $date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
        
        $result = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM $table WHERE date_created < %s",
            $date
        ));
        
        return $result;
    }
    
    public function delete_account_data($account_id) {
        $tweets_table = $this->wpdb->prefix . 'nitter_tweets';
        $images_table = $this->wpdb->prefix . 'nitter_images';
        
        $tweets = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id FROM $tweets_table WHERE account_id = %d",
            $account_id
        ));
        
        if ($tweets) {
            foreach ($tweets as $tweet) {
                $images = $this->get_tweet_images($tweet->id);
                if ($images) {
                    foreach ($images as $image) {
                        if ($image->wordpress_attachment_id) {
                            wp_delete_attachment($image->wordpress_attachment_id, true);
                        }
                    }
                }
                
                $this->wpdb->delete($images_table, array('tweet_id' => $tweet->id), array('%d'));
            }
        }
        
        $this->wpdb->delete($tweets_table, array('account_id' => $account_id), array('%d'));
    }
    
    public function delete_old_content() {
        $accounts = $this->get_accounts();
        
        if (!$accounts) {
            return;
        }
        
        foreach ($accounts as $account) {
            $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$account->retention_days} days"));
            
            $tweets_table = $this->wpdb->prefix . 'nitter_tweets';
            $images_table = $this->wpdb->prefix . 'nitter_images';
            
            $old_tweets = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id FROM $tweets_table WHERE account_id = %d AND tweet_date < %s",
                $account->id,
                $cutoff_date
            ));
            
            if ($old_tweets) {
                foreach ($old_tweets as $tweet) {
                    $images = $this->get_tweet_images($tweet->id);
                    if ($images) {
                        foreach ($images as $image) {
                            if ($image->wordpress_attachment_id) {
                                wp_delete_attachment($image->wordpress_attachment_id, true);
                            }
                        }
                    }
                    
                    $this->wpdb->delete($images_table, array('tweet_id' => $tweet->id), array('%d'));
                }
            }
            
            $deleted_count = $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM $tweets_table WHERE account_id = %d AND tweet_date < %s",
                $account->id,
                $cutoff_date
            ));
            
            if ($deleted_count > 0) {
                $this->add_log('cron', "Deleted $deleted_count old tweets for account: {$account->account_username}");
            }
        }
    }
    
    public function delete_all_content() {
        $tweets_table = $this->wpdb->prefix . 'nitter_tweets';
        $images_table = $this->wpdb->prefix . 'nitter_images';
        
        $images = $this->wpdb->get_results("SELECT wordpress_attachment_id FROM $images_table WHERE wordpress_attachment_id IS NOT NULL");
        if ($images) {
            foreach ($images as $image) {
                wp_delete_attachment($image->wordpress_attachment_id, true);
            }
        }
        
        $this->wpdb->query("TRUNCATE TABLE $images_table");
        $this->wpdb->query("TRUNCATE TABLE $tweets_table");
        
        $this->add_log('system', 'All content deleted manually');
        
        return true;
    }

    public function get_last_insert_id() {
        return $this->wpdb->insert_id;
    }
}