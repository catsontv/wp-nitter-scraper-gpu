<?php
/**
 * Plugin Name: Nitter Scraper GPU
 * Description: WordPress plugin to scrape X/Twitter accounts using Nitter instances with GPU-accelerated GIF conversion
 * Version: 2.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants - using NITTER_SCRAPER_* naming to match ajax/admin files
define('NITTER_SCRAPER_VERSION', '2.0.0');
define('NITTER_SCRAPER_PATH', plugin_dir_path(__FILE__));
define('NITTER_SCRAPER_URL', plugin_dir_url(__FILE__));

// Include required class files
require_once NITTER_SCRAPER_PATH . 'includes/class-database.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-api.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-media-handler.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-imgbb-client.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-video-handler.php';
require_once NITTER_SCRAPER_PATH . 'includes/class-cron-handler.php';
require_once NITTER_SCRAPER_PATH . 'admin/class-admin.php';

// Global singleton functions
function nitter_get_database() {
    static $database = null;
    if ($database === null) {
        $database = new Nitter_Database();
    }
    return $database;
}

function nitter_get_api() {
    static $api = null;
    if ($api === null) {
        $api = new Nitter_API();
    }
    return $api;
}

function nitter_get_media_handler() {
    static $media_handler = null;
    if ($media_handler === null) {
        $media_handler = new Nitter_Media_Handler();
    }
    return $media_handler;
}

function nitter_get_video_handler() {
    static $video_handler = null;
    if ($video_handler === null) {
        $video_handler = new Nitter_Video_Handler();
    }
    return $video_handler;
}

function nitter_get_imgbb_client() {
    static $imgbb_client = null;
    if ($imgbb_client === null) {
        $imgbb_client = new Nitter_ImgBB_Client();
    }
    return $imgbb_client;
}

function nitter_get_cron_handler() {
    static $cron_handler = null;
    if ($cron_handler === null) {
        $cron_handler = new Nitter_Cron_Handler();
    }
    return $cron_handler;
}

// Custom cron intervals
function nitter_custom_cron_intervals($schedules) {
    if (!isset($schedules['nitter_five_minutes'])) {
        $schedules['nitter_five_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'nitter-scraper')
        );
    }
    if (!isset($schedules['nitter_fifteen_minutes'])) {
        $schedules['nitter_fifteen_minutes'] = array(
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'nitter-scraper')
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'nitter_custom_cron_intervals');

// Initialize cron handler early
function nitter_init_cron_handler() {
    nitter_get_cron_handler();
}
add_action('init', 'nitter_init_cron_handler', 1);

// Activation hook
function nitter_activate() {
    // Ensure custom cron interval is registered
    add_filter('cron_schedules', 'nitter_custom_cron_intervals');
    
    $database = nitter_get_database();
    $database->create_tables();
    
    $cron_handler = nitter_get_cron_handler();
    $cron_handler->schedule_events();
    
    add_option('nitter_scraper_version', NITTER_SCRAPER_VERSION);
    
    $database->add_log('system', 'Plugin activated - Version ' . NITTER_SCRAPER_VERSION);
}
register_activation_hook(__FILE__, 'nitter_activate');

// Deactivation hook
function nitter_deactivate() {
    $cron_handler = nitter_get_cron_handler();
    $cron_handler->clear_scheduled_events();
    
    $database = nitter_get_database();
    $database->add_log('system', 'Plugin deactivated');
}
register_deactivation_hook(__FILE__, 'nitter_deactivate');

// Ensure cron events stay scheduled
function nitter_ensure_cron_scheduled() {
    if (!is_admin()) {
        return;
    }
    
    if (!wp_next_scheduled('nitter_process_videos')) {
        $cron = nitter_get_cron_handler();
        $cron->schedule_events();
        
        $database = nitter_get_database();
        $database->add_log('system', 'Cron events were missing - rescheduled automatically');
    }
}
add_action('admin_init', 'nitter_ensure_cron_scheduled');

// Initialize admin interface
if (is_admin()) {
    global $nitter_admin;
    $nitter_admin = new Nitter_Admin();
}

// Include AJAX handlers - ajax-handlers.php will load the other ajax files
require_once NITTER_SCRAPER_PATH . 'ajax/ajax-handlers.php';

// Add settings link on plugins page
function nitter_scraper_settings_link($links) {
    $settings_link = '<a href="admin.php?page=nitter-scraper">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'nitter_scraper_settings_link');