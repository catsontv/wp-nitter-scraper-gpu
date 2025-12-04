<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nitter_Cron_Handler {
    
    private $database;
    
    public function __construct() {
        // Don't initialize database in constructor to avoid memory issues
        $this->init_hooks();
    }
    
    private function get_database() {
        if (!$this->database) {
            $this->database = nitter_get_database();
        }
        return $this->database;
    }
    
    private function init_hooks() {
        add_action('nitter_cleanup_old_content', array($this, 'cleanup_old_content'));
        add_action('nitter_cleanup_old_logs', array($this, 'cleanup_old_logs'));
        add_action('nitter_auto_scrape_accounts', array($this, 'auto_scrape_accounts'));
        add_action('nitter_process_videos', array($this, 'process_pending_videos'));
    }
    
    public function schedule_events() {
        // Schedule content cleanup daily
        if (!wp_next_scheduled('nitter_cleanup_old_content')) {
            wp_schedule_event(time(), 'daily', 'nitter_cleanup_old_content');
        }
        
        // Schedule log cleanup daily
        if (!wp_next_scheduled('nitter_cleanup_old_logs')) {
            wp_schedule_event(time(), 'daily', 'nitter_cleanup_old_logs');
        }
        
        // Schedule automatic scraping every hour
        if (!wp_next_scheduled('nitter_auto_scrape_accounts')) {
            wp_schedule_event(time(), 'hourly', 'nitter_auto_scrape_accounts');
        }
        
        // Schedule video processing every 5 minutes
        if (!wp_next_scheduled('nitter_process_videos')) {
            wp_schedule_event(time(), 'nitter_five_minutes', 'nitter_process_videos');
        }
    }
    
    public function unschedule_events() {
        $this->clear_scheduled_events();
    }
    
    public function get_next_auto_scrape_time() {
        $next_auto_scrape = wp_next_scheduled('nitter_auto_scrape_accounts');
        return $next_auto_scrape ? date('Y-m-d H:i:s', $next_auto_scrape) : 'Not scheduled';
    }
    
    public function cleanup_old_content() {
        $db = $this->get_database();
        $db->delete_old_content();
        $db->add_log('system', 'Automatic content cleanup completed');
    }
    
    public function cleanup_old_logs() {
        $db = $this->get_database();
        $deleted_count = $db->delete_old_logs();
        if ($deleted_count > 0) {
            $db->add_log('system', "Deleted {$deleted_count} old log entries");
        }
    }
    
    public function auto_scrape_accounts() {
        $db = $this->get_database();
        $db->add_log('system', 'AUTO-SCRAPE TRIGGERED: Starting automatic scraping cycle');
        
        if (!class_exists('Nitter_API')) {
            require_once plugin_dir_path(__FILE__) . 'class-api.php';
        }
        
        $accounts = $db->get_accounts();
        $db->add_log('system', 'AUTO-SCRAPE: Found ' . count($accounts) . ' total accounts');
        
        $api = new Nitter_API();
        $active_count = 0;
        
        foreach ($accounts as $account) {
            if ($account->is_active) {
                $active_count++;
                $db->add_log('scraping', "Auto-scraping account: {$account->account_username}");
                $result = $api->scrape_account($account->id);
                
                if ($result['success']) {
                    $db->add_log('scraping', "Auto-scrape successful: {$account->account_username}");
                } else {
                    $db->add_log('scraping', "Auto-scrape failed: {$account->account_username} - {$result['message']}");
                }
                
                sleep(30);
            }
        }
        
        $db->add_log('system', "AUTO-SCRAPE COMPLETED: Processed {$active_count} active accounts out of " . count($accounts) . " total");
    }
    
    /**
     * Process pending videos (Phase 4 - cron worker)
     * This is called by the WordPress cron hook
     * FIX: Enhanced logging to trace execution
     */
    public function process_pending_videos() {
        $db = $this->get_database();
        
        // LOG: Cron hook fired
        $db->add_log('video_conversion', '>>> CRON HOOK FIRED: nitter_process_videos <<<');
        
        // Check if video processing is enabled
        $enabled = $db->get_setting('enable_video_scraping', '0');
        $db->add_log('video_conversion', "CRON HOOK: Video scraping setting = '{$enabled}'");
        
        if ($enabled !== '1') {
            $db->add_log('video_conversion', 'CRON HOOK: Video processing is DISABLED, exiting');
            return;
        }
        
        // Get video handler and process batch
        $db->add_log('video_conversion', 'CRON HOOK: Getting video handler instance...');
        $video_handler = nitter_get_video_handler();
        
        if (!$video_handler) {
            $db->add_log('video_conversion', 'CRON HOOK: ERROR - video_handler is NULL!');
            return;
        }
        
        $db->add_log('video_conversion', 'CRON HOOK: Video handler retrieved successfully');
        $db->add_log('video_conversion', 'CRON HOOK: Calling process_pending_batch()...');
        
        $video_handler->process_pending_batch();
        
        $db->add_log('video_conversion', 'CRON HOOK: process_pending_batch() completed');
    }
    
    public function clear_scheduled_events() {
        wp_clear_scheduled_hook('nitter_cleanup_old_content');
        wp_clear_scheduled_hook('nitter_cleanup_old_logs');
        wp_clear_scheduled_hook('nitter_auto_scrape_accounts');
        wp_clear_scheduled_hook('nitter_process_videos');
    }
    
    public function manual_cleanup() {
        $this->cleanup_old_content();
        $this->cleanup_old_logs();
        return true;
    }
    
    public function get_next_cleanup_time() {
        $next_content = wp_next_scheduled('nitter_cleanup_old_content');
        $next_logs = wp_next_scheduled('nitter_cleanup_old_logs');
        $next_videos = wp_next_scheduled('nitter_process_videos');
        
        return array(
            'content' => $next_content ? date('Y-m-d H:i:s', $next_content) : 'Not scheduled',
            'logs' => $next_logs ? date('Y-m-d H:i:s', $next_logs) : 'Not scheduled',
            'videos' => $next_videos ? date('Y-m-d H:i:s', $next_videos) : 'Not scheduled'
        );
    }
}