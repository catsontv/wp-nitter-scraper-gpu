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
     * Get intelligent batch size based on total account count
     * Automatically adjusts to prevent system overload
     * 
     * @param int $account_count Total number of accounts
     * @return array Array with batch_size and delay_between_batches
     */
    private function get_batch_config($account_count) {
        $db = $this->get_database();
        
        // Get user-configured settings (if they exist)
        $user_batch_size = (int) $db->get_setting('auto_scrape_batch_size', '0');
        $user_account_delay = (int) $db->get_setting('auto_scrape_account_delay', '0');
        $user_batch_delay = (int) $db->get_setting('auto_scrape_batch_delay', '0');
        
        // If user has configured custom settings, use those
        if ($user_batch_size > 0 && $user_account_delay > 0 && $user_batch_delay > 0) {
            return array(
                'batch_size' => $user_batch_size,
                'delay_between_accounts' => $user_account_delay,
                'delay_between_batches' => $user_batch_delay
            );
        }
        
        // Otherwise, use intelligent auto-adjustment based on account count
        if ($account_count <= 10) {
            // Very small: Process all at once with minimal delays
            return array(
                'batch_size' => $account_count,
                'delay_between_accounts' => 2,
                'delay_between_batches' => 5
            );
        } elseif ($account_count <= 30) {
            // Small: Conservative batching
            return array(
                'batch_size' => 3,
                'delay_between_accounts' => 3,
                'delay_between_batches' => 10
            );
        } elseif ($account_count <= 60) {
            // Medium: Balanced batching
            return array(
                'batch_size' => 5,
                'delay_between_accounts' => 3,
                'delay_between_batches' => 10
            );
        } elseif ($account_count <= 100) {
            // Large: Larger batches with same safety delays
            return array(
                'batch_size' => 8,
                'delay_between_accounts' => 3,
                'delay_between_batches' => 10
            );
        } else {
            // Very large: Maximum batch size with safety delays
            return array(
                'batch_size' => 10,
                'delay_between_accounts' => 3,
                'delay_between_batches' => 10
            );
        }
    }
    
    /**
     * FIXED: Intelligent sequential batch processing for auto-scrape
     * 
     * CRITICAL CHANGES:
     * - Processes accounts ONE AT A TIME (sequential, not parallel)
     * - Auto-adjusts batch size based on total account count
     * - Adds delays between EACH account (3 seconds) to prevent Chrome overload
     * - Adds delays between batches (10 seconds) to let system recover
     * - Prevents all 123 Chrome instances from launching simultaneously
     * 
     * PERFORMANCE:
     * - 123 accounts: ~10 minutes total (safe and stable)
     * - Previous version: 100% failure due to simultaneous Chrome launches
     * - New version: Processes reliably without system overload
     */
    public function auto_scrape_accounts() {
        $db = $this->get_database();
        $start_time = time();
        $db->add_log('cron', '=== AUTO-SCRAPE STARTED ===' );
        
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
        
        // Get intelligent batch configuration
        $config = $this->get_batch_config($active_count);
        $batch_size = $config['batch_size'];
        $delay_between_accounts = $config['delay_between_accounts'];
        $delay_between_batches = $config['delay_between_batches'];
        
        $batches = array_chunk($active_accounts, $batch_size);
        $batch_count = count($batches);
        
        // Calculate estimated time
        $estimated_time = ($active_count * $delay_between_accounts) + ($batch_count * $delay_between_batches);
        $estimated_minutes = round($estimated_time / 60, 1);
        
        $db->add_log('cron', "AUTO-SCRAPE: Processing {$active_count} accounts in {$batch_count} batches");
        $db->add_log('cron', "AUTO-SCRAPE: Batch size={$batch_size}, Account delay={$delay_between_accounts}s, Batch delay={$delay_between_batches}s");
        $db->add_log('cron', "AUTO-SCRAPE: Estimated completion time: ~{$estimated_minutes} minutes");
        
        $success_count = 0;
        $fail_count = 0;
        $current_account_num = 0;
        
        // Process each batch sequentially
        foreach ($batches as $batch_num => $batch) {
            $batch_label = $batch_num + 1;
            $batch_start_time = time();
            $db->add_log('cron', "AUTO-SCRAPE: Starting batch {$batch_label}/{$batch_count}");
            
            // Process each account in the batch SEQUENTIALLY (one at a time)
            foreach ($batch as $account) {
                $current_account_num++;
                $progress = round(($current_account_num / $active_count) * 100, 1);
                
                $db->add_log('cron', "AUTO-SCRAPE: [{$current_account_num}/{$active_count} - {$progress}%] Processing {$account->account_username}");
                
                // Scrape this account (THIS is where Chrome launches)
                $result = $api->scrape_account($account->id);
                
                if ($result['success']) {
                    $success_count++;
                    $db->add_log('scrape_image', "✓ Auto-scrape successful: {$account->account_username}");
                } else {
                    $fail_count++;
                    $db->add_log('scrape_image', "✗ Auto-scrape failed: {$account->account_username} - {$result['message']}");
                }
                
                // CRITICAL: Wait between EACH account to prevent Chrome overload
                // This ensures only ONE Chrome instance is active at a time
                if ($current_account_num < $active_count) {
                    sleep($delay_between_accounts);
                }
            }
            
            $batch_elapsed = time() - $batch_start_time;
            $db->add_log('cron', "AUTO-SCRAPE: Batch {$batch_label} completed in {$batch_elapsed}s");
            
            // Wait between batches to let system fully recover
            if ($batch_num < $batch_count - 1) {
                $db->add_log('cron', "AUTO-SCRAPE: Pausing {$delay_between_batches}s before next batch...");
                sleep($delay_between_batches);
            }
        }
        
        $total_elapsed = time() - $start_time;
        $total_minutes = round($total_elapsed / 60, 1);
        
        $db->add_log('cron', "=== AUTO-SCRAPE COMPLETED ===");
        $db->add_log('cron', "AUTO-SCRAPE: Results: {$success_count} successful, {$fail_count} failed out of {$active_count} active accounts");
        $db->add_log('cron', "AUTO-SCRAPE: Total time: {$total_minutes} minutes ({$total_elapsed}s)");
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