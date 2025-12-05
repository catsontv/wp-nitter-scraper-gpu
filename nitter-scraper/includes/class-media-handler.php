<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nitter_Media_Handler {
    
    private $database;
    
    public function __construct() {
        $this->database = new Nitter_Database();
    }
    
    public function save_image_to_media_library($image_data) {
        if (empty($image_data)) {
            return false;
        }
        
        if (is_array($image_data) && isset($image_data['data'])) {
            return $this->save_base64_image($image_data);
        }
        
        return false;
    }
    
    private function save_base64_image($image_data) {
        if (!isset($image_data['data']) || !isset($image_data['mimeType'])) {
            $this->database->add_log('upload', 'Invalid image data format');
            return false;
        }
        
        if (!function_exists('wp_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $mime_parts = explode('/', $image_data['mimeType']);
        $extension = isset($mime_parts[1]) ? $mime_parts[1] : 'jpg';
        if ($extension === 'jpeg') $extension = 'jpg';
        
        $filename = 'nitter_' . time() . '_' . wp_generate_password(8, false) . '.' . $extension;
        
        $binary_data = base64_decode($image_data['data']);
        
        if ($binary_data === false) {
            $this->database->add_log('upload', 'Failed to decode base64 image data');
            return false;
        }
        
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['path'] . '/' . $filename;
        
        $bytes_written = file_put_contents($upload_path, $binary_data);
        
        if ($bytes_written === false) {
            $this->database->add_log('upload', 'Failed to write image data to file');
            return false;
        }
        
        $attachment = array(
            'post_mime_type' => $image_data['mimeType'],
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => $upload_dir['url'] . '/' . basename($filename)
        );
        
        $attach_id = wp_insert_attachment($attachment, $upload_path);
        
        if (is_wp_error($attach_id)) {
            $this->database->add_log('upload', 'Failed to insert attachment: ' . $attach_id->get_error_message());
            unlink($upload_path);
            return false;
        }
        
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload_path);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        update_post_meta($attach_id, '_wp_attachment_image_alt', 'Image from Nitter scraper');
        if (isset($image_data['url'])) {
            update_post_meta($attach_id, '_nitter_original_url', $image_data['url']);
        }
        
        $this->database->add_log('upload', 'Image saved to media library: ' . $filename . ' (ID: ' . $attach_id . ')');
        return $attach_id;
    }
    
    public function get_image_html($attachment_id, $size = 'medium') {
        if (!$attachment_id) {
            return '';
        }
        
        $image = wp_get_attachment_image($attachment_id, $size, false, array(
            'class' => 'nitter-tweet-image',
            'loading' => 'lazy'
        ));
        
        return $image;
    }
    
    public function get_image_url($attachment_id, $size = 'medium') {
        if (!$attachment_id) {
            return '';
        }
        
        $image_data = wp_get_attachment_image_src($attachment_id, $size);
        
        if ($image_data) {
            return $image_data[0];
        }
        
        return '';
    }
    
    public function delete_tweet_images($tweet_id) {
        $images = $this->database->get_tweet_images($tweet_id);
        $deleted_count = 0;
        
        foreach ($images as $image) {
            if ($image->wordpress_attachment_id) {
                $result = wp_delete_attachment($image->wordpress_attachment_id, true);
                if ($result) {
                    $deleted_count++;
                }
            }
        }
        
        if ($deleted_count > 0) {
            $this->database->add_log('scrape_image', "Deleted $deleted_count images for tweet ID: $tweet_id");
        }
        
        return $deleted_count;
    }
    
    public function get_media_library_stats() {
        global $wpdb;
        
        $nitter_images = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'attachment' 
             AND pm.meta_key = '_wp_attachment_image_alt' 
             AND pm.meta_value = 'Image from Nitter scraper'"
        );
        
        return array(
            'total_images' => intval($nitter_images),
            'total_size' => '0 B'
        );
    }
    
    public function cleanup_orphaned_images() {
        global $wpdb;
        
        $orphaned_images = $wpdb->get_results(
            "SELECT p.ID FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             LEFT JOIN {$wpdb->prefix}nitter_images ni ON p.ID = ni.wordpress_attachment_id 
             WHERE p.post_type = 'attachment' 
             AND pm.meta_key = '_wp_attachment_image_alt' 
             AND pm.meta_value = 'Image from Nitter scraper' 
             AND ni.wordpress_attachment_id IS NULL"
        );
        
        $deleted_count = 0;
        foreach ($orphaned_images as $image) {
            if (wp_delete_attachment($image->ID, true)) {
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            $this->database->add_log('scrape_image', "Cleaned up $deleted_count orphaned images");
        }
        
        return $deleted_count;
    }
}