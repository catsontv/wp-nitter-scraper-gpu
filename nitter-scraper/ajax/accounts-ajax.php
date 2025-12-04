<?php
if (!defined('ABSPATH')) {
    exit;
}

// Test auto-scraping
add_action('wp_ajax_nitter_test_auto_scrape', 'nitter_handle_test_auto_scrape');

function nitter_handle_test_auto_scrape() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $cron_handler = new Nitter_Cron_Handler();
    
    try {
        // Call the auto-scraping method directly
        $cron_handler->auto_scrape_accounts();
        wp_send_json_success('Auto-scraping test completed successfully');
    } catch (Exception $e) {
        wp_send_json_error('Auto-scraping failed: ' . $e->getMessage());
    }
}

// Handle scraped data from Node.js service
add_action('wp_ajax_nitter_receive_scraped_data', 'nitter_handle_receive_scraped_data');
add_action('wp_ajax_nopriv_nitter_receive_scraped_data', 'nitter_handle_receive_scraped_data');

function nitter_handle_receive_scraped_data() {
    // Skip nonce check for external service callbacks
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $api = new Nitter_API();
    $result = $api->receive_scraped_data($data);
    
    wp_send_json($result);
}



// Add account
add_action('wp_ajax_nitter_add_account', 'nitter_handle_add_account');
function nitter_handle_add_account() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $account_url = sanitize_url($_POST['account_url']);
    $retention_days = intval($_POST['retention_days']);
    
    if (empty($account_url)) {
        wp_send_json_error('Account URL is required');
    }
    
    if ($retention_days < 1 || $retention_days > 365) {
        wp_send_json_error('Retention days must be between 1 and 365');
    }
    
    $database = new Nitter_Database();
    $api = new Nitter_API();
    
    $username = $api->extract_username_from_url($account_url);
    if (!$username) {
        wp_send_json_error('Invalid account URL format');
    }
    
    $result = $database->add_account($account_url, $username, $retention_days);
    
    if ($result) {
        wp_send_json_success('Account added successfully');
    } else {
        wp_send_json_error('Failed to add account. Account may already exist.');
    }
}

// Toggle account status
add_action('wp_ajax_nitter_toggle_account', 'nitter_handle_toggle_account');
function nitter_handle_toggle_account() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $account_id = intval($_POST['account_id']);
    $status = sanitize_text_field($_POST['status']);
    
    if (!$account_id) {
        wp_send_json_error('Invalid account ID');
    }
    
    $is_active = ($status === 'active') ? 1 : 0;
    
    $database = new Nitter_Database();
    $result = $database->update_account_status($account_id, $is_active);
    
    if ($result !== false) {
        $account = $database->get_account($account_id);
        $status_text = $is_active ? 'enabled' : 'disabled';
        $database->add_log('account', "Account {$status_text}: " . $account->account_username);
        wp_send_json_success("Account {$status_text} successfully");
    } else {
        wp_send_json_error('Failed to update account status');
    }
}

// Delete account
add_action('wp_ajax_nitter_delete_account', 'nitter_handle_delete_account');
function nitter_handle_delete_account() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $account_id = intval($_POST['account_id']);
    
    if (!$account_id) {
        wp_send_json_error('Invalid account ID');
    }
    
    $database = new Nitter_Database();
    $result = $database->delete_account($account_id);
    
    if ($result) {
        wp_send_json_success('Account deleted successfully');
    } else {
        wp_send_json_error('Failed to delete account');
    }
}

// Manual scrape account
add_action('wp_ajax_nitter_scrape_account', 'nitter_handle_scrape_account');
function nitter_handle_scrape_account() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $account_id = intval($_POST['account_id']);
    
    if (!$account_id) {
        wp_send_json_error('Invalid account ID');
    }
    
    $database = new Nitter_Database();
    $account = $database->get_account($account_id);
    
    if (!$account) {
        wp_send_json_error('Account not found');
    }
    
    if (!$account->is_active) {
        wp_send_json_error('Account is disabled');
    }
    
    $api = new Nitter_API();
    $result = $api->scrape_account($account_id);
    
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

// Test service
add_action('wp_ajax_nitter_test_service', 'nitter_handle_test_service');
function nitter_handle_test_service() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $api = new Nitter_API();
    $result = $api->test_service();
    
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

// Test instance
add_action('wp_ajax_nitter_test_instance', 'nitter_handle_test_instance');
function nitter_handle_test_instance() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $instance_url = sanitize_url($_POST['instance_url']);
    
    if (empty($instance_url)) {
        wp_send_json_error('Instance URL is required');
    }
    
    $api = new Nitter_API();
    $result = $api->test_instance($instance_url);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message']);
    }
}

// Test API
add_action('wp_ajax_nitter_test_api', 'nitter_handle_test_api');
function nitter_handle_test_api() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $api = new Nitter_API();
    $result = $api->test_service();
    
    if ($result['success']) {
        wp_send_json_success('API connection successful');
    } else {
        wp_send_json_error($result['message']);
    }
}

// Manual cleanup
add_action('wp_ajax_nitter_manual_cleanup', 'nitter_handle_manual_cleanup');
function nitter_handle_manual_cleanup() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $cron_handler = new Nitter_Cron_Handler();
    $result = $cron_handler->manual_cleanup();
    
    if ($result) {
        wp_send_json_success('Manual cleanup completed successfully');
    } else {
        wp_send_json_error('Cleanup failed');
    }
}

// Cleanup orphaned images
add_action('wp_ajax_nitter_cleanup_orphaned', 'nitter_handle_cleanup_orphaned');
function nitter_handle_cleanup_orphaned() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $media_handler = new Nitter_Media_Handler();
    $deleted_count = $media_handler->cleanup_orphaned_images();
    
    wp_send_json_success("Cleaned up $deleted_count orphaned images");
}

// Delete all content
add_action('wp_ajax_nitter_delete_all_content', 'nitter_handle_delete_all_content');
function nitter_handle_delete_all_content() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $database = new Nitter_Database();
    $result = $database->delete_all_content();
    
    if ($result) {
        wp_send_json_success('All content deleted successfully');
    } else {
        wp_send_json_error('Failed to delete content');
    }
}