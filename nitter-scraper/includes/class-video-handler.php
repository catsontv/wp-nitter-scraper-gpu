<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles video download, conversion to GIF, ImgBB upload, and file management
 * PHASE 2: Orientation-aware quality levels, early abort, randomized GIF names
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
    private $parallel_count;
    private $first_pass_abort_threshold; // PHASE 2
    
    // Word lists for randomized GIF names (PHASE 2 Feature 3.3)
    private $adjectives = array(
        'cosmic', 'stellar', 'radiant', 'brilliant', 'vivid', 'electric', 'neon', 'quantum',
        'digital', 'crystal', 'frozen', 'blazing', 'golden', 'silver', 'emerald', 'sapphire',
        'mystic', 'ancient', 'modern', 'future', 'retro', 'vintage', 'dynamic', 'static',
        'fluid', 'solid', 'ethereal', 'tangible', 'virtual', 'real', 'abstract', 'concrete',
        'wild', 'tame', 'fierce', 'gentle', 'bold', 'subtle', 'loud', 'quiet',
        'bright', 'dark', 'colorful', 'monochrome', 'vibrant', 'muted', 'sharp', 'soft',
        'rapid', 'slow', 'smooth', 'rough'
    );
    
    private $nouns = array(
        'nebula', 'galaxy', 'comet', 'asteroid', 'meteor', 'supernova', 'quasar', 'pulsar',
        'phoenix', 'dragon', 'unicorn', 'griffin', 'sphinx', 'pegasus', 'hydra', 'chimera',
        'thunder', 'lightning', 'storm', 'tempest', 'cyclone', 'tornado', 'hurricane', 'typhoon',
        'ocean', 'river', 'mountain', 'valley', 'forest', 'desert', 'tundra', 'jungle',
        'pixel', 'byte', 'circuit', 'matrix', 'vector', 'vertex', 'polygon', 'fractal',
        'prism', 'spectrum', 'aurora', 'eclipse', 'horizon', 'zenith', 'meridian', 'equinox',
        'cascade', 'vortex', 'spiral', 'helix'
    );
    
    public function __construct() {
        $this->database = nitter_get_database();
        $this->imgbb_client = new Nitter_ImgBB_Client();
        $this->load_settings();
        $this->ensure_temp_folder();
    }
    
    private function load_settings() {
        $this->ytdlp_path = $this->database->get_setting('ytdlp_path', 'C:\\Users\\destro\\bin\\yt-dlp.exe');
        $this->ffmpeg_path = $this->database->get_setting('ffmpeg_path', 'C:\\Users\\destro\\bin\\ffmpeg.exe');
        $this->ffprobe_path = $this->database->get_setting('ffprobe_path', 'C:\\Users\\destro\\bin\\ffprobe.exe');
        $this->max_duration = intval($this->database->get_setting('max_video_duration', 90));
        $this->max_size_mb = intval($this->database->get_setting('max_gif_size_mb', 20));
        $this->auto_delete = boolval($this->database->get_setting('auto_delete_local_files', 1));
        $this->parallel_count = intval($this->database->get_setting('parallel_video_count', 5));
        $this->first_pass_abort_threshold = intval($this->database->get_setting('first_pass_abort_threshold_mb', 70)); // PHASE 2
        
        $temp_path = $this->database->get_setting('temp_folder_path', 'C:\\xampp\\htdocs\\wp-content\\uploads\\nitter-temp');
        $this->temp_folder = $temp_path;
    }
    
    private function ensure_temp_folder() {
        if (!file_exists($this->temp_folder)) {
            wp_mkdir_p($this->temp_folder);
            $this->database->add_log('conversion', 'Created temp folder: ' . $this->temp_folder);
        }
    }
    
    public function is_configured() {
        $errors = array();
        
        if (!function_exists('exec')) {
            $errors[] = 'exec() function is disabled';
        }
        
        if (!file_exists($this->ytdlp_path)) {
            $errors[] = "yt-dlp not found at: {$this->ytdlp_path}";
        }
        
        if (!file_exists($this->ffmpeg_path)) {
            $errors[] = "ffmpeg not found at: {$this->ffmpeg_path}";
        }
        
        if (!file_exists($this->ffprobe_path)) {
            $errors[] = "ffprobe not found at: {$this->ffprobe_path}";
        }
        
        if (!is_writable($this->temp_folder)) {
            $errors[] = "Temp folder not writable: {$this->temp_folder}";
        }
        
        if (!$this->imgbb_client->is_configured()) {
            $errors[] = 'ImgBB API key not configured';
        }
        
        if (!empty($errors)) {
            $this->database->add_log('conversion', 'Configuration errors: ' . implode(', ', $errors));
            return false;
        }
        
        return true;
    }
    
    public function process_pending_batch() {
        $enabled = $this->database->get_setting('enable_video_scraping', '0');
        if ($enabled !== '1') {
            return;
        }
        
        if (!$this->is_configured()) {
            $this->database->add_log('conversion', 'Cron: Video processing skipped - not properly configured');
            return;
        }
        
        $pending = $this->database->get_pending_videos($this->parallel_count);
        
        if (empty($pending)) {
            return;
        }
        
        $count = count($pending);
        $this->database->add_log('conversion', "Starting parallel processing of {$count} videos...");
        
        $processes = array();
        $pipes_array = array();
        
        foreach ($pending as $entry) {
            $this->database->update_video_conversion_status($entry->id, 'processing');
            
            $process_info = $this->spawn_video_worker($entry->id);
            if ($process_info) {
                $processes[] = $process_info['process'];
                $pipes_array[] = $process_info['pipes'];
                $this->database->add_log('conversion', "Spawned worker for video ID {$entry->id}");
            }
        }
        
        $this->database->add_log('conversion', "Waiting for {$count} parallel workers to complete...");
        
        foreach ($processes as $idx => $process) {
            $stdout = stream_get_contents($pipes_array[$idx][1]);
            $stderr = stream_get_contents($pipes_array[$idx][2]);
            
            fclose($pipes_array[$idx][0]);
            fclose($pipes_array[$idx][1]);
            fclose($pipes_array[$idx][2]);
            
            $exit_code = proc_close($process);
            
            if (!empty($stdout)) {
                $this->database->add_log('conversion', "Worker #{$idx} output: {$stdout}");
            }
            if (!empty($stderr) && $exit_code !== 0) {
                $this->database->add_log('conversion', "Worker #{$idx} error: {$stderr}");
            }
        }
        
        $this->database->add_log('conversion', 'Parallel batch processing COMPLETED');
    }
    
    private function spawn_video_worker($entry_id) {
        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($is_windows) {
            $php_path = 'C:\\xampp\\php\\php.exe';
            if (!file_exists($php_path)) {
                $php_path = PHP_BINARY;
            }
        } else {
            $php_path = PHP_BINARY;
        }
        
        $worker_script = dirname(__FILE__) . '/video-worker.php';
        
        if ($is_windows) {
            $command = sprintf('"%s" "%s" %d', $php_path, $worker_script, $entry_id);
        } else {
            $command = sprintf('%s %s %d', escapeshellarg($php_path), escapeshellarg($worker_script), $entry_id);
        }
        
        $descriptors = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        
        $process = proc_open($command, $descriptors, $pipes, ABSPATH);
        
        if (is_resource($process)) {
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);
            
            return array(
                'process' => $process,
                'pipes' => $pipes
            );
        }
        
        $this->database->add_log('conversion', "Failed to spawn worker for entry ID {$entry_id}");
        return false;
    }
    
    /**
     * PHASE 2 Feature 2.1: Get video orientation using ffprobe
     */
    private function get_video_orientation($video_file) {
        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($is_windows) {
            $file_escaped = '"' . str_replace('/', '\\', $video_file) . '"';
            $ffprobe = '"' . $this->ffprobe_path . '"';
        } else {
            $file_escaped = escapeshellarg($video_file);
            $ffprobe = $this->ffprobe_path;
        }
        
        // Get width and height
        $command = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 %s 2>&1',
            $ffprobe,
            $file_escaped
        );
        
        exec($command, $output, $return_code);
        
        if ($return_code !== 0 || empty($output)) {
            return 'unknown';
        }
        
        $dimensions = explode('x', trim($output[0]));
        if (count($dimensions) !== 2) {
            return 'unknown';
        }
        
        $width = intval($dimensions[0]);
        $height = intval($dimensions[1]);
        
        if ($width === 0 || $height === 0) {
            return 'unknown';
        }
        
        $aspect_ratio = $width / $height;
        
        // Determine orientation
        if (abs($aspect_ratio - 1.0) < 0.1) {
            return 'square'; // Nearly square (0.9 to 1.1 ratio)
        } elseif ($width > $height) {
            return 'landscape';
        } else {
            return 'portrait';
        }
    }
    
    /**
     * PHASE 2 Feature 3.3: Generate randomized GIF name
     */
    private function generate_random_gif_name($entry_id) {
        $adjective = $this->adjectives[array_rand($this->adjectives)];
        $noun = $this->nouns[array_rand($this->nouns)];
        $hash = substr(sha1($entry_id . microtime(true)), 0, 8);
        
        return "{$adjective}-{$noun}-{$hash}";
    }
    
    public function process_video($entry) {
        $entry_id = $entry->id;
        $video_url = $entry->original_video_url;
        
        $this->database->add_log('conversion', "Starting processing for entry ID: {$entry_id}");
        
        if ($entry->conversion_status !== 'processing') {
            $this->database->update_video_conversion_status($entry_id, 'processing');
        }
        
        $video_file = null;
        $gif_file = null;
        
        try {
            $video_file = $this->download_video($video_url, $entry_id);
            if (!$video_file) {
                throw new Exception('Failed to download video');
            }
            
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
                // PHASE 1: Delete parent tweet on skip
                $this->delete_parent_tweet($entry_id);
                return array('status' => 'skipped', 'reason' => 'duration');
            }
            
            // PHASE 2 Feature 2.1: Get orientation for smart quality levels
            $orientation = $this->get_video_orientation($video_file);
            $this->database->add_log('conversion', "Video orientation detected: {$orientation}");
            
            $gif_file = $this->convert_to_gif($video_file, $entry_id, $duration, $orientation);
            if (!$gif_file) {
                throw new Exception('Failed to convert video to GIF');
            }
            
            // PHASE 2 Feature 3.3: Use randomized GIF name
            $gif_name = $this->generate_random_gif_name($entry_id);
            $upload_result = $this->imgbb_client->upload_file($gif_file, $gif_name);
            
            if (!$upload_result['success']) {
                throw new Exception('ImgBB upload failed: ' . $upload_result['error']);
            }
            
            $this->database->update_gif_data(
                $entry_id,
                $upload_result['url'],
                $upload_result['delete_url'],
                $upload_result['size'],
                $duration
            );
            
            // PHASE 1: publish the parent tweet now that GIF is ready
            global $wpdb;
            $images_table = $wpdb->prefix . 'nitter_images';
            $tweets_table = $wpdb->prefix . 'nitter_tweets';
            
            $tweet_id = $wpdb->get_var($wpdb->prepare(
                "SELECT tweet_id FROM $images_table WHERE id = %d",
                $entry_id
            ));
            
            if ($tweet_id) {
                $parent_tweet_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $tweets_table WHERE id = %d",
                    $tweet_id
                ));
                if ($parent_tweet_id) {
                    $this->database->publish_tweet($parent_tweet_id);
                }
            }
            
            $size_mb = round($upload_result['size'] / (1024 * 1024), 2);
            $this->database->add_log('conversion',
                "Successfully processed entry ID: {$entry_id} | URL: {$upload_result['url']} | Size: {$size_mb}MB | Duration: {$duration}s | Name: {$gif_name}.gif"
            );
            
            if ($this->auto_delete) {
                $this->cleanup_files($video_file, $gif_file);
                $this->database->add_log('conversion', "Cleaned up local files for entry ID: {$entry_id}");
            }
            
            return array(
                'status' => 'completed',
                'imgbb_url' => $upload_result['url'],
                'delete_url' => $upload_result['delete_url'],
                'file_size' => $upload_result['size'],
                'duration' => $duration
            );
            
        } catch (Exception $e) {
            if ($video_file) $this->cleanup_files($video_file);
            if ($gif_file) $this->cleanup_files($gif_file);
            
            $this->database->update_video_conversion_status($entry_id, 'failed', $e->getMessage());
            $this->database->add_log('conversion', "Processing failed for entry ID: {$entry_id} - " . $e->getMessage());
            
            // PHASE 1: Delete parent tweet on permanent failure
            $this->delete_parent_tweet($entry_id);
            
            return array('status' => 'failed', 'error' => $e->getMessage());
        }
    }
    
    /**
     * PHASE 1: Delete parent tweet and video queue entry on permanent failure/skip
     */
    private function delete_parent_tweet($entry_id) {
        global $wpdb;
        $images_table = $wpdb->prefix . 'nitter_images';
        $tweets_table = $wpdb->prefix . 'nitter_tweets';
        
        $tweet_id = $wpdb->get_var($wpdb->prepare(
            "SELECT tweet_id FROM $images_table WHERE id = %d",
            $entry_id
        ));
        
        if ($tweet_id) {
            // Delete the tweet
            $wpdb->delete($tweets_table, array('id' => $tweet_id), array('%d'));
            $this->database->add_log('conversion', "Deleted parent tweet ID {$tweet_id} for failed/skipped video");
        }
        
        // Delete the video queue entry
        $wpdb->delete($images_table, array('id' => $entry_id), array('%d'));
    }
    
    private function download_video($video_url, $entry_id) {
        $output_file = $this->temp_folder . '/video_' . $entry_id . '.mp4';
        
        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($is_windows) {
            $output_escaped = '"' . str_replace('/', '\\', $output_file) . '"';
            $url_escaped = '"' . $video_url . '"';
            $ytdlp = '"' . $this->ytdlp_path . '"';
        } else {
            $url_escaped = escapeshellarg($video_url);
            $output_escaped = escapeshellarg($output_file);
            $ytdlp = $this->ytdlp_path;
        }
        
        $command = sprintf(
            '%s -f "best[ext=mp4]/best" --no-playlist -o %s %s 2>&1',
            $ytdlp,
            $output_escaped,
            $url_escaped
        );
        
        $this->database->add_log('conversion', "Downloading video: {$video_url}");
        
        exec($command, $output, $return_code);
        
        if ($return_code !== 0 || !file_exists($output_file)) {
            $error = implode("\n", $output);
            $this->database->add_log('conversion', "Download failed: {$error}");
            return false;
        }
        
        $file_size = filesize($output_file);
        $this->database->add_log('conversion', "Video downloaded: " . $this->format_bytes($file_size));
        
        return $output_file;
    }
    
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
     * PHASE 2 Feature 2.1: Orientation-aware smart quality levels with early abort
     */
    private function convert_to_gif($video_file, $entry_id, $duration, $orientation) {
        $gif_file = $this->temp_folder . '/gif_' . $entry_id . '.gif';
        
        // PHASE 2: Orientation-aware quality levels (3-pass strategy)
        $quality_levels = array(
            array('fps' => 15, 'dimension' => 720),  // Pass 1
            array('fps' => 12, 'dimension' => 640),  // Pass 2
            array('fps' => 10, 'dimension' => 540)   // Pass 3
        );
        
        foreach ($quality_levels as $pass_num => $quality) {
            $fps = $quality['fps'];
            $dimension = $quality['dimension'];
            
            $pass_label = $pass_num + 1;
            $this->database->add_log('conversion', "Pass {$pass_label}: FPS={$fps}, Dimension={$dimension}px, Orientation={$orientation}");
            
            if (file_exists($gif_file)) {
                unlink($gif_file);
            }
            
            // PHASE 2: Build orientation-aware scale filter
            $scale_filter = $this->build_scale_filter($orientation, $dimension);
            
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
            
            $command = sprintf(
                '%s -hwaccel cuda -i %s -vf "fps=%d,%s,split[s0][s1];[s0]palettegen=max_colors=128[p];[s1][p]paletteuse=dither=bayer:bayer_scale=3" -y %s 2>&1',
                $ffmpeg,
                $video_escaped,
                $fps,
                $scale_filter,
                $gif_escaped
            );
            
            exec($command, $output, $return_code);
            
            if ($return_code !== 0 || !file_exists($gif_file)) {
                $this->database->add_log('conversion', "Pass {$pass_label} conversion failed");
                continue;
            }
            
            $file_size = filesize($gif_file);
            $size_mb = $file_size / (1024 * 1024);
            
            $this->database->add_log('conversion', "Pass {$pass_label} GIF created: " . $this->format_bytes($file_size));
            
            // PHASE 2 Feature 2.1: Early abort logic (first pass only)
            if ($pass_num === 0 && $size_mb > $this->first_pass_abort_threshold) {
                // Calculate estimated minimum size (assuming 40% reduction from pass 1 to pass 3)
                $estimated_min_size = $size_mb * 0.40;
                
                if ($estimated_min_size > $this->max_size_mb) {
                    $this->cleanup_files($gif_file);
                    $this->database->add_log('conversion', 
                        sprintf(
                            "Aborting: First pass %.2fMB, estimated minimum ~%.2fMB > %dMB (threshold: %dMB)",
                            $size_mb,
                            $estimated_min_size,
                            $this->max_size_mb,
                            $this->first_pass_abort_threshold
                        )
                    );
                    $this->database->update_video_conversion_status(
                        $entry_id,
                        'skipped',
                        'Video too complex (early abort)'
                    );
                    return false;
                }
            }
            
            // Check if size is within limit
            if ($size_mb <= $this->max_size_mb) {
                return $gif_file;
            }
            
            $this->database->add_log('conversion', "GIF too large (" . round($size_mb, 2) . "MB > {$this->max_size_mb}MB), trying lower quality");
        }
        
        $this->cleanup_files($gif_file);
        $this->database->add_log('conversion', "Failed to create GIF under size limit after all attempts");
        
        return false;
    }
    
    /**
     * PHASE 2 Feature 2.1: Build orientation-aware scale filter for FFmpeg
     */
    private function build_scale_filter($orientation, $dimension) {
        switch ($orientation) {
            case 'landscape':
                // Constrain width, auto height
                return "scale={$dimension}:-1:flags=lanczos";
            
            case 'portrait':
                // Constrain height, auto width
                return "scale=-1:{$dimension}:flags=lanczos";
            
            case 'square':
                // Constrain both dimensions equally
                return "scale={$dimension}:{$dimension}:flags=lanczos";
            
            default:
                // Unknown orientation, constrain width (safer default)
                return "scale={$dimension}:-1:flags=lanczos";
        }
    }
    
    private function cleanup_files(...$files) {
        foreach ($files as $file) {
            if ($file && file_exists($file)) {
                unlink($file);
            }
        }
    }
    
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
                'auto_delete' => $this->auto_delete,
                'parallel_count' => $this->parallel_count,
                'first_pass_abort_threshold' => $this->first_pass_abort_threshold
            ),
            'imgbb' => $imgbb_status
        );
        
        return $status;
    }
    
    public function get_imgbb_client() {
        return $this->imgbb_client;
    }
}