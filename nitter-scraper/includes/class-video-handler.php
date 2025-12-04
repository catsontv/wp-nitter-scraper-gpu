```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles video download, conversion to GIF, ImgBB upload, and file management
 * Phase 4: Complete pipeline with ImgBB integration + automatic batch processing
 */
class Nitter_Video_Handler {
    
    private $database;
    private $imgbb_client;
    private $settings;
    
    // Executable paths
    private $ytdlp_path;
    private $ffmpeg_path;
    private $ffprobe_path;
    
    // Conversion settings
    private $max_duration;
    private $max_size_mb;
    private $temp_folder;
    private $auto_delete;
    
    public function __construct() {
        $this->database = nitter_get_database();
        $this->imgbb_client = new Nitter_ImgBB_Client();
        $this->load_settings();
        $this->ensure_temp_folder();
    }
    
    /**
     * Load settings from database
     */
    private function load_settings() {
        $this->ytdlp_path = $this->database->get_setting('ytdlp_path', '/usr/local/bin/yt-dlp');
        $this->ffmpeg_path = $this->database->get_setting('ffmpeg_path', '/usr/bin/ffmpeg');
        $this->ffprobe_path = $this->database->get_setting('ffprobe_path', '/usr/bin/ffprobe');
        $this->max_duration = intval($this->database->get_setting('max_video_duration', 90));
        $this->max_size_mb = intval($this->database->get_setting('max_gif_size_mb', 20));
        $this->auto_delete = boolval($this->database->get_setting('auto_delete_local_files', 1));
        
        // Get temp folder path
        $temp_path = $this->database->get_setting('temp_folder_path', 'wp-content/uploads/nitter-temp');
        
        // Convert relative path to absolute
        if (strpos($temp_path, '/') !== 0) {
            $this->temp_folder = ABSPATH . $temp_path;
        } else {
            $this->temp_folder = $temp_path;
        }
    }
    
    /**
     * Ensure temp folder exists
     */
    private function ensure_temp_folder() {
        if (!file_exists($this->temp_folder)) {
            wp_mkdir_p($this->temp_folder);
            $this->database->add_log('video_conversion', "Created temp folder: {$this->temp_folder}");
        }
    }
    
    /**
     * Check if video processing is properly configured
     */
    public function is_configured() {
        $errors = array();
        
        // Check if exec() is enabled
        if (!function_exists('exec')) {
            $errors[] = 'exec() function is disabled';
        }
        
        // Check executables
        if (!file_exists($this->ytdlp_path)) {
            $errors[] = "yt-dlp not found at: {$this->ytdlp_path}";
        }
        
        if (!file_exists($this->ffmpeg_path)) {
            $errors[] = "ffmpeg not found at: {$this->ffmpeg_path}";
        }
        
        if (!file_exists($this->ffprobe_path)) {
            $errors[] = "ffprobe not found at: {$this->ffprobe_path}";
        }
        
        // Check temp folder
        if (!is_writable($this->temp_folder)) {
            $errors[] = "Temp folder not writable: {$this->temp_folder}";
        }
        
        // Check ImgBB configuration
        if (!$this->imgbb_client->is_configured()) {
            $errors[] = 'ImgBB API key not configured';
        }
        
        if (!empty($errors)) {
            $this->database->add_log('video_conversion', 'Configuration errors: ' . implode(', ', $errors));
            return false;
        }
        
        return true;
    }
    
    /**
     * Process pending videos in batch (called by cron)
     * Processes up to 3 videos per run
     */
    public function process_pending_batch() {
        // Check if video processing is enabled
        $enabled = $this->database->get_setting('enable_video_scraping', '0');
        if ($enabled !== '1') {
            return;
        }
        
        // Check configuration
        if (!$this->is_configured()) {
            $this->database->add_log('video_conversion', 'Cron: Video processing skipped - not properly configured');
            return;
        }
        
        // Get pending videos (limit 3 per run)
        $pending = $this->database->get_pending_videos(3);
        
        if (empty($pending)) {
            return; // Nothing to process
        }
        
        $this->database->add_log('video_conversion', 'Cron: Processing ' . count($pending) . ' pending videos...');
        
        foreach ($pending as $entry) {
            $result = $this->process_video($entry);
            
            if ($result['status'] === 'completed') {
                $this->database->add_log('video_conversion', "Cron: Video ID {$entry->id} processed successfully");
            } else if ($result['status'] === 'skipped') {
                $this->database->add_log('video_conversion', "Cron: Video ID {$entry->id} skipped: {$result['reason']}");
            } else {
                $this->database->add_log('video_conversion', "Cron: Video ID {$entry->id} failed: {$result['error']}");
            }
        }
        
        $this->database->add_log('video_conversion', 'Cron: Batch processing completed');
    }
    
    /**
     * Process a single video entry
     * Complete pipeline: download → check duration → convert → upload to ImgBB → cleanup
     */
    public function process_video($entry) {
        $entry_id = $entry->id;
        $video_url = $entry->original_video_url;
        
        $this->database->add_log('video_conversion', "Starting processing for entry ID: {$entry_id}");
        
        // Mark as processing
        $this->database->update_video_conversion_status($entry_id, 'processing');
        
        $video_file = null;
        $gif_file = null;
        
        try {
            // Step 1: Download video
            $video_file = $this->download_video($video_url, $entry_id);
            if (!$video_file) {
                throw new Exception('Failed to download video');
            }
            
            // Step 2: Check duration
            $duration = $this->get_video_duration($video_file);
            if ($duration === false) {
                throw new Exception('Failed to get video duration');
            }
            
            if ($duration > $this->max_duration) {
                $this->cleanup_files($video_file);
                $this->database->update_video_conversion_status(
                    $entry_id, 
                    'skipped', 
                    "Video too long: {$duration}s (max: {$this->max_duration}s)"
                );
                return array('status' => 'skipped', 'reason' => 'duration');
            }
            
            // Step 3: Convert to GIF
            $gif_file = $this->convert_to_gif($video_file, $entry_id, $duration);
            if (!$gif_file) {
                throw new Exception('Failed to convert video to GIF');
            }
            
            // Step 4: Upload to ImgBB
            $gif_name = "nitter_gif_{$entry_id}";
            $upload_result = $this->imgbb_client->upload_file($gif_file, $gif_name);
            
            if (!$upload_result['success']) {
                throw new Exception('ImgBB upload failed: ' . $upload_result['error']);
            }
            
            // Step 5: Update database with ImgBB data
            $file_size = filesize($gif_file);
            $this->database->update_gif_data(
                $entry_id,
                $upload_result['url'],
                $upload_result['delete_url'],
                $upload_result['size'], // Use actual uploaded size from ImgBB
                $duration
            );
            
            $size_mb = round($upload_result['size'] / (1024 * 1024), 2);
            $this->database->add_log('video_conversion', 
                "Successfully processed entry ID: {$entry_id} | URL: {$upload_result['url']} | Size: {$size_mb}MB | Duration: {$duration}s"
            );
            
            // Step 6: Cleanup local files
            if ($this->auto_delete) {
                $this->cleanup_files($video_file, $gif_file);
                $this->database->add_log('video_conversion', "Cleaned up local files for entry ID: {$entry_id}");
            }
            
            return array(
                'status' => 'completed',
                'imgbb_url' => $upload_result['url'],
                'delete_url' => $upload_result['delete_url'],
                'file_size' => $upload_result['size'],
                'duration' => $duration
            );
            
        } catch (Exception $e) {
            // Cleanup on failure
            if ($video_file) $this->cleanup_files($video_file);
            if ($gif_file) $this->cleanup_files($gif_file);
            
            $this->database->update_video_conversion_status($entry_id, 'failed', $e->getMessage());
            $this->database->add_log('video_conversion', "Processing failed for entry ID: {$entry_id} - " . $e->getMessage());
            
            return array('status' => 'failed', 'error' => $e->getMessage());
        }
    }
    
    /**
     * Download video using yt-dlp
     */
    private function download_video($video_url, $entry_id) {
        $output_file = $this->temp_folder . '/video_' . $entry_id . '.mp4';
        
        // Windows-compatible path handling
        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($is_windows) {
            // On Windows, use double quotes and don't escape the executable path
            $output_escaped = '"' . str_replace('/', '\\', $output_file) . '"';
            $url_escaped = '"' . $video_url . '"';
            $ytdlp = '"' . $this->ytdlp_path . '"';
        } else {
            // Linux/Unix escaping
            $url_escaped = escapeshellarg($video_url);
            $output_escaped = escapeshellarg($output_file);
            $ytdlp = $this->ytdlp_path;
        }
        
        // Build yt-dlp command
        $command = sprintf(
            '%s -f "best[ext=mp4]/best" --no-playlist -o %s %s 2>&1',
            $ytdlp,
            $output_escaped,
            $url_escaped
        );
        
        $this->database->add_log('video_conversion', "Downloading video: {$video_url}");
        
        exec($command, $output, $return_code);
        
        if ($return_code !== 0 || !file_exists($output_file)) {
            $error = implode("\n", $output);
            $this->database->add_log('video_conversion', "Download failed: {$error}");
            return false;
        }
        
        $file_size = filesize($output_file);
        $this->database->add_log('video_conversion', "Video downloaded: " . $this->format_bytes($file_size));
        
        return $output_file;
    }
    
    /**
     * Get video duration using ffprobe
     */
    private function get_video_duration($video_file) {
        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($is_windows) {
            $file_escaped = '"' . str_replace('/', '\\', $video_file) . '"';
            $ffprobe = '"' . $this->ffprobe_path . '"';
        } else {
            $file_escaped = escapeshellarg($video_file);
            $ffprobe = $this->ffprobe_path;
        }
        
        $command = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            $ffprobe,
            $file_escaped
        );
        
        exec($command, $output, $return_code);
        
        if ($return_code !== 0 || empty($output)) {
            return false;
        }
        
        return intval(floatval($output[0]));
    }
    
    /**
     * Convert video to GIF with progressive quality reduction
     */
private function convert_to_gif($video_file, $entry_id, $duration) {
    $gif_file = $this->temp_folder . '/gif_' . $entry_id . '.gif';
    
    // Progressive quality settings
    $quality_levels = array(
        array('fps' => 15, 'width' => 720),
        array('fps' => 12, 'width' => 640),
        array('fps' => 10, 'width' => 540),
        array('fps' => 8, 'width' => 480),
        array('fps' => 6, 'width' => 360),
        array('fps' => 5, 'width' => 320)
    );
    
    foreach ($quality_levels as $index => $quality) {
        $fps = $quality['fps'];
        $width = $quality['width'];
        
        $this->database->add_log('video_conversion', "Attempting conversion with FPS: {$fps}, Width: {$width}px");
        
        // Delete previous attempt if exists
        if (file_exists($gif_file)) {
            unlink($gif_file);
        }
        
        // Build ffmpeg command with Windows compatibility
        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($is_windows) {
            $video_escaped = '"' . str_replace('/', '\\', $video_file) . '"';
            $gif_escaped = '"' . str_replace('/', '\\', $gif_file) . '"';
            $ffmpeg = '"' . $this->ffmpeg_path . '"';
        } else {
            $video_escaped = escapeshellarg($video_file);
            $gif_escaped = escapeshellarg($gif_file);
            $ffmpeg = $this->ffmpeg_path;
        }
        
        // Use GPU for decode/resize, CPU for GIF palette
        $command = sprintf(
            '%s -hwaccel cuda -i %s -vf "fps=%d,scale=%d:-1:flags=lanczos,split[s0][s1];[s0]palettegen=max_colors=128[p];[s1][p]paletteuse=dither=bayer:bayer_scale=3" -y %s 2>&1',
            $ffmpeg,
            $video_escaped,
            $fps,
            $width,
            $gif_escaped
        );
        
        exec($command, $output, $return_code);
        
        if ($return_code !== 0 || !file_exists($gif_file)) {
            $this->database->add_log('video_conversion', "Conversion attempt failed");
            continue;
        }
        
        $file_size = filesize($gif_file);
        $size_mb = $file_size / (1024 * 1024);
        
        $this->database->add_log('video_conversion', "GIF created: " . $this->format_bytes($file_size) . " with {$fps}fps @ {$width}px");
        
        // Check if size is acceptable
        if ($size_mb <= $this->max_size_mb) {
            return $gif_file;
        }
        
        $this->database->add_log('video_conversion', "GIF too large (" . round($size_mb, 2) . "MB > {$this->max_size_mb}MB), trying lower quality");
    }
    
    // If we get here, all attempts failed
    $this->cleanup_files($gif_file);
    $this->database->add_log('video_conversion', "Failed to create GIF under size limit after all attempts");
    
    return false;
}

    
    /**
     * Cleanup temporary files
     */
    private function cleanup_files(...$files) {
        foreach ($files as $file) {
            if ($file && file_exists($file)) {
                unlink($file);
            }
        }
    }
    
    /**
     * Format bytes to human readable
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Get configuration status for admin display
     */
    public function get_config_status() {
        $imgbb_status = $this->imgbb_client->get_config_status();
        
        $status = array(
            'configured' => $this->is_configured(),
            'checks' => array(
                'exec_enabled' => function_exists('exec'),
                'ytdlp_exists' => file_exists($this->ytdlp_path),
                'ffmpeg_exists' => file_exists($this->ffmpeg_path),
                'ffprobe_exists' => file_exists($this->ffprobe_path),
                'temp_folder_writable' => is_writable($this->temp_folder),
                'imgbb_configured' => $imgbb_status['configured']
            ),
            'paths' => array(
                'ytdlp' => $this->ytdlp_path,
                'ffmpeg' => $this->ffmpeg_path,
                'ffprobe' => $this->ffprobe_path,
                'temp_folder' => $this->temp_folder
            ),
            'settings' => array(
                'max_duration' => $this->max_duration,
                'max_size_mb' => $this->max_size_mb,
                'auto_delete' => $this->auto_delete
            ),
            'imgbb' => $imgbb_status
        );
        
        return $status;
    }
    
    /**
     * Get ImgBB client instance
     */
    public function get_imgbb_client() {
        return $this->imgbb_client;
    }
}