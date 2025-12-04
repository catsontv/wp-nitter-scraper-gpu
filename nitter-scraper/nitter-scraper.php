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

define('NITTER_PLUGIN_VERSION', '2.0.0');
define('NITTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NITTER_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once NITTER_PLUGIN_DIR . 'includes/class-database.php';
require_once NITTER_PLUGIN_DIR . 'includes/class-api.php';
require_once NITTER_PLUGIN_DIR . 'includes/class-media-handler.php';
require_once NITTER_PLUGIN_DIR . 'includes/class-imgbb-client.php';
require_once NITTER_PLUGIN_DIR . 'includes/class-video-handler.php';
require_once NITTER_PLUGIN_DIR . 'includes/class-cron-handler.php';
require_once NITTER_PLUGIN_DIR . 'includes/class-admin.php';

function nitter_get_database() {
    static $database = null;
    if ($database === null) {
        $database = new Nitter_Database();
    }
    return $database;
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

function nitter_activate() {
    $database = new Nitter_Database();
    $database->create_tables();
    
    $cron_handler = new Nitter_Cron_Handler();
    $cron_handler->schedule_events();
    
    add_option('nitter_scraper_version', NITTER_PLUGIN_VERSION);
}

function nitter_deactivate() {
    $cron_handler = new Nitter_Cron_Handler();
    $cron_handler->unschedule_events();
}

register_activation_hook(__FILE__, 'nitter_activate');
register_deactivation_hook(__FILE__, 'nitter_deactivate');

// Custom cron intervals
function nitter_custom_cron_intervals($schedules) {
    $schedules['nitter_five_minutes'] = array(
        'interval' => 300,
        'display' => __('Every 5 Minutes')
    );
    $schedules['nitter_fifteen_minutes'] = array(
        'interval' => 900,
        'display' => __('Every 15 Minutes')
    );
    return $schedules;
}
add_filter('cron_schedules', 'nitter_custom_cron_intervals');

if (is_admin()) {
    global $nitter_admin;
    $nitter_admin = new Nitter_Admin();
}

require_once NITTER_PLUGIN_DIR . 'ajax/ajax-handler.php';
require_once NITTER_PLUGIN_DIR . 'ajax/instances-ajax.php';
require_once NITTER_PLUGIN_DIR . 'ajax/accounts-ajax.php';
require_once NITTER_PLUGIN_DIR . 'ajax/tweets-ajax.php';
require_once NITTER_PLUGIN_DIR . 'ajax/videos-ajax.php';
require_once NITTER_PLUGIN_DIR . 'ajax/logs-ajax.php';
require_once NITTER_PLUGIN_DIR . 'ajax/settings-ajax.php';