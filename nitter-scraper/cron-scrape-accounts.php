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
 */

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
$database->add_log('system', 'SYSTEM CRON: Found ' . count($accounts) . ' total accounts');

$api = new Nitter_API();
$active_count = 0;
$success_count = 0;
$fail_count = 0;

// Scrape each active account
foreach ($accounts as $account) {
    if ($account->is_active) {
        $active_count++;
        $database->add_log('scrape_image', "SYSTEM CRON: Scraping account {$account->account_username}");
        
        $result = $api->scrape_account($account->id);
        
        if ($result['success']) {
            $success_count++;
            $database->add_log('scrape_image', "SYSTEM CRON: Success - {$account->account_username}");
        } else {
            $fail_count++;
            $database->add_log('scrape_image', "SYSTEM CRON: Failed - {$account->account_username}: {$result['message']}");
        }
        
        // Sleep 30 seconds between accounts to avoid rate limiting
        sleep(30);
    }
}

$database->add_log('system', "=== SYSTEM CRON: Completed - {$success_count} success, {$fail_count} failed out of {$active_count} active accounts ===");

exit;
