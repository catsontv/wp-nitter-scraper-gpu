<?php
if (!defined('ABSPATH')) {
    exit;
}

// Load tweets
add_action('wp_ajax_nitter_load_tweets', 'nitter_handle_load_tweets');
function nitter_handle_load_tweets() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : null;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
    
    $database = new Nitter_Database();
    $media_handler = new Nitter_Media_Handler();
    
    $tweets = $database->get_tweets($account_id, $limit, $offset);
    
    function twitter_time_ago($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return $time . 's';
        if ($time < 3600) return floor($time/60) . 'm';
        if ($time < 86400) return floor($time/3600) . 'h';
        if ($time < 2592000) return floor($time/86400) . 'd';
        
        return date('M j', strtotime($datetime));
    }
    
    ob_start();
    
    if (empty($tweets)): ?>
        <?php if ($offset === 0): ?>
            <div style="padding: 20px; text-align: center; color: #666;">
                No tweets found
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php foreach ($tweets as $tweet): ?>
            <?php
            // Get media (images + GIFs)
            $media = $database->get_tweet_images($tweet->id);
            $has_media = !empty($media);
            ?>
            <div class="nitter-tweet">
                <div class="nitter-tweet-content">
                    <div class="nitter-tweet-header">
                        <span class="nitter-tweet-name"><?php echo esc_html($tweet->account_username); ?></span>
                        <span class="nitter-tweet-username">
                            @<?php echo esc_html($tweet->account_username); ?>
                        </span>
                        <span class="nitter-tweet-separator">·</span>
                        <span class="nitter-tweet-time">
                            <?php echo esc_html(twitter_time_ago($tweet->tweet_date)); ?>
                        </span>
                    </div>
                    
                    <div class="nitter-tweet-text"><?php echo nl2br(esc_html($tweet->tweet_text)); ?></div>
                    
                    <?php if ($has_media): ?>
                        <?php
                        $media_count = count($media);
                        $grid_class = 'single';
                        
                        if ($media_count == 2) $grid_class = 'double';
                        elseif ($media_count == 3) $grid_class = 'triple';
                        elseif ($media_count >= 4) $grid_class = 'quad';
                        ?>
                        
                        <div class="nitter-tweet-media">
                            <div class="nitter-images-container">
                                <div class="nitter-images-grid <?php echo esc_attr($grid_class); ?>">
                                    <?php
                                    $display_count = min($media_count, 4);
                                    for ($i = 0; $i < $display_count; $i++):
                                        if (!isset($media[$i])) continue;
                                        
                                        $item = $media[$i];
                                        
                                        // Check if it's a GIF (has imgbb_url and conversion completed)
                                        if ($item->media_type === 'gif' && $item->conversion_status === 'completed' && !empty($item->imgbb_url)):
                                    ?>
                                            <div class="nitter-media-item nitter-gif-item" style="position: relative;">
                                                <img src="<?php echo esc_url($item->imgbb_url); ?>" 
                                                     class="nitter-tweet-image" 
                                                     alt="GIF"
                                                     data-full-url="<?php echo esc_url($item->imgbb_url); ?>"
                                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;">
                                                <div class="nitter-gif-badge" style="position: absolute; bottom: 8px; left: 8px; background: rgba(0,0,0,0.6); color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">GIF</div>
                                                <button class="nitter-copy-gif-url" 
                                                        data-url="<?php echo esc_attr($item->imgbb_url); ?>"
                                                        style="position: absolute; top: 8px; right: 8px; background: #1d9bf0; color: white; border: none; padding: 6px 12px; border-radius: 16px; font-size: 12px; cursor: pointer; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.2); z-index: 10;"
                                                        title="Copy ImgBB URL">
                                                    📋 Copy URL
                                                </button>
                                            </div>
                                    <?php
                                        // Check if it's a regular image
                                        elseif ($item->media_type === 'image' && $item->wordpress_attachment_id):
                                            $image_url = $media_handler->get_image_url($item->wordpress_attachment_id, 'full');
                                            if ($image_url):
                                    ?>
                                                <img src="<?php echo esc_url($image_url); ?>" 
                                                     class="nitter-tweet-image" 
                                                     alt="Tweet image"
                                                     data-full-url="<?php echo esc_url($image_url); ?>">
                                    <?php
                                            endif;
                                        endif;
                                    endfor;
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="nitter-tweet-footer">
                        <span class="nitter-tweet-stat">
                            <?php echo esc_html(date('M j, Y \a\t g:i A', strtotime($tweet->tweet_date))); ?>
                        </span>
                        <?php if ($has_media): ?>
                            <?php
                            $image_count = 0;
                            $gif_count = 0;
                            foreach ($media as $item) {
                                if ($item->media_type === 'gif' && $item->conversion_status === 'completed') {
                                    $gif_count++;
                                } elseif ($item->media_type === 'image') {
                                    $image_count++;
                                }
                            }
                            ?>
                            <?php if ($image_count > 0): ?>
                                <span class="nitter-tweet-stat">
                                    <?php echo esc_html($image_count); ?> image<?php echo $image_count > 1 ? 's' : ''; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($gif_count > 0): ?>
                                <span class="nitter-tweet-stat" style="color: #1d9bf0;">
                                    🎬 <?php echo esc_html($gif_count); ?> GIF<?php echo $gif_count > 1 ? 's' : ''; ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif;
    
    $html = ob_get_clean();
    
    wp_send_json_success(array(
        'html' => $html,
        'count' => count($tweets)
    ));
}

// Rest of the file remains the same...
// Get tweet details
add_action('wp_ajax_nitter_get_tweet_details', 'nitter_handle_get_tweet_details');
function nitter_handle_get_tweet_details() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $tweet_id = intval($_POST['tweet_id']);
    
    if (!$tweet_id) {
        wp_send_json_error('Invalid tweet ID');
    }
    
    global $wpdb;
    $tweets_table = $wpdb->prefix . 'nitter_tweets';
    $accounts_table = $wpdb->prefix . 'nitter_accounts';
    
    $tweet = $wpdb->get_row($wpdb->prepare(
        "SELECT t.*, a.account_username 
         FROM $tweets_table t 
         LEFT JOIN $accounts_table a ON t.account_id = a.id 
         WHERE t.id = %d",
        $tweet_id
    ));
    
    if (!$tweet) {
        wp_send_json_error('Tweet not found');
    }
    
    $database = new Nitter_Database();
    $images = $database->get_tweet_images($tweet_id);
    
    $tweet_data = array(
        'id' => $tweet->id,
        'tweet_id' => $tweet->tweet_id,
        'account_username' => $tweet->account_username,
        'tweet_text' => $tweet->tweet_text,
        'tweet_date' => $tweet->tweet_date,
        'images_count' => $tweet->images_count,
        'date_scraped' => $tweet->date_scraped,
        'images' => array()
    );
    
    foreach ($images as $image) {
        $tweet_data['images'][] = array(
            'id' => $image->id,
            'image_url' => $image->image_url,
            'wordpress_attachment_id' => $image->wordpress_attachment_id,
            'date_saved' => $image->date_saved
        );
    }
    
    wp_send_json_success($tweet_data);
}

// Delete tweet
add_action('wp_ajax_nitter_delete_tweet', 'nitter_handle_delete_tweet');
function nitter_handle_delete_tweet() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $tweet_id = intval($_POST['tweet_id']);
    
    if (!$tweet_id) {
        wp_send_json_error('Invalid tweet ID');
    }
    
    $database = new Nitter_Database();
    $media_handler = new Nitter_Media_Handler();
    
    // Delete images first
    $deleted_images = $media_handler->delete_tweet_images($tweet_id);
    
    // Delete tweet
    global $wpdb;
    $tweets_table = $wpdb->prefix . 'nitter_tweets';
    $images_table = $wpdb->prefix . 'nitter_images';
    
    $wpdb->delete($images_table, array('tweet_id' => $tweet_id), array('%d'));
    $result = $wpdb->delete($tweets_table, array('id' => $tweet_id), array('%d'));
    
    if ($result) {
        $database->add_log('cleanup', "Tweet deleted manually (ID: $tweet_id) with $deleted_images images");
        wp_send_json_success('Tweet deleted successfully');
    } else {
        wp_send_json_error('Failed to delete tweet');
    }
}

// Get tweets count
add_action('wp_ajax_nitter_get_tweets_count', 'nitter_handle_get_tweets_count');
function nitter_handle_get_tweets_count() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : null;
    
    global $wpdb;
    $tweets_table = $wpdb->prefix . 'nitter_tweets';
    
    if ($account_id) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tweets_table WHERE account_id = %d",
            $account_id
        ));
    } else {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $tweets_table");
    }
    
    wp_send_json_success(array('count' => intval($count)));
}

// Search tweets (keeping as is for now)
add_action('wp_ajax_nitter_search_tweets', 'nitter_handle_search_tweets');
function nitter_handle_search_tweets() {
    if (!wp_verify_nonce($_POST['nonce'], 'nitter_ajax_nonce')) {
        wp_send_json_error('Security check failed');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $search_term = sanitize_text_field($_POST['search_term']);
    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : null;
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    
    if (empty($search_term)) {
        wp_send_json_error('Search term is required');
    }
    
    global $wpdb;
    $tweets_table = $wpdb->prefix . 'nitter_tweets';
    $accounts_table = $wpdb->prefix . 'nitter_accounts';
    
    $where_clause = "WHERE t.tweet_text LIKE %s";
    $params = array('%' . $wpdb->esc_like($search_term) . '%');
    
    if ($account_id) {
        $where_clause .= " AND t.account_id = %d";
        $params[] = $account_id;
    }
    
    $params[] = $limit;
    $params[] = $offset;
    
    $sql = "SELECT t.*, a.account_username 
            FROM $tweets_table t 
            LEFT JOIN $accounts_table a ON t.account_id = a.id 
            $where_clause 
            ORDER BY t.tweet_date DESC 
            LIMIT %d OFFSET %d";
    
    $tweets = $wpdb->get_results($wpdb->prepare($sql, $params));
    
    wp_send_json_success(array(
        'html' => '',
        'count' => count($tweets)
    ));
}