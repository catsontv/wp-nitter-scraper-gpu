<?php
/**
 * PHASE 2 Feature 2.5: System Cron Entry Point for Account Scraping
 * 
 * This file should be called by Windows Task Scheduler instead of relying on WP-Cron.
 * 
 * Command for Windows Task Scheduler:
 * C:\xampp\php\php.exe C:\xampp\htdocs\wp-content\plugins\nitter-scraper\cron-scrape-accounts.php
 * 
 * Schedule: Every 60 minutes (or as configured in plugin settings)
 * 
 * FIXED: Now uses sequential processing with 3-second delays between accounts
 * to prevent Chrome instance overload.
 */

// CRITICAL: Allow unlimited execution time for processing all accounts
set_time_limit(0);
ini_set('max_execution_time', 0);

// Find WordPress installation
$wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

if (!file_exists($wp_load_path)) {
    die('Error: Could not find WordPress installation (wp-load.php)');
}

// Bootstrap WordPress
require_once($wp_load_path);

if (!defined('ABSPATH')) {
    die('Error: WordPress not loaded properly');
}

// Get database and log start
$database = nitter_get_database();
$start_time = time();
$database->add_log('system', '=== SYSTEM CRON: Account scraping started ===');

// Check if system cron is enabled
$use_system_cron = $database->get_setting('use_system_cron', '0');

if ($use_system_cron !== '1') {
    $database->add_log('system', 'SYSTEM CRON: Disabled in settings, exiting');
    exit;
}

// Load API class
if (!class_exists('Nitter_API')) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-api.php';
}

// Get all accounts
$accounts = $database->get_accounts();
$total_accounts = count($accounts);
$database->add_log('system', "SYSTEM CRON: Found {$total_accounts} total accounts");

$api = new Nitter_API();
$active_accounts = array();

// Filter active accounts
foreach ($accounts as $account) {
    if ($account->is_active) {
        $active_accounts[] = $account;
    }
}

$active_count = count($active_accounts);
$database->add_log('system', "SYSTEM CRON: Found {$active_count} active accounts to process");

if (empty($active_accounts)) {
    $database->add_log('system', 'SYSTEM CRON: No active accounts, exiting');
    exit;
}

$success_count = 0;
$fail_count = 0;
$current_num = 0;

// Process each active account SEQUENTIALLY (one at a time)
foreach ($active_accounts as $account) {
    $current_num++;
    $progress = round(($current_num / $active_count) * 100, 1);
    
    $database->add_log('system', "SYSTEM CRON: [{$current_num}/{$active_count} - {$progress}%] Processing {$account->account_username}");
    
    $result = $api->scrape_account($account->id);
    
    if ($result['success']) {
        $success_count++;
        $database->add_log('scrape_image', "✓ System cron success: {$account->account_username}");
    } else {
        $fail_count++;
        $database->add_log('scrape_image', "✗ System cron failed: {$account->account_username} - {$result['message']}");
    }
    
    // Wait 3 seconds between accounts to prevent Chrome overload
    if ($current_num < $active_count) {
        sleep(3);
    }
}

$total_elapsed = time() - $start_time;
$total_minutes = round($total_elapsed / 60, 1);

$database->add_log('system', '=== SYSTEM CRON: Completed ===');
$database->add_log('system', "SYSTEM CRON: Results: {$success_count} successful, {$fail_count} failed out of {$active_count} active accounts");
$database->add_log('system', "SYSTEM CRON: Total time: {$total_minutes} minutes ({$total_elapsed}s)");

exit;
