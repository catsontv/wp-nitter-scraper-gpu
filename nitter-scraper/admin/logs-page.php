<?php
if (!defined('ABSPATH')) {
    exit;
}

global $nitter_admin;
$database = $nitter_admin->get_database();

$message = '';
$message_type = '';

if (isset($_POST['clear_logs']) && wp_verify_nonce($_POST['nonce'], 'nitter_clear_logs')) {
    $result = $database->clear_logs();
    if ($result !== false) {
        $message = 'Logs cleared successfully';
        $message_type = 'success';
    } else {
        $message = 'Failed to clear logs';
        $message_type = 'error';
    }
}

$log_type_filter = isset($_GET['log_type']) ? sanitize_text_field($_GET['log_type']) : 'all';
$logs = $database->get_logs(200, $log_type_filter);

function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return $time . 's';
    if ($time < 3600) return floor($time/60) . 'm';
    if ($time < 86400) return floor($time/3600) . 'h';
    if ($time < 2592000) return floor($time/86400) . 'd';
    
    return date('M j', strtotime($datetime));
}
?>

<div class="nitter-admin-wrap">
    <h1>Nitter Scraper - Logs</h1>
    
    <?php if ($message): ?>
        <div class="nitter-message <?php echo esc_attr($message_type); ?>">
            <?php echo esc_html($message); ?>
        </div>
    <?php endif; ?>
    
    <div class="nitter-form">
        <div class="nitter-form-row">
            <label for="log-type-filter">Filter by Type:</label>
            <select id="log-type-filter" onchange="location.href='?page=nitter-logs&log_type='+this.value">
                <option value="all" <?php selected($log_type_filter, 'all'); ?>>All</option>
                <option value="scrape_image" <?php selected($log_type_filter, 'scrape_image'); ?>>Image Scraping</option>
                <option value="scrape_video" <?php selected($log_type_filter, 'scrape_video'); ?>>Video Scraping</option>
                <option value="conversion" <?php selected($log_type_filter, 'conversion'); ?>>Video Conversion</option>
                <option value="upload" <?php selected($log_type_filter, 'upload'); ?>>Upload</option>
                <option value="feed" <?php selected($log_type_filter, 'feed'); ?>>Feed</option>
                <option value="feed_reconciliation" <?php selected($log_type_filter, 'feed_reconciliation'); ?>>Feed Reconciliation</option>
                <option value="cron" <?php selected($log_type_filter, 'cron'); ?>>Cron</option>
                <option value="system" <?php selected($log_type_filter, 'system'); ?>>System</option>
            </select>
            
            <button type="button" id="nitter-refresh-logs" class="nitter-btn" onclick="location.reload()">Refresh Logs</button>
            
            <form method="post" style="display: inline; margin-left: 10px;">
                <?php wp_nonce_field('nitter_clear_logs', 'nonce'); ?>
                <button type="submit" name="clear_logs" class="nitter-btn nitter-btn-danger"
                        onclick="return confirm('Are you sure you want to clear all logs?')">
                    Clear All Logs
                </button>
            </form>
        </div>
    </div>
    
    <h2>Recent Activity <?php if ($log_type_filter !== 'all') echo '(' . esc_html(ucwords(str_replace('_', ' ', $log_type_filter))) . ')'; ?></h2>
    
    <div id="nitter-logs-container" class="nitter-logs-container">
        <?php if (empty($logs)): ?>
            <div style="padding: 20px; text-align: center; color: #666;">
                No logs available
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="nitter-log-entry">
                    <span class="nitter-log-time">
                        <?php echo esc_html(date('H:i:s', strtotime($log->date_created))); ?>
                        <small>(<?php echo esc_html(time_ago($log->date_created)); ?>)</small>
                    </span>
                    
                    <span class="nitter-log-type <?php echo esc_attr($log->log_type); ?>">
                        <?php echo esc_html($log->log_type); ?>
                    </span>
                    
                    <span class="nitter-log-message">
                        <?php echo esc_html($log->message); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var $logsContainer = $('#nitter-logs-container');
    if ($logsContainer.length) {
        $logsContainer.scrollTop($logsContainer[0].scrollHeight);
    }
});
</script>