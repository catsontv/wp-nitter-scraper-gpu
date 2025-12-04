<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin page for testing video conversion (Phase 3)
 */
class Nitter_Video_Test_Page {
    
    private $database;
    private $video_handler;
    
    public function __construct() {
        $this->database = nitter_get_database();
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_post_nitter_test_video', array($this, 'handle_test_video'));
        add_action('admin_post_nitter_process_pending', array($this, 'handle_process_pending'));
    }
    
    public function add_menu_page() {
        add_submenu_page(
            'nitter-scraper',
            'Video Testing',
            'Video Testing',
            'manage_options',
            'nitter-video-test',
            array($this, 'render_page')
        );
    }
    
    public function render_page() {
        // Load video handler
        require_once NITTER_SCRAPER_PLUGIN_DIR . 'includes/class-video-handler.php';
        $this->video_handler = new Nitter_Video_Handler();
        
        $config_status = $this->video_handler->get_config_status();
        $pending_videos = $this->database->get_pending_videos(10);
        
        ?>
        <div class="wrap">
            <h1>Video Conversion Testing</h1>
            <p>Phase 3: Test video download and GIF conversion (local only, no ImgBB upload yet)</p>
            
            <!-- Configuration Status -->
            <div class="card">
                <h2>Configuration Status</h2>
                <?php if ($config_status['configured']): ?>
                    <p style="color: green; font-weight: bold;">✓ Video processing is properly configured</p>
                <?php else: ?>
                    <p style="color: red; font-weight: bold;">✗ Video processing is NOT properly configured</p>
                <?php endif; ?>
                
                <h3>System Checks</h3>
                <ul>
                    <li>exec() enabled: <?php echo $config_status['checks']['exec_enabled'] ? '✓' : '✗'; ?></li>
                    <li>yt-dlp found: <?php echo $config_status['checks']['ytdlp_exists'] ? '✓' : '✗'; ?> (<?php echo esc_html($config_status['paths']['ytdlp']); ?>)</li>
                    <li>ffmpeg found: <?php echo $config_status['checks']['ffmpeg_exists'] ? '✓' : '✗'; ?> (<?php echo esc_html($config_status['paths']['ffmpeg']); ?>)</li>
                    <li>ffprobe found: <?php echo $config_status['checks']['ffprobe_exists'] ? '✓' : '✗'; ?> (<?php echo esc_html($config_status['paths']['ffprobe']); ?>)</li>
                    <li>Temp folder writable: <?php echo $config_status['checks']['temp_folder_writable'] ? '✓' : '✗'; ?> (<?php echo esc_html($config_status['paths']['temp_folder']); ?>)</li>
                </ul>
                
                <h3>Settings</h3>
                <ul>
                    <li>Max video duration: <?php echo $config_status['settings']['max_duration']; ?> seconds</li>
                    <li>Max GIF size: <?php echo $config_status['settings']['max_size_mb']; ?> MB</li>
                    <li>Auto-delete local files: <?php echo $config_status['settings']['auto_delete'] ? 'Yes' : 'No'; ?></li>
                </ul>
            </div>
            
            <!-- Test with URL -->
            <div class="card">
                <h2>Test Video Conversion</h2>
                <p>Enter a Twitter/X video URL to test the conversion process:</p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('nitter_test_video'); ?>
                    <input type="hidden" name="action" value="nitter_test_video">
                    <input type="url" name="video_url" placeholder="https://twitter.com/username/status/123456789" style="width: 500px;" required>
                    <button type="submit" class="button button-primary">Test Video Conversion</button>
                </form>
            </div>
            
            <!-- Pending Videos -->
            <div class="card">
                <h2>Pending Videos</h2>
                <?php if (!empty($pending_videos)): ?>
                    <p>Found <?php echo count($pending_videos); ?> pending videos</p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('nitter_process_pending'); ?>
                        <input type="hidden" name="action" value="nitter_process_pending">
                        <button type="submit" class="button button-primary">Process All Pending Videos</button>
                    </form>
                    <br>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Video URL</th>
                                <th>Status</th>
                                <th>Attempts</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_videos as $video): ?>
                                <tr>
                                    <td><?php echo $video->id; ?></td>
                                    <td><a href="<?php echo esc_url($video->original_video_url); ?>" target="_blank"><?php echo esc_html(substr($video->original_video_url, 0, 50)) . '...'; ?></a></td>
                                    <td><?php echo esc_html($video->conversion_status); ?></td>
                                    <td><?php echo $video->conversion_attempts; ?></td>
                                    <td><?php echo esc_html($video->date_saved); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No pending videos found.</p>
                <?php endif; ?>
            </div>
            
            <!-- Recent Logs -->
            <div class="card">
                <h2>Recent Video Conversion Logs</h2>
                <?php
                $logs = $this->database->get_logs(20);
                $video_logs = array_filter($logs, function($log) {
                    return $log->log_type === 'video_conversion';
                });
                ?>
                <?php if (!empty($video_logs)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($video_logs, 0, 10) as $log): ?>
                                <tr>
                                    <td><?php echo esc_html($log->date_created); ?></td>
                                    <td><?php echo esc_html($log->message); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No video conversion logs yet.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public function handle_test_video() {
        check_admin_referer('nitter_test_video');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $video_url = sanitize_text_field($_POST['video_url']);
        
        // Load video handler
        require_once NITTER_SCRAPER_PLUGIN_DIR . 'includes/class-video-handler.php';
        $video_handler = new Nitter_Video_Handler();
        
        if (!$video_handler->is_configured()) {
            wp_redirect(add_query_arg('message', 'not_configured', admin_url('admin.php?page=nitter-video-test')));
            exit;
        }
        
        // Create a temporary test entry
        $test_entry = (object) array(
            'id' => 'test_' . time(),
            'original_video_url' => $video_url
        );
        
        $result = $video_handler->process_video($test_entry);
        
        if ($result['status'] === 'completed') {
            wp_redirect(add_query_arg('message', 'success', admin_url('admin.php?page=nitter-video-test')));
        } else {
            wp_redirect(add_query_arg(array('message' => 'failed', 'error' => urlencode($result['error'] ?? $result['reason'] ?? 'unknown')), admin_url('admin.php?page=nitter-video-test')));
        }
        exit;
    }
    
    public function handle_process_pending() {
        check_admin_referer('nitter_process_pending');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Load video handler
        require_once NITTER_SCRAPER_PLUGIN_DIR . 'includes/class-video-handler.php';
        $video_handler = new Nitter_Video_Handler();
        
        $pending = $this->database->get_pending_videos(10);
        $processed = 0;
        
        foreach ($pending as $entry) {
            $video_handler->process_video($entry);
            $processed++;
        }
        
        wp_redirect(add_query_arg('message', 'processed_' . $processed, admin_url('admin.php?page=nitter-video-test')));
        exit;
    }
}