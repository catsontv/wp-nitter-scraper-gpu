<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ImgBB API Client
 * Handles uploading images/GIFs to ImgBB and managing responses
 * Phase 4: ImgBB Integration
 */
class Nitter_ImgBB_Client {
    
    private $api_key;
    private $api_endpoint = 'https://api.imgbb.com/1/upload';
    private $database;
    
    public function __construct() {
        $this->database = nitter_get_database();
        $this->api_key = $this->database->get_setting('imgbb_api_key', '');
    }
    
    /**
     * Check if ImgBB is properly configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    /**
     * Test connection to ImgBB API
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'error' => 'API key not configured'
            );
        }
        
        // Create a tiny test image (1x1 transparent PNG)
        $test_image = base64_encode(file_get_contents('data://image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        
        $response = $this->upload_base64($test_image, 'test_connection');
        
        if ($response['success']) {
            // Delete the test image if we got a delete URL
            if (!empty($response['delete_url'])) {
                // We don't actually need to delete it, just confirm API works
            }
            
            return array(
                'success' => true,
                'message' => 'ImgBB API connection successful'
            );
        }
        
        return $response;
    }
    
    /**
     * Upload a file to ImgBB
     * 
     * @param string $file_path Full path to the file
     * @param string $name Optional name for the image
     * @return array Response with success, url, delete_url, etc.
     */
    public function upload_file($file_path, $name = null) {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'error' => 'ImgBB API key not configured'
            );
        }
        
        if (!file_exists($file_path)) {
            return array(
                'success' => false,
                'error' => 'File not found: ' . $file_path
            );
        }
        
        // Read file and encode to base64
        $image_data = file_get_contents($file_path);
        $base64_image = base64_encode($image_data);
        
        if (!$name) {
            $name = basename($file_path, '.' . pathinfo($file_path, PATHINFO_EXTENSION));
        }
        
        return $this->upload_base64($base64_image, $name);
    }
    
    /**
     * Upload base64 encoded image to ImgBB
     * 
     * @param string $base64_image Base64 encoded image data
     * @param string $name Optional name for the image
     * @return array Response with success, url, delete_url, etc.
     */
    private function upload_base64($base64_image, $name = null) {
        $this->database->add_log('imgbb', 'Uploading to ImgBB...');
        
        // Prepare POST data
        $post_data = array(
            'key' => $this->api_key,
            'image' => $base64_image
        );
        
        if ($name) {
            $post_data['name'] = sanitize_title($name);
        }
        
        // Use WordPress HTTP API
        $response = wp_remote_post($this->api_endpoint, array(
            'timeout' => 30,
            'body' => $post_data
        ));
        
        // Check for HTTP errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->database->add_log('imgbb', 'HTTP Error: ' . $error_message);
            
            return array(
                'success' => false,
                'error' => 'HTTP Error: ' . $error_message
            );
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Parse JSON response
        $data = json_decode($body, true);
        
        if ($http_code !== 200) {
            $error = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            $this->database->add_log('imgbb', "Upload failed (HTTP $http_code): $error");
            
            return array(
                'success' => false,
                'error' => "ImgBB Error (HTTP $http_code): $error",
                'http_code' => $http_code
            );
        }
        
        // Check if response is successful
        if (empty($data['success']) || !isset($data['data'])) {
            $error = isset($data['error']['message']) ? $data['error']['message'] : 'Invalid response';
            $this->database->add_log('imgbb', 'Upload failed: ' . $error);
            
            return array(
                'success' => false,
                'error' => 'ImgBB Error: ' . $error
            );
        }
        
        // Extract relevant data
        $result = array(
            'success' => true,
            'url' => $data['data']['url'],
            'display_url' => $data['data']['display_url'],
            'delete_url' => isset($data['data']['delete_url']) ? $data['data']['delete_url'] : '',
            'width' => $data['data']['width'],
            'height' => $data['data']['height'],
            'size' => $data['data']['size'],
            'expiration' => isset($data['data']['expiration']) ? $data['data']['expiration'] : null,
            'id' => $data['data']['id']
        );
        
        $size_mb = round($result['size'] / (1024 * 1024), 2);
        $this->database->add_log('imgbb', "Upload successful: {$result['url']} ({$size_mb}MB, {$result['width']}x{$result['height']})");
        
        return $result;
    }
    
    /**
     * Delete an image from ImgBB using delete URL
     * 
     * @param string $delete_url The delete URL from ImgBB
     * @return array Response with success status
     */
    public function delete_image($delete_url) {
        if (empty($delete_url)) {
            return array(
                'success' => false,
                'error' => 'No delete URL provided'
            );
        }
        
        $this->database->add_log('imgbb', 'Deleting image from ImgBB: ' . $delete_url);
        
        // Use WordPress HTTP API
        $response = wp_remote_get($delete_url, array(
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->database->add_log('imgbb', 'Delete failed: ' . $error_message);
            
            return array(
                'success' => false,
                'error' => 'HTTP Error: ' . $error_message
            );
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        
        if ($http_code === 200) {
            $this->database->add_log('imgbb', 'Image deleted successfully');
            return array('success' => true);
        }
        
        return array(
            'success' => false,
            'error' => "Delete failed with HTTP code: $http_code"
        );
    }
    
    /**
     * Get API configuration status for admin display
     */
    public function get_config_status() {
        $status = array(
            'configured' => $this->is_configured(),
            'api_key_set' => !empty($this->api_key),
            'api_key_length' => strlen($this->api_key),
            'api_endpoint' => $this->api_endpoint
        );
        
        return $status;
    }
    
    /**
     * Validate API key format (basic check)
     */
    public function validate_api_key($api_key) {
        // ImgBB API keys are typically 32 characters
        if (empty($api_key)) {
            return array(
                'valid' => false,
                'error' => 'API key cannot be empty'
            );
        }
        
        if (strlen($api_key) < 20) {
            return array(
                'valid' => false,
                'error' => 'API key appears to be too short'
            );
        }
        
        // Only alphanumeric characters expected
        if (!preg_match('/^[a-zA-Z0-9]+$/', $api_key)) {
            return array(
                'valid' => false,
                'error' => 'API key should only contain alphanumeric characters'
            );
        }
        
        return array('valid' => true);
    }
}