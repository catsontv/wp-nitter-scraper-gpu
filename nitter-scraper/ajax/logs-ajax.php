<?php
if (!defined('ABSPATH')) {
    exit;
}

// Clear logs
add_action('wp_ajax_nitter_clear_logs', 'nitter_handle_clear_logs');
function nitter_handle_clear_logs() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $database = new Nitter_Database();
    $result = $database->clear_logs();
    
    if ($result !== false) {
        wp_send_json_success('Logs cleared successfully');
    } else {
        wp_send_json_error('Failed to clear logs');
    }
}

// Refresh logs
add_action('wp_ajax_nitter_refresh_logs', 'nitter_handle_refresh_logs');
function nitter_handle_refresh_logs() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $database = new Nitter_Database();
    $logs = $database->get_logs(200);
    
    function time_ago($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return $time . 's';
        if ($time < 3600) return floor($time/60) . 'm';
        if ($time < 86400) return floor($time/3600) . 'h';
        if ($time < 2592000) return floor($time/86400) . 'd';
        
        return date('M j', strtotime($datetime));
    }
    
    ob_start();
    
    if (empty($logs)): ?>
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
    <?php endif;
    
    $html = ob_get_clean();
    
    wp_send_json_success(array('html' => $html));
}

// Get service logs
add_action('wp_ajax_nitter_get_service_logs', 'nitter_handle_get_service_logs');
function nitter_handle_get_service_logs() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $api = new Nitter_API();
    $result = $api->get_service_logs();
    
    if ($result['success']) {
        wp_send_json_success($result['logs']);
    } else {
        wp_send_json_error($result['message']);
    }
}

// Export logs
add_action('wp_ajax_nitter_export_logs', 'nitter_handle_export_logs');
function nitter_handle_export_logs() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $database = new Nitter_Database();
    $logs = $database->get_logs(1000);
    
    $csv_data = "Date,Time,Type,Message\n";
    
    foreach ($logs as $log) {
        $date = date('Y-m-d', strtotime($log->date_created));
        $time = date('H:i:s', strtotime($log->date_created));
        $type = $log->log_type;
        $message = str_replace('"', '""', $log->message);
        
        $csv_data .= "\"{$date}\",\"{$time}\",\"{$type}\",\"{$message}\"\n";
    }
    
    $filename = 'nitter-logs-' . date('Y-m-d-H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv_data));
    
    echo $csv_data;
    exit;
}

// Get log statistics
add_action('wp_ajax_nitter_get_log_stats', 'nitter_handle_get_log_stats');
function nitter_handle_get_log_stats() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    global $wpdb;
    $logs_table = $wpdb->prefix . 'nitter_logs';
    
    // Get log counts by type
    $type_counts = $wpdb->get_results(
        "SELECT log_type, COUNT(*) as count 
         FROM $logs_table 
         GROUP BY log_type 
         ORDER BY count DESC"
    );
    
    // Get recent activity (last 24 hours)
    $recent_count = $wpdb->get_var(
        "SELECT COUNT(*) 
         FROM $logs_table 
         WHERE date_created >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    
    // Get total logs
    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table");
    
    // Get oldest log date
    $oldest_log = $wpdb->get_var("SELECT MIN(date_created) FROM $logs_table");
    
    $stats = array(
        'total_logs' => intval($total_count),
        'recent_activity' => intval($recent_count),
        'oldest_log' => $oldest_log,
        'type_breakdown' => array()
    );
    
    foreach ($type_counts as $type_count) {
        $stats['type_breakdown'][$type_count->log_type] = intval($type_count->count);
    }
    
    wp_send_json_success($stats);
}
