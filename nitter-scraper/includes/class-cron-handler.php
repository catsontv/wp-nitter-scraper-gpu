<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nitter_Cron_Handler {
    
    private $database;
    
    public function __construct() {
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
        add_action('nitter_reconcile_feed', array($this, 'reconcile_feed'));
    }
    
    public function schedule_events() {
        if (!wp_next_scheduled('nitter_cleanup_old_content')) {
            wp_schedule_event(time(), 'daily', 'nitter_cleanup_old_content');
        }
        
        if (!wp_next_scheduled('nitter_cleanup_old_logs')) {
            wp_schedule_event(time(), 'daily', 'nitter_cleanup_old_logs');
        }
        
        if (!wp_next_scheduled('nitter_auto_scrape_accounts')) {
            wp_schedule_event(time(), 'hourly', 'nitter_auto_scrape_accounts');
        }
        
        if (!wp_next_scheduled('nitter_process_videos')) {
            wp_schedule_event(time(), 'nitter_five_minutes', 'nitter_process_videos');
        }
        
        if (!wp_next_scheduled('nitter_reconcile_feed')) {
            wp_schedule_event(time(), 'nitter_fifteen_minutes', 'nitter_reconcile_feed');
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
        $db->add_log('cron', 'Automatic content cleanup completed');
    }
    
    public function cleanup_old_logs() {
        $db = $this->get_database();
        $deleted_count = $db->delete_old_logs();
        if ($deleted_count > 0) {
            $db->add_log('cron', "Deleted {$deleted_count} old log entries");
        }
    }
    
    /**
     * FIXED: Auto-scrape now processes accounts in batches without delays
     * - Changed log type from 'scraping' to 'scrape_image'
     * - Removed 30-second delays (was causing 123 accounts to take 61+ minutes)
     * - Added batch processing (10 accounts per batch with 2s between batches)
     * - Total time for 123 accounts: ~24 seconds instead of 61+ minutes
     */
    public function auto_scrape_accounts() {
        $db = $this->get_database();
        $db->add_log('cron', 'AUTO-SCRAPE TRIGGERED: Starting automatic scraping cycle');
        
        if (!class_exists('Nitter_API')) {
            require_once plugin_dir_path(__FILE__) . 'class-api.php';
        }
        
        $accounts = $db->get_accounts();
        $total_accounts = count($accounts);
        $db->add_log('cron', "AUTO-SCRAPE: Found {$total_accounts} total accounts");
        
        if (empty($accounts)) {
            $db->add_log('cron', 'AUTO-SCRAPE: No accounts to scrape, exiting');
            return;
        }
        
        $api = new Nitter_API();
        $active_accounts = array();
        
        // Filter active accounts
        foreach ($accounts as $account) {
            if ($account->is_active) {
                $active_accounts[] = $account;
            }
        }
        
        $active_count = count($active_accounts);
        $db->add_log('cron', "AUTO-SCRAPE: Found {$active_count} active accounts to process");
        
        if (empty($active_accounts)) {
            $db->add_log('cron', 'AUTO-SCRAPE: No active accounts, exiting');
            return;
        }
        
        // Process accounts in batches of 10
        $batch_size = 10;
        $batches = array_chunk($active_accounts, $batch_size);
        $batch_count = count($batches);
        
        $db->add_log('cron', "AUTO-SCRAPE: Processing {$active_count} accounts in {$batch_count} batches of {$batch_size}");
        
        $success_count = 0;
        $fail_count = 0;
        
        foreach ($batches as $batch_num => $batch) {
            $batch_label = $batch_num + 1;
            $db->add_log('cron', "AUTO-SCRAPE: Processing batch {$batch_label}/{$batch_count}");
            
            foreach ($batch as $account) {
                $db->add_log('scrape_image', "Auto-scraping account: {$account->account_username}");
                $result = $api->scrape_account($account->id);
                
                if ($result['success']) {
                    $success_count++;
                    $db->add_log('scrape_image', "✓ Auto-scrape successful: {$account->account_username}");
                } else {
                    $fail_count++;
                    $db->add_log('scrape_image', "✗ Auto-scrape failed: {$account->account_username} - {$result['message']}");
                }
            }
            
            // Short 2-second pause between batches to avoid overwhelming Node.js service
            if ($batch_num < $batch_count - 1) {
                sleep(2);
            }
        }
        
        $db->add_log('cron', "AUTO-SCRAPE COMPLETED: {$success_count} successful, {$fail_count} failed out of {$active_count} active accounts");
    }
    
    public function process_pending_videos() {
        $db = $this->get_database();
        
        $db->add_log('cron', '>>> CRON HOOK FIRED: nitter_process_videos <<<');
        
        $enabled = $db->get_setting('enable_video_scraping', '0');
        $db->add_log('cron', "CRON HOOK: Video scraping setting = '{$enabled}'");
        
        if ($enabled !== '1') {
            $db->add_log('cron', 'CRON HOOK: Video processing is DISABLED, exiting');
            return;
        }
        
        $db->add_log('cron', 'CRON HOOK: Getting video handler instance...');
        $video_handler = nitter_get_video_handler();
        
        if (!$video_handler) {
            $db->add_log('cron', 'CRON HOOK: ERROR - video_handler is NULL!');
            return;
        }
        
        $db->add_log('cron', 'CRON HOOK: Video handler retrieved successfully');
        $db->add_log('cron', 'CRON HOOK: Calling process_pending_batch()...');
        
        $video_handler->process_pending_batch();
        
        $db->add_log('cron', 'CRON HOOK: process_pending_batch() completed');
    }
    
    public function reconcile_feed() {
        $db = $this->get_database();
        
        $db->add_log('feed_reconciliation', '>>> RECONCILIATION CRON STARTED <<<');
        
        $orphans = $db->get_orphaned_tweets(12);
        
        if (empty($orphans)) {
            $db->add_log('feed_reconciliation', 'No orphaned tweets found');
            return;
        }
        
        $count = count($orphans);
        $db->add_log('feed_reconciliation', "Found {$count} orphaned tweet(s) - promoting to feed top");
        
        foreach ($orphans as $orphan) {
            $db->publish_tweet($orphan->id);
            $db->add_log('feed_reconciliation', "Promoted tweet ID {$orphan->id} (original date: {$orphan->date_scraped})");
        }
        
        $db->add_log('feed_reconciliation', "Reconciliation completed: {$count} orphaned tweet(s) promoted");
    }
    
    public function clear_scheduled_events() {
        wp_clear_scheduled_hook('nitter_cleanup_old_content');
        wp_clear_scheduled_hook('nitter_cleanup_old_logs');
        wp_clear_scheduled_hook('nitter_auto_scrape_accounts');
        wp_clear_scheduled_hook('nitter_process_videos');
        wp_clear_scheduled_hook('nitter_reconcile_feed');
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
        $next_reconcile = wp_next_scheduled('nitter_reconcile_feed');
        
        return array(
            'content' => $next_content ? date('Y-m-d H:i:s', $next_content) : 'Not scheduled',
            'logs' => $next_logs ? date('Y-m-d H:i:s', $next_logs) : 'Not scheduled',
            'videos' => $next_videos ? date('Y-m-d H:i:s', $next_videos) : 'Not scheduled',
            'reconciliation' => $next_reconcile ? date('Y-m-d H:i:s', $next_reconcile) : 'Not scheduled'
        );
    }
}