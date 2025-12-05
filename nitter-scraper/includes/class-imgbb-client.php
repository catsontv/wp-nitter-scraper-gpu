<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nitter_ImgBB_Client {
    
    private $api_key;
    private $api_endpoint = 'https://api.imgbb.com/1/upload';
    private $database;
    
    public function __construct() {
        $this->database = nitter_get_database();
        $this->api_key = $this->database->get_setting('imgbb_api_key', '');
    }
    
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    public function test_connection() {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'error' => 'API key not configured'
            );
        }
        
        $test_image = base64_encode(file_get_contents('data://image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        
        $response = $this->upload_base64($test_image, 'test_connection');
        
        if ($response['success']) {
            return array(
                'success' => true,
                'message' => 'ImgBB API connection successful'
            );
        }
        
        return $response;
    }
    
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
        
        $image_data = file_get_contents($file_path);
        $base64_image = base64_encode($image_data);
        
        if (!$name) {
            $name = basename($file_path, '.' . pathinfo($file_path, PATHINFO_EXTENSION));
        }
        
        return $this->upload_base64($base64_image, $name);
    }
    
    private function upload_base64($base64_image, $name = null) {
        $this->database->add_log('upload', 'Uploading to ImgBB...');
        
        $post_data = array(
            'key' => $this->api_key,
            'image' => $base64_image
        );
        
        if ($name) {
            $post_data['name'] = sanitize_title($name);
        }
        
        $response = wp_remote_post($this->api_endpoint, array(
            'timeout' => 120,
            'body' => $post_data
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->database->add_log('upload', 'HTTP Error: ' . $error_message);
            
            return array(
                'success' => false,
                'error' => 'HTTP Error: ' . $error_message
            );
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $data = json_decode($body, true);
        
        if ($http_code !== 200) {
            $error = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            $this->database->add_log('upload', "Upload failed (HTTP $http_code): $error");
            
            return array(
                'success' => false,
                'error' => "ImgBB Error (HTTP $http_code): $error",
                'http_code' => $http_code
            );
        }
        
        if (empty($data['success']) || !isset($data['data'])) {
            $error = isset($data['error']['message']) ? $data['error']['message'] : 'Invalid response';
            $this->database->add_log('upload', 'Upload failed: ' . $error);
            
            return array(
                'success' => false,
                'error' => 'ImgBB Error: ' . $error
            );
        }
        
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
        $this->database->add_log('upload', "Upload successful: {$result['url']} ({$size_mb}MB, {$result['width']}x{$result['height']})");
        
        return $result;
    }
    
    public function delete_image($delete_url) {
        if (empty($delete_url)) {
            return array(
                'success' => false,
                'error' => 'No delete URL provided'
            );
        }
        
        $this->database->add_log('upload', 'Deleting image from ImgBB: ' . $delete_url);
        
        $response = wp_remote_get($delete_url, array(
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->database->add_log('upload', 'Delete failed: ' . $error_message);
            
            return array(
                'success' => false,
                'error' => 'HTTP Error: ' . $error_message
            );
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        
        if ($http_code === 200) {
            $this->database->add_log('upload', 'Image deleted successfully');
            return array('success' => true);
        }
        
        return array(
            'success' => false,
            'error' => "Delete failed with HTTP code: $http_code"
        );
    }
    
    public function get_config_status() {
        $status = array(
            'configured' => $this->is_configured(),
            'api_key_set' => !empty($this->api_key),
            'api_key_length' => strlen($this->api_key),
            'api_endpoint' => $this->api_endpoint
        );
        
        return $status;
    }
    
    public function validate_api_key($api_key) {
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
        
        if (!preg_match('/^[a-zA-Z0-9]+$/', $api_key)) {
            return array(
                'valid' => false,
                'error' => 'API key should only contain alphanumeric characters'
            );
        }
        
        return array('valid' => true);
    }
}