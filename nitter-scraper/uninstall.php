<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete all custom tables
$tables = array(
    $wpdb->prefix . 'nitter_accounts',
    $wpdb->prefix . 'nitter_tweets',
    $wpdb->prefix . 'nitter_images',
    $wpdb->prefix . 'nitter_instances',
    $wpdb->prefix . 'nitter_logs'
);

// Get all WordPress attachments created by this plugin before deleting tables
$images_table = $wpdb->prefix . 'nitter_images';
$attachments = $wpdb->get_results("SELECT wordpress_attachment_id FROM $images_table WHERE wordpress_attachment_id IS NOT NULL");

// Delete WordPress media library attachments
foreach ($attachments as $attachment) {
    wp_delete_attachment($attachment->wordpress_attachment_id, true);
}

// Drop all custom tables
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

// Clear scheduled cron events
wp_clear_scheduled_hook('nitter_cleanup_old_content');
wp_clear_scheduled_hook('nitter_cleanup_old_logs');

// Delete any plugin options if we had any
delete_option('nitter_scraper_version');
delete_option('nitter_scraper_settings');
