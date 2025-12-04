<?php
if (!defined('ABSPATH')) {
    exit;
}

// Handle manual video processing
if (isset($_POST['nitter_process_videos_now']) && check_admin_referer('nitter_video_settings', 'nitter_video_settings_nonce')) {
    $video_handler = nitter_get_video_handler();
    $video_handler->process_pending_batch();
    echo '<div class="notice notice-success is-dismissible"><p>Video processing triggered! Check logs for progress.</p></div>';
}

// Handle settings save
if (isset($_POST['nitter_save_video_settings']) && check_admin_referer('nitter_video_settings', 'nitter_video_settings_nonce')) {
    $database = nitter_get_database();
    
    // Enable/disable video scraping
    $enable_video = isset($_POST['enable_video_scraping']) ? '1' : '0';
    $database->update_setting('enable_video_scraping', $enable_video);
    
    // Tool paths
    $database->update_setting('ytdlp_path', sanitize_text_field($_POST['ytdlp_path']));
    $database->update_setting('ffmpeg_path', sanitize_text_field($_POST['ffmpeg_path']));
    $database->update_setting('ffprobe_path', sanitize_text_field($_POST['ffprobe_path']));
    
    // Processing limits
    $database->update_setting('max_video_duration', intval($_POST['max_video_duration']));
    $database->update_setting('max_gif_size_mb', intval($_POST['max_gif_size_mb']));
    
    // ImgBB settings
    $database->update_setting('imgbb_api_key', sanitize_text_field($_POST['imgbb_api_key']));
    
    // File management
    $auto_delete = isset($_POST['auto_delete_local_files']) ? '1' : '0';
    $database->update_setting('auto_delete_local_files', $auto_delete);
    
    $database->update_setting('temp_folder_path', sanitize_text_field($_POST['temp_folder_path']));
    
    echo '<div class="notice notice-success is-dismissible"><p>Video settings saved successfully!</p></div>';
}

// Get current settings
$database = nitter_get_database();
$enable_video = $database->get_setting('enable_video_scraping', '0');
$ytdlp_path = $database->get_setting('ytdlp_path', '/usr/local/bin/yt-dlp');
$ffmpeg_path = $database->get_setting('ffmpeg_path', '/usr/bin/ffmpeg');
$ffprobe_path = $database->get_setting('ffprobe_path', '/usr/bin/ffprobe');
$max_duration = $database->get_setting('max_video_duration', '90');
$max_size = $database->get_setting('max_gif_size_mb', '20');
$imgbb_key = $database->get_setting('imgbb_api_key', '');
$auto_delete = $database->get_setting('auto_delete_local_files', '1');
$temp_folder = $database->get_setting('temp_folder_path', 'wp-content/uploads/nitter-temp');

// Get pending video count
$pending_videos = $database->get_pending_videos(100);
$pending_count = count($pending_videos);

?>

<div class="wrap">
    <h1>Video/GIF Settings - Phase 4</h1>
    
    <div class="notice notice-info">
        <p><strong>Phase 4: ImgBB Integration Active</strong></p>
        <p>Complete video→GIF→ImgBB pipeline is now available:</p>
        <ul style="margin-left: 20px;">
            <li>✅ Phase 1: Database schema and settings foundation</li>
            <li>✅ Phase 2: Video detection and metadata storage</li>
            <li>✅ Phase 3: Video → GIF conversion pipeline</li>
            <li>✅ <strong>Phase 4: ImgBB integration (CURRENT)</strong></li>
            <li>⏳ Phase 5: Background queue processing (coming next)</li>
            <li>⏳ Phase 6: Admin UI for GIF preview</li>
        </ul>
        <p><strong>What Phase 4 Does:</strong> Videos are downloaded, converted to GIFs, uploaded to ImgBB, and local files are auto-deleted. All GIFs are hosted on ImgBB.</p>
    </div>
    
    <?php if ($pending_count > 0): ?>
    <div class="notice notice-warning">
        <p><strong>⚠️ You have <?php echo $pending_count; ?> pending videos waiting to be processed!</strong></p>
        <p>WordPress cron may not be running reliably. Use the button below to process videos manually:</p>
        <form method="post" action="" style="display: inline;">
            <?php wp_nonce_field('nitter_video_settings', 'nitter_video_settings_nonce'); ?>
            <button type="submit" name="nitter_process_videos_now" class="button button-secondary" style="background: #2271b1; color: white; border-color: #2271b1;">
                🚀 Process Videos Now (Up to 3)
            </button>
        </form>
        <span style="margin-left: 10px; color: #666;">This will process up to 3 videos immediately. Click multiple times to process more.</span>
    </div>
    <?php endif; ?>
    
    <form method="post" action="">
        <?php wp_nonce_field('nitter_video_settings', 'nitter_video_settings_nonce'); ?>
        
        <table class="form-table">
            <tr>
                <th colspan="2">
                    <h2>General Settings</h2>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="enable_video_scraping">Enable Video Processing</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="enable_video_scraping" 
                               id="enable_video_scraping" 
                               value="1" 
                               <?php checked($enable_video, '1'); ?>
                        >
                        Enable complete video→GIF→ImgBB pipeline
                    </label>
                    <p class="description">
                        <strong>Phase 4 Feature:</strong> Videos will be downloaded, converted to GIFs, uploaded to ImgBB, and local files deleted automatically.
                    </p>
                    <?php if ($enable_video == '1'): ?>
                        <p class="description" style="color: #2271b1;">
                            ✅ <strong>Video processing is ENABLED.</strong> Complete pipeline is active.
                        </p>
                    <?php else: ?>
                        <p class="description" style="color: #d63638;">
                            ❌ <strong>Video processing is DISABLED.</strong> Video tweets will be ignored.
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            
            <tr>
                <th colspan="2">
                    <h2>Tool Paths</h2>
                    <p class="description">Paths to required command-line tools for video processing.</p>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="ytdlp_path">yt-dlp Path</label>
                </th>
                <td>
                    <input type="text" 
                           name="ytdlp_path" 
                           id="ytdlp_path" 
                           value="<?php echo esc_attr($ytdlp_path); ?>" 
                           class="regular-text"
                    >
                    <p class="description">
                        Full path to yt-dlp binary (e.g., /usr/local/bin/yt-dlp)<br>
                        Used to download videos from Twitter/X.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="ffmpeg_path">ffmpeg Path</label>
                </th>
                <td>
                    <input type="text" 
                           name="ffmpeg_path" 
                           id="ffmpeg_path" 
                           value="<?php echo esc_attr($ffmpeg_path); ?>" 
                           class="regular-text"
                    >
                    <p class="description">
                        Full path to ffmpeg binary (e.g., /usr/bin/ffmpeg)<br>
                        Used to convert videos to GIF format.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="ffprobe_path">ffprobe Path</label>
                </th>
                <td>
                    <input type="text" 
                           name="ffprobe_path" 
                           id="ffprobe_path" 
                           value="<?php echo esc_attr($ffprobe_path); ?>" 
                           class="regular-text"
                    >
                    <p class="description">
                        Full path to ffprobe binary (e.g., /usr/bin/ffprobe)<br>
                        Used to inspect video duration and properties.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th colspan="2">
                    <h2>Processing Limits</h2>
                    <p class="description">Control video processing to manage resources and file sizes.</p>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="max_video_duration">Max Video Duration (seconds)</label>
                </th>
                <td>
                    <input type="number" 
                           name="max_video_duration" 
                           id="max_video_duration" 
                           value="<?php echo esc_attr($max_duration); ?>" 
                           min="5" 
                           max="300" 
                           class="small-text"
                    > seconds
                    <p class="description">
                        Videos longer than this will be skipped (5-300 seconds).<br>
                        Recommended: 90 seconds.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="max_gif_size_mb">Max GIF Size (MB)</label>
                </th>
                <td>
                    <input type="number" 
                           name="max_gif_size_mb" 
                           id="max_gif_size_mb" 
                           value="<?php echo esc_attr($max_size); ?>" 
                           min="5" 
                           max="30" 
                           class="small-text"
                    > MB
                    <p class="description">
                        Target maximum GIF file size (5-30 MB).<br>
                        ImgBB has a 32MB limit. Recommended: 20 MB.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th colspan="2">
                    <h2>ImgBB Integration</h2>
                    <p class="description">Configure ImgBB for GIF hosting (GIFs are not stored in WordPress).</p>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="imgbb_api_key">ImgBB API Key</label>
                </th>
                <td>
                    <input type="text" 
                           name="imgbb_api_key" 
                           id="imgbb_api_key" 
                           value="<?php echo esc_attr($imgbb_key); ?>" 
                           class="regular-text"
                           placeholder="Enter your ImgBB API key"
                    >
                    <p class="description">
                        Get your free API key from <a href="https://api.imgbb.com/" target="_blank">ImgBB API</a>.<br>
                        <strong>Phase 4:</strong> All GIFs will be uploaded to ImgBB and not stored locally.
                    </p>
                    <?php if (!empty($imgbb_key)): ?>
                        <p>
                            <button type="button" id="test_imgbb_connection" class="button button-secondary">
                                Test ImgBB Connection
                            </button>
                            <span id="imgbb_test_result" style="margin-left: 10px;"></span>
                        </p>
                    <?php else: ?>
                        <p class="description" style="color: #d63638;">
                            ⚠️ <strong>API key required:</strong> Add your ImgBB API key and save settings before testing.
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            
            <tr>
                <th colspan="2">
                    <h2>File Management</h2>
                    <p class="description">Configure how temporary files are handled during processing.</p>
                </th>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="auto_delete_local_files">Auto-delete Local Files</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="auto_delete_local_files" 
                               id="auto_delete_local_files" 
                               value="1" 
                               <?php checked($auto_delete, '1'); ?>
                        >
                        Automatically delete source videos and GIFs after upload
                    </label>
                    <p class="description">
                        Recommended to keep enabled to save disk space.<br>
                        Files are deleted after successful ImgBB upload.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="temp_folder_path">Temporary Folder Path</label>
                </th>
                <td>
                    <input type="text" 
                           name="temp_folder_path" 
                           id="temp_folder_path" 
                           value="<?php echo esc_attr($temp_folder); ?>" 
                           class="regular-text"
                    >
                    <p class="description">
                        Relative path from WordPress root for temporary video/GIF storage.<br>
                        This folder will be created automatically if it doesn't exist.
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" 
                   name="nitter_save_video_settings" 
                   class="button button-primary" 
                   value="Save Video Settings"
            >
        </p>
    </form>
    
    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2>WordPress Cron Issue</h2>
        <p><strong>Why automatic processing isn't working:</strong></p>
        <p>WordPress cron relies on site visits to trigger scheduled tasks. If your site has low traffic or you're testing locally, cron won't fire automatically.</p>
        
        <h3>Solutions:</h3>
        <ol style="margin-left: 20px;">
            <li><strong>Use the manual button above</strong> - Click "🚀 Process Videos Now" to process 3 videos immediately</li>
            <li><strong>Set up real cron</strong> - Add this to your server's crontab:
                <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto;">*/5 * * * * wget -q -O - <?php echo site_url('/wp-cron.php?doing_wp_cron'); ?> >/dev/null 2>&1</pre>
            </li>
            <li><strong>Wait for site traffic</strong> - Each visit to your site will check if cron needs to run</li>
        </ol>
        
        <p><strong>For now:</strong> Just use the manual "Process Videos Now" button until all <?php echo $pending_count; ?> videos are processed!</p>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    $('#test_imgbb_connection').on('click', function() {
        var button = $(this);
        var resultSpan = $('#imgbb_test_result');
        
        button.prop('disabled', true).text('Testing...');
        resultSpan.html('<span style="color: #999;">⏳ Connecting to ImgBB...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'nitter_test_imgbb',
                nonce: nitter_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Test ImgBB Connection');
                
                if (response.success) {
                    resultSpan.html('<span style="color: #46b450;">✅ ' + response.data + '</span>');
                } else {
                    resultSpan.html('<span style="color: #dc3232;">❌ ' + response.data + '</span>');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Test ImgBB Connection');
                resultSpan.html('<span style="color: #dc3232;">❌ Connection failed</span>');
            }
        });
    });
});
</script>

<style>
.card h2 {
    margin-top: 0;
}
.card h3 {
    margin-top: 20px;
    margin-bottom: 10px;
}
.card pre {
    overflow-x: auto;
}
</style>