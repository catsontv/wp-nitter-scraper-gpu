<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nitter_API {
    
    private $node_service_url;
    private $database;
    
    public function __construct() {
        $this->node_service_url = 'http://localhost:3001';
        $this->database = new Nitter_Database();
    }
    
    public function test_service() {
        $response = wp_remote_get($this->node_service_url . '/status', array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            $this->database->add_log('api', 'Service test failed: ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            $this->database->add_log('api', 'Service test successful');
            return array(
                'success' => true,
                'message' => 'Node.js service is running',
                'data' => json_decode($body, true)
            );
        } else {
            $this->database->add_log('api', 'Service test failed with status: ' . $status_code);
            return array(
                'success' => false,
                'message' => 'Service returned status: ' . $status_code
            );
        }
    }
    
    public function test_instance($instance_url) {
        $response = wp_remote_post($this->node_service_url . '/test-instance', array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'instance_url' => $instance_url
            ))
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Test failed: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 200 && isset($data['success']) && $data['success']) {
            return array(
                'success' => true,
                'message' => 'Instance is working',
                'response_time' => isset($data['response_time']) ? $data['response_time'] : null
            );
        } else {
            return array(
                'success' => false,
                'message' => isset($data['message']) ? $data['message'] : 'Instance test failed'
            );
        }
    }
    
    public function scrape_account($account_id) {
        $account = $this->database->get_account($account_id);
        if (!$account) {
            return array(
                'success' => false,
                'message' => 'Account not found'
            );
        }
        
        $instances = $this->database->get_active_instances();
        if (empty($instances)) {
            $this->database->add_log('scraping', 'No active instances available for scraping');
            return array(
                'success' => false,
                'message' => 'No active instances available'
            );
        }
        
        $this->database->add_log('scraping', 'Starting scrape for account: ' . $account->account_username);
        
        $response = wp_remote_post($this->node_service_url . '/scrape-account', array(
            'timeout' => 300, // 5 minutes timeout for scraping
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'account_url' => $account->account_url,
                'account_username' => $account->account_username,
                'account_id' => $account->id,
                'instances' => array_map(function($instance) {
                    return $instance->instance_url;
                }, $instances),
                'callback_url' => home_url('/wp-admin/admin-ajax.php?action=nitter_receive_scraped_data')
            ))
        ));
        
        if (is_wp_error($response)) {
            $error_message = 'Scraping failed: ' . $response->get_error_message();
            $this->database->add_log('scraping', $error_message);
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 200 && isset($data['success']) && $data['success']) {
            $this->database->update_last_scraped($account_id);
            $this->database->add_log('scraping', 'Scraping started successfully for: ' . $account->account_username);
            return array(
                'success' => true,
                'message' => 'Scraping started successfully'
            );
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Scraping request failed';
            $this->database->add_log('scraping', 'Scraping failed for ' . $account->account_username . ': ' . $error_message);
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * PHASE 2: Enhanced receive_scraped_data to handle both images and videos
     */
    public function receive_scraped_data($data) {
        if (!isset($data['account_id']) || !isset($data['tweets'])) {
            $this->database->add_log('scraping', 'Invalid scraped data received');
            return array(
                'success' => false,
                'message' => 'Invalid data format'
            );
        }
        
        $account_id = intval($data['account_id']);
        $tweets = $data['tweets'];
        $tweets_added = 0;
        $images_added = 0;
        $videos_added = 0;
        
        $media_handler = new Nitter_Media_Handler();
        
        // Check if video scraping is enabled
        $video_scraping_enabled = $this->database->get_setting('enable_video_scraping', '0') === '1';
        
        foreach ($tweets as $tweet_data) {
            if (!isset($tweet_data['tweet_id']) || !isset($tweet_data['tweet_text']) || !isset($tweet_data['tweet_date'])) {
                continue;
            }
            
            // Check if tweet already exists
            global $wpdb;
            $tweets_table = $wpdb->prefix . 'nitter_tweets';
            $existing_tweet = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $tweets_table WHERE tweet_id = %s",
                $tweet_data['tweet_id']
            ));
            
            if ($existing_tweet) {
                continue;
            }
            
            // PHASE 2: Determine media type and count
            $media_type = isset($tweet_data['media_type']) ? $tweet_data['media_type'] : 'image';
            $is_video = $media_type === 'video';
            
            // Count media items
            if ($is_video) {
                $media_count = isset($tweet_data['video_url']) && !empty($tweet_data['video_url']) ? 1 : 0;
            } else {
                $media_count = isset($tweet_data['images']) ? count($tweet_data['images']) : 0;
            }
            
            // Add tweet to database
            $tweet_result = $this->database->add_tweet(
                $account_id,
                $tweet_data['tweet_id'],
                $tweet_data['tweet_text'],
                $tweet_data['tweet_date'],
                $media_count
            );
            
            if ($tweet_result) {
                $tweets_added++;
                $tweet_db_id = $this->database->get_last_insert_id();
                
                // PHASE 2: Handle videos
                if ($is_video && isset($tweet_data['video_url']) && !empty($tweet_data['video_url'])) {
                    if ($video_scraping_enabled) {
                        // Add video entry with pending status
                        $video_result = $this->database->add_video_entry($tweet_db_id, $tweet_data['video_url']);
                        
                        if ($video_result) {
                            $videos_added++;
                            $this->database->add_log('video', "Video entry added (pending): Tweet ID {$tweet_data['tweet_id']}, URL: {$tweet_data['video_url']}");
                        } else {
                            $this->database->add_log('video', "Failed to add video entry for Tweet ID {$tweet_data['tweet_id']}", 'error');
                        }
                    } else {
                        $this->database->add_log('video', "Video skipped (video scraping disabled): Tweet ID {$tweet_data['tweet_id']}");
                    }
                }
                // Handle images if present (existing logic)
                elseif (isset($tweet_data['images']) && is_array($tweet_data['images'])) {
                    foreach ($tweet_data['images'] as $image_data) {
                        $attachment_id = $media_handler->save_image_to_media_library($image_data);
                        
                        if ($attachment_id) {
                            $image_url = isset($image_data['url']) ? $image_data['url'] : 'base64_image';
                            $image_result = $this->database->add_image($tweet_db_id, $image_url, $attachment_id);
                            
                            if ($image_result) {
                                $images_added++;
                            }
                        }
                    }
                }
            }
        }
        
        $account = $this->database->get_account($account_id);
        $account_name = $account ? $account->account_username : 'Unknown';
        
        // PHASE 2: Enhanced logging with video count
        if ($videos_added > 0) {
            $this->database->add_log('scraping', "Scraping completed for $account_name: $tweets_added tweets, $images_added images, $videos_added videos (pending conversion)");
        } else {
            $this->database->add_log('scraping', "Scraping completed for $account_name: $tweets_added tweets, $images_added images added");
        }
        
        return array(
            'success' => true,
            'message' => "Added $tweets_added tweets, $images_added images, and $videos_added videos",
            'tweets_added' => $tweets_added,
            'images_added' => $images_added,
            'videos_added' => $videos_added
        );
    }
    
    public function get_service_logs() {
        $response = wp_remote_get($this->node_service_url . '/logs', array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Failed to get logs: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            $data = json_decode($body, true);
            return array(
                'success' => true,
                'logs' => isset($data['logs']) ? $data['logs'] : array()
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to retrieve logs'
            );
        }
    }
    
    public function extract_username_from_url($url) {
        // Handle both x.com and nitter instance URLs
        $patterns = array(
            '/(?:x\.com|twitter\.com)\/([a-zA-Z0-9_]+)/',
            '/(?:nitter\.[a-zA-Z0-9.-]+|xcancel\.com|lightbrd\.com|nuku\.trabun\.org)\/([a-zA-Z0-9_]+)/'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        
        return false;
    }
    
    public function convert_to_nitter_url($url, $instance_url = 'https://nitter.net') {
        $username = $this->extract_username_from_url($url);
        if ($username) {
            return rtrim($instance_url, '/') . '/' . $username;
        }
        
        return $url;
    }
    
    public function get_random_instance() {
        $instances = $this->database->get_active_instances();
        if (empty($instances)) {
            return null;
        }
        
        // Get least recently used instance
        return $instances[0];
    }
}