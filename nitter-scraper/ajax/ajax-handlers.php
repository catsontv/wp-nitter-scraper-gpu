<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX Handlers Loader
 * This file includes all AJAX handler files for the Nitter Scraper plugin
 */

// Include individual AJAX handler files
require_once NITTER_SCRAPER_PATH . 'ajax/accounts-ajax.php';
require_once NITTER_SCRAPER_PATH . 'ajax/tweets-ajax.php';
require_once NITTER_SCRAPER_PATH . 'ajax/logs-ajax.php';

// Test ImgBB connection (Phase 4)
add_action('wp_ajax_nitter_test_imgbb', 'nitter_handle_test_imgbb');
function nitter_handle_test_imgbb() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $imgbb_client = nitter_get_imgbb_client();
    $result = $imgbb_client->test_connection();
    
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['error']);
    }
}

// Manual video processing (Phase 4 testing)
add_action('wp_ajax_nitter_process_video_manual', 'nitter_handle_process_video_manual');
function nitter_handle_process_video_manual() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $video_id = intval($_POST['video_id']);
    if (!$video_id) {
        wp_send_json_error('Invalid video ID');
    }
    
    $database = nitter_get_database();
    $video_handler = nitter_get_video_handler();
    
    // Get video entry
    global $wpdb;
    $table = $wpdb->prefix . 'nitter_images';
    $entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id = %d AND conversion_status = 'pending'",
        $video_id
    ));
    
    if (!$entry) {
        wp_send_json_error('Video not found or already processed');
    }
    
    // Check configuration
    if (!$video_handler->is_configured()) {
        wp_send_json_error('Video processing is not properly configured. Check system requirements.');
    }
    
    // Process the video
    $result = $video_handler->process_video($entry);
    
    if ($result['status'] === 'completed') {
        wp_send_json_success($result);
    } else if ($result['status'] === 'skipped') {
        wp_send_json_error('Video was skipped: ' . $result['reason']);
    } else {
        wp_send_json_error($result['error']);
    }
}

// Get recent logs (for live log display)
add_action('wp_ajax_nitter_get_recent_logs', 'nitter_handle_get_recent_logs');
function nitter_handle_get_recent_logs() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $log_type = isset($_POST['log_type']) ? sanitize_text_field($_POST['log_type']) : '';
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
    
    global $wpdb;
    $table = $wpdb->prefix . 'nitter_logs';
    
    if ($log_type) {
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE log_type = %s ORDER BY date_created DESC LIMIT %d",
            $log_type,
            $limit
        ));
    } else {
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY date_created DESC LIMIT %d",
            $limit
        ));
    }
    
    wp_send_json_success($logs);
}

// Additional AJAX handlers can be added here as needed