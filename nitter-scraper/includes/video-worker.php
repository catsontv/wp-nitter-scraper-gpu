<?php
/**
 * Video Processing Worker Script
 * 
 * This script is called by separate PHP processes for parallel video processing.
 * Usage: php video-worker.php [entry_id]
 * 
 * Each worker handles one video independently:
 * 1. Downloads video from Twitter/X using yt-dlp
 * 2. Checks duration with ffprobe
 * 3. Converts to GIF using ffmpeg (GPU-accelerated)
 * 4. Uploads to ImgBB
 * 5. Updates database
 * 6. Cleans up local files
 * 
 * Exit codes:
 * 0 = Success (video processed or skipped)
 * 1 = Error (video processing failed)
 */

// This script must be run from command line
if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

// Get entry ID from command line argument
if ($argc < 2) {
    fwrite(STDERR, "Error: Entry ID required\nUsage: php video-worker.php [entry_id]\n");
    exit(1);
}

$entry_id = intval($argv[1]);

if ($entry_id <= 0) {
    fwrite(STDERR, "Error: Invalid entry ID\n");
    exit(1);
}

// Load WordPress environment
define('WP_USE_THEMES', false);

// Find wp-load.php by navigating up from plugin directory
// Plugin structure: wp-content/plugins/nitter-scraper/includes/video-worker.php
// Target: wp-load.php in WordPress root
$wp_load = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

if (!file_exists($wp_load)) {
    fwrite(STDERR, "Error: WordPress not found at {$wp_load}\n");
    exit(1);
}

require_once($wp_load);

// Verify plugin functions are available
if (!function_exists('nitter_get_database') || !function_exists('nitter_get_video_handler')) {
    fwrite(STDERR, "Error: Plugin functions not available. Make sure plugin is activated.\n");
    exit(1);
}

// Get plugin instances
$database = nitter_get_database();
$video_handler = nitter_get_video_handler();

if (!$database || !$video_handler) {
    fwrite(STDERR, "Error: Plugin not properly loaded\n");
    exit(1);
}

$database->add_log('video_conversion', "Worker: Starting processing for video ID {$entry_id} (PID: " . getmypid() . ")");

// Get the entry from database
global $wpdb;
$images_table = $wpdb->prefix . 'nitter_images';
$entry = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $images_table WHERE id = %d",
    $entry_id
));

if (!$entry) {
    $database->add_log('video_conversion', "Worker: Entry ID {$entry_id} not found in database");
    fwrite(STDERR, "Error: Entry ID {$entry_id} not found\n");
    exit(1);
}

// Verify it's a video entry
if ($entry->media_type !== 'gif' || empty($entry->original_video_url)) {
    $database->add_log('video_conversion', "Worker: Entry ID {$entry_id} is not a valid video entry (type: {$entry->media_type})");
    fwrite(STDERR, "Error: Entry ID {$entry_id} is not a video\n");
    exit(1);
}

// Process the video
try {
    $result = $video_handler->process_video($entry);
    
    if ($result['status'] === 'completed') {
        $database->add_log('video_conversion', "Worker: Successfully completed video ID {$entry_id}");
        echo "SUCCESS: Video ID {$entry_id} processed - {$result['imgbb_url']}\n";
        exit(0);
        
    } else if ($result['status'] === 'skipped') {
        $database->add_log('video_conversion', "Worker: Skipped video ID {$entry_id} - {$result['reason']}");
        echo "SKIPPED: Video ID {$entry_id} - {$result['reason']}\n";
        exit(0);
        
    } else {
        $database->add_log('video_conversion', "Worker: Failed video ID {$entry_id} - {$result['error']}");
        fwrite(STDERR, "FAILED: Video ID {$entry_id} - {$result['error']}\n");
        exit(1);
    }
    
} catch (Exception $e) {
    $error_msg = $e->getMessage();
    $database->add_log('video_conversion', "Worker: Exception processing video ID {$entry_id} - {$error_msg}");
    fwrite(STDERR, "EXCEPTION: Video ID {$entry_id} - {$error_msg}\n");
    exit(1);
}