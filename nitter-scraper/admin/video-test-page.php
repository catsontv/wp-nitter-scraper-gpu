<?php
if (!defined('ABSPATH')) {
    exit;
}

$database = nitter_get_database();
$video_handler = nitter_get_video_handler();

// Get configuration status
$config_status = $video_handler->get_config_status();

// Get pending videos
$pending_videos = $database->get_pending_videos(10);

?>

<div class="wrap">
    <h1>Test Video Processing - Phase 4</h1>
    
    <div class="notice notice-info">
        <p><strong>Manual Video Processing Test</strong></p>
        <p>This page allows you to manually process pending videos one at a time to verify the complete pipeline works:</p>
        <ul style="margin-left: 20px;">
            <li>Download video with yt-dlp</li>
            <li>Check duration with ffprobe</li>
            <li>Convert to GIF with ffmpeg</li>
            <li><strong>Upload to ImgBB</strong></li>
            <li>Store ImgBB URL in database</li>
            <li>Delete local files</li>
        </ul>
    </div>
    
    <!-- Configuration Status -->
    <div class="card" style="max-width: 800px; margin-bottom: 20px;">
        <h2>System Configuration</h2>
        
        <table class="widefat" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>exec() Function</strong></td>
                    <td>
                        <?php if ($config_status['checks']['exec_enabled']): ?>
                            <span style="color: #46b450;">✅ Enabled</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">❌ Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td>Required to run command-line tools</td>
                </tr>
                
                <tr>
                    <td><strong>yt-dlp</strong></td>
                    <td>
                        <?php if ($config_status['checks']['ytdlp_exists']): ?>
                            <span style="color: #46b450;">✅ Found</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">❌ Not Found</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($config_status['paths']['ytdlp']); ?></code></td>
                </tr>
                
                <tr>
                    <td><strong>ffmpeg</strong></td>
                    <td>
                        <?php if ($config_status['checks']['ffmpeg_exists']): ?>
                            <span style="color: #46b450;">✅ Found</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">❌ Not Found</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($config_status['paths']['ffmpeg']); ?></code></td>
                </tr>
                
                <tr>
                    <td><strong>ffprobe</strong></td>
                    <td>
                        <?php if ($config_status['checks']['ffprobe_exists']): ?>
                            <span style="color: #46b450;">✅ Found</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">❌ Not Found</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($config_status['paths']['ffprobe']); ?></code></td>
                </tr>
                
                <tr>
                    <td><strong>Temp Folder</strong></td>
                    <td>
                        <?php if ($config_status['checks']['temp_folder_writable']): ?>
                            <span style="color: #46b450;">✅ Writable</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">❌ Not Writable</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($config_status['paths']['temp_folder']); ?></code></td>
                </tr>
                
                <tr>
                    <td><strong>ImgBB API</strong></td>
                    <td>
                        <?php if ($config_status['checks']['imgbb_configured']): ?>
                            <span style="color: #46b450;">✅ Configured</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">❌ Not Configured</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($config_status['imgbb']['api_key_set']): ?>
                            API Key: <?php echo $config_status['imgbb']['api_key_length']; ?> characters
                        <?php else: ?>
                            <em>No API key set</em>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <td colspan="3">
                        <?php if ($config_status['configured']): ?>
                            <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; margin-top: 10px;">
                                <strong>✅ All systems ready!</strong> Video processing is fully configured.
                            </div>
                        <?php else: ?>
                            <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; border-radius: 4px; margin-top: 10px;">
                                <strong>❌ Configuration incomplete.</strong> Please fix the issues above before processing videos.
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <h3 style="margin-top: 20px;">Processing Settings</h3>
        <ul style="margin-left: 20px;">
            <li><strong>Max Video Duration:</strong> <?php echo $config_status['settings']['max_duration']; ?> seconds</li>
            <li><strong>Max GIF Size:</strong> <?php echo $config_status['settings']['max_size_mb']; ?> MB</li>
            <li><strong>Auto-delete Files:</strong> <?php echo $config_status['settings']['auto_delete'] ? 'Enabled' : 'Disabled'; ?></li>
        </ul>
    </div>
    
    <!-- Pending Videos -->
    <div class="card" style="max-width: 800px;">
        <h2>Pending Videos (<?php echo count($pending_videos); ?>)</h2>
        
        <?php if (empty($pending_videos)): ?>
            <p style="color: #666;"><em>No pending videos found. Videos will appear here after scraping accounts with video content.</em></p>
        <?php else: ?>
            <p>Select a video to process manually. This will run the complete pipeline and show detailed results.</p>
            
            <div id="test-results" style="display: none; margin: 20px 0; padding: 15px; background: #f0f0f0; border-radius: 4px;">
                <h3 style="margin-top: 0;">Processing Results</h3>
                <div id="test-results-content"></div>
            </div>
            
            <table class="widefat" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>Video URL</th>
                        <th style="width: 120px;">Added</th>
                        <th style="width: 150px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_videos as $video): ?>
                        <tr id="video-row-<?php echo $video->id; ?>">
                            <td><?php echo $video->id; ?></td>
                            <td>
                                <a href="<?php echo esc_url($video->original_video_url); ?>" target="_blank">
                                    <?php echo esc_html($video->original_video_url); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($video->date_saved); ?></td>
                            <td>
                                <button type="button" 
                                        class="button button-primary process-video-btn" 
                                        data-video-id="<?php echo $video->id; ?>"
                                        data-video-url="<?php echo esc_attr($video->original_video_url); ?>">
                                    Process Now
                                </button>
                                <span class="spinner" style="float: none; margin: 0 0 0 5px;"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="notice notice-warning" style="margin-top: 20px;">
                <p><strong>Note:</strong> Processing can take 20-120 seconds per video depending on length and size. Please be patient and don't close this page while processing.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Recent Logs -->
    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2>Recent Video Processing Logs</h2>
        <p>Real-time logs will appear here during processing. You can also view full logs in <a href="<?php echo admin_url('admin.php?page=nitter-logs'); ?>">Logs page</a>.</p>
        
        <div id="live-logs" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">
            <em style="color: #999;">Waiting for processing to start...</em>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var isProcessing = false;
    
    $('.process-video-btn').on('click', function() {
        if (isProcessing) {
            alert('Please wait for the current video to finish processing.');
            return;
        }
        
        var button = $(this);
        var videoId = button.data('video-id');
        var videoUrl = button.data('video-url');
        var spinner = button.next('.spinner');
        var row = $('#video-row-' + videoId);
        
        // Disable all buttons
        $('.process-video-btn').prop('disabled', true);
        button.text('Processing...');
        spinner.addClass('is-active');
        isProcessing = true;
        
        // Clear previous results
        $('#test-results').hide();
        $('#live-logs').html('<em style="color: #999;">Starting processing for video ID ' + videoId + '...</em>');
        
        // Start processing
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'nitter_process_video_manual',
                nonce: nitter_ajax.nonce,
                video_id: videoId
            },
            success: function(response) {
                isProcessing = false;
                $('.process-video-btn').prop('disabled', false);
                button.text('Process Now');
                spinner.removeClass('is-active');
                
                // Show results
                $('#test-results').show();
                
                if (response.success) {
                    var result = response.data;
                    var html = '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
                    html += '<strong style="color: #155724;">✅ Processing Successful!</strong>';
                    html += '</div>';
                    
                    html += '<table class="widefat">';
                    html += '<tr><th>Status</th><td>' + result.status + '</td></tr>';
                    
                    if (result.imgbb_url) {
                        html += '<tr><th>ImgBB URL</th><td><a href="' + result.imgbb_url + '" target="_blank">' + result.imgbb_url + '</a></td></tr>';
                        html += '<tr><th>File Size</th><td>' + (result.file_size / 1048576).toFixed(2) + ' MB</td></tr>';
                        html += '<tr><th>Duration</th><td>' + result.duration + ' seconds</td></tr>';
                        
                        // Show GIF preview
                        html += '<tr><th>Preview</th><td><img src="' + result.imgbb_url + '" style="max-width: 100%; height: auto; border: 1px solid #ddd;"></td></tr>';
                    }
                    
                    html += '</table>';
                    
                    $('#test-results-content').html(html);
                    
                    // Remove row from pending list
                    row.fadeOut();
                    
                } else {
                    var html = '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px;">';
                    html += '<strong style="color: #721c24;">❌ Processing Failed</strong><br>';
                    html += '<em>' + response.data + '</em>';
                    html += '</div>';
                    
                    $('#test-results-content').html(html);
                }
                
                // Load logs
                loadRecentLogs();
            },
            error: function(xhr, status, error) {
                isProcessing = false;
                $('.process-video-btn').prop('disabled', false);
                button.text('Process Now');
                spinner.removeClass('is-active');
                
                $('#test-results').show();
                $('#test-results-content').html(
                    '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px;">' +
                    '<strong style="color: #721c24;">❌ AJAX Error</strong><br>' +
                    '<em>' + error + '</em>' +
                    '</div>'
                );
            }
        });
    });
    
    // Function to load recent logs
    function loadRecentLogs() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'nitter_get_recent_logs',
                nonce: nitter_ajax.nonce,
                log_type: 'video_conversion',
                limit: 50
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var html = '';
                    response.data.forEach(function(log) {
                        var color = '#333';
                        if (log.message.includes('failed') || log.message.includes('error')) {
                            color = '#dc3232';
                        } else if (log.message.includes('successful') || log.message.includes('completed')) {
                            color = '#46b450';
                        }
                        
                        html += '<div style="margin-bottom: 5px; color: ' + color + ';">';
                        html += '<span style="color: #999;">[' + log.date_created + ']</span> ';
                        html += log.message;
                        html += '</div>';
                    });
                    $('#live-logs').html(html);
                    
                    // Scroll to bottom
                    $('#live-logs').scrollTop($('#live-logs')[0].scrollHeight);
                }
            }
        });
    }
});
</script>

<style>
.card {
    background: white;
    border: 1px solid #ccd0d4;
    padding: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.card h2 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.card h3 {
    margin-top: 20px;
}

.widefat th {
    font-weight: 600;
}

#live-logs {
    line-height: 1.6;
}
</style>