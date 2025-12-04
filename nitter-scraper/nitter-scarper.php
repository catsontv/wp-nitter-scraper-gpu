<?php
/**
 * Plugin Name: Nitter Scraper
 * Description: Scrape Twitter/X accounts via Nitter instances with video→GIF conversion and ImgBB integration
 * Version: 1.4.2
 * Author: Your Name
 * Text Domain: nitter-scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('NITTER_SCRAPER_VERSION', '1.4.2');
define('NITTER_SCRAPER_PATH', plugin_dir_path(__FILE__));
define('NITTER_SCRAPER_URL', plugin_dir_url(__FILE__));

// Include required files
require_once NITTER_SCRAPER_PATH . 'includes/class-database.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-api.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-media-handler.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-video-handler.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-imgbb-client.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-cron-handler.php';
require_once NITTER_SCRAPER_PATH . 'admin/class-admin.php';
require_once NITTER_SCRAPER_PATH . 'ajax/ajax-handlers.php';

/**
 * Add custom cron schedules - MUST be registered early and always
 * This runs on every page load to ensure the interval exists when cron tries to use it
 */
function nitter_add_cron_schedules($schedules) {
    if (!isset($schedules['nitter_five_minutes'])) {
        $schedules['nitter_five_minutes'] = array(
            'interval' => 300, // 5 minutes in seconds
            'display' => __('Every 5 Minutes', 'nitter-scraper')
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'nitter_add_cron_schedules');

/**
 * Global function to get database instance
 */
function nitter_get_database() {
    static $database = null;
    if ($database === null) {
        $database = new Nitter_Database();
    }
    return $database;
}

/**
 * Global function to get API instance
 */
function nitter_get_api() {
    static $api = null;
    if ($api === null) {
        $api = new Nitter_API();
    }
    return $api;
}

/**
 * Global function to get media handler instance
 */
function nitter_get_media_handler() {
    static $media_handler = null;
    if ($media_handler === null) {
        $media_handler = new Nitter_Media_Handler();
    }
    return $media_handler;
}

/**
 * Global function to get video handler instance
 */
function nitter_get_video_handler() {
    static $video_handler = null;
    if ($video_handler === null) {
        $video_handler = new Nitter_Video_Handler();
    }
    return $video_handler;
}

/**
 * Global function to get ImgBB client instance
 */
function nitter_get_imgbb_client() {
    static $imgbb_client = null;
    if ($imgbb_client === null) {
        $imgbb_client = new Nitter_ImgBB_Client();
    }
    return $imgbb_client;
}

/**
 * Global function to get cron handler instance
 */
function nitter_get_cron_handler() {
    static $cron_handler = null;
    if ($cron_handler === null) {
        $cron_handler = new Nitter_Cron_Handler();
    }
    return $cron_handler;
}

/**
 * CRITICAL: Initialize cron handler EARLY so hooks are registered
 * This must run before WordPress processes cron events
 */
function nitter_init_cron_handler() {
    // Initialize cron handler to register action hooks
    nitter_get_cron_handler();
}
add_action('init', 'nitter_init_cron_handler', 1); // Priority 1 = very early

/**
 * Activation hook
 */
function nitter_scraper_activate() {
    // Ensure custom cron interval is registered
    add_filter('cron_schedules', 'nitter_add_cron_schedules');
    
    $database = nitter_get_database();
    $database->create_tables();
    
    // Schedule cron events
    $cron = nitter_get_cron_handler();
    $cron->schedule_events();
    
    // Log activation
    $database->add_log('system', 'Plugin activated - Version ' . NITTER_SCRAPER_VERSION);
}
register_activation_hook(__FILE__, 'nitter_scraper_activate');

/**
 * Deactivation hook
 */
function nitter_scraper_deactivate() {
    // Clear scheduled events
    $cron = nitter_get_cron_handler();
    $cron->clear_scheduled_events();
    
    // Log deactivation
    $database = nitter_get_database();
    $database->add_log('system', 'Plugin deactivated');
}
register_deactivation_hook(__FILE__, 'nitter_scraper_deactivate');

/**
 * Check and reschedule cron events if needed
 * This ensures cron events stay scheduled even if WordPress cron gets cleared
 */
function nitter_ensure_cron_scheduled() {
    // Only run this check in admin to avoid overhead
    if (!is_admin()) {
        return;
    }
    
    // Check if video processing cron is scheduled
    if (!wp_next_scheduled('nitter_process_videos')) {
        $cron = nitter_get_cron_handler();
        $cron->schedule_events();
        
        $database = nitter_get_database();
        $database->add_log('system', 'Cron events were missing - rescheduled automatically');
    }
}
add_action('admin_init', 'nitter_ensure_cron_scheduled');

/**
 * Initialize admin interface
 */
if (is_admin()) {
    new Nitter_Admin();
}

/**
 * Add settings link on plugins page
 */
function nitter_scraper_settings_link($links) {
    $settings_link = '<a href="admin.php?page=nitter-scraper">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'nitter_scraper_settings_link');