<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nitter_Admin {
    
    private $database;
    private $api;
    private $media_handler;
    private $cron_handler;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Store references when WordPress is ready
        add_action('admin_init', array($this, 'init_references'));
    }
    
    public function init_references() {
        $this->database = nitter_get_database();
        $this->api = nitter_get_api();
        $this->media_handler = nitter_get_media_handler();
        $this->cron_handler = nitter_get_cron_handler();
    }
    
    public function get_database() {
        return $this->database;
    }
    
    public function get_api() {
        return $this->api;
    }
    
    public function get_media_handler() {
        return $this->media_handler;
    }
    
    public function get_cron_handler() {
        return $this->cron_handler;
    }
    
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'Nitter Scraper',
            'Nitter Scraper',
            'manage_options',
            'nitter-scraper',
            array($this, 'render_accounts_page'),
            'dashicons-twitter',
            30
        );
        
        // Accounts submenu (same as main)
        add_submenu_page(
            'nitter-scraper',
            'Accounts',
            'Accounts',
            'manage_options',
            'nitter-scraper'
        );
        
        // Tweets submenu
        add_submenu_page(
            'nitter-scraper',
            'Tweets',
            'Tweets',
            'manage_options',
            'nitter-tweets',
            array($this, 'render_tweets_page')
        );
        
        // Settings submenu
        add_submenu_page(
            'nitter-scraper',
            'Settings',
            'Settings',
            'manage_options',
            'nitter-settings',
            array($this, 'render_settings_page')
        );
        
        // Video Settings submenu
        add_submenu_page(
            'nitter-scraper',
            'Video/GIF Settings',
            'Video Settings',
            'manage_options',
            'nitter-video-settings',
            array($this, 'render_video_settings_page')
        );
        
        // Test Video Processing submenu (Phase 4)
        add_submenu_page(
            'nitter-scraper',
            'Test Video Processing',
            'Test Videos',
            'manage_options',
            'nitter-video-test',
            array($this, 'render_video_test_page')
        );
        
        // Logs submenu
        add_submenu_page(
            'nitter-scraper',
            'Logs',
            'Logs',
            'manage_options',
            'nitter-logs',
            array($this, 'render_logs_page')
        );
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'nitter') === false) {
            return;
        }
        
        wp_enqueue_style(
            'nitter-admin-css',
            NITTER_SCRAPER_URL . 'assets/css/admin.css',
            array(),
            NITTER_SCRAPER_VERSION
        );
        
        wp_enqueue_script(
            'nitter-admin-js',
            NITTER_SCRAPER_URL . 'assets/js/admin.js',
            array('jquery'),
            NITTER_SCRAPER_VERSION,
            true
        );
        
        // Use nitter_ajax (underscore) not nitterAjax (camelCase) - matches JS expectations
        wp_localize_script('nitter-admin-js', 'nitter_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nitter_ajax_nonce')
        ));
    }
    
    public function render_accounts_page() {
        // Make admin instance available to the page
        global $nitter_admin;
        $nitter_admin = $this;
        require_once NITTER_SCRAPER_PATH . 'admin/accounts-page.php';
    }
    
    public function render_tweets_page() {
        // Make admin instance available to the page
        global $nitter_admin;
        $nitter_admin = $this;
        require_once NITTER_SCRAPER_PATH . 'admin/tweets-page.php';
    }
    
    public function render_settings_page() {
        // Make admin instance available to the page
        global $nitter_admin;
        $nitter_admin = $this;
        require_once NITTER_SCRAPER_PATH . 'admin/settings-page.php';
    }
    
    public function render_video_settings_page() {
        require_once NITTER_SCRAPER_PATH . 'admin/video-settings-page.php';
    }
    
    public function render_video_test_page() {
        require_once NITTER_SCRAPER_PATH . 'admin/video-test-page.php';
    }
    
    public function render_logs_page() {
        // Make admin instance available to the page
        global $nitter_admin;
        $nitter_admin = $this;
        require_once NITTER_SCRAPER_PATH . 'admin/logs-page.php';
    }
}