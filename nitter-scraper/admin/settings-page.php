<?php
if (!defined('ABSPATH')) {
    exit;
}

global $nitter_admin;
$database = $nitter_admin->get_database();
$api = $nitter_admin->get_api();
$cron_handler = $nitter_admin->get_cron_handler();
$media_handler = $nitter_admin->get_media_handler();

// Handle form submissions
$message = '';
$message_type = '';

if (isset($_POST['add_instance']) && wp_verify_nonce($_POST['nonce'], 'nitter_add_instance')) {
    $instance_url = sanitize_url($_POST['instance_url']);
    
    if (empty($instance_url)) {
        $message = 'Instance URL is required';
        $message_type = 'error';
    } else {
        global $wpdb;
        $instances_table = $wpdb->prefix . 'nitter_instances';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $instances_table WHERE instance_url = %s",
            $instance_url
        ));
        
        if ($existing) {
            $message = 'Instance already exists';
            $message_type = 'error';
        } else {
            $result = $wpdb->insert(
                $instances_table,
                array('instance_url' => $instance_url),
                array('%s')
            );
            
            if ($result) {
                $database->add_log('system', 'Instance added: ' . $instance_url);
                $message = 'Instance added successfully';
                $message_type = 'success';
            } else {
                $message = 'Failed to add instance';
                $message_type = 'error';
            }
        }
    }
}

if (isset($_POST['delete_instance']) && wp_verify_nonce($_POST['nonce'], 'nitter_delete_instance')) {
    $instance_id = intval($_POST['instance_id']);
    
    global $wpdb;
    $instances_table = $wpdb->prefix . 'nitter_instances';
    
    $instance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $instances_table WHERE id = %d",
        $instance_id
    ));
    
    if ($instance) {
        $result = $wpdb->delete($instances_table, array('id' => $instance_id), array('%d'));
        
        if ($result) {
            $database->add_log('system', 'Instance deleted: ' . $instance->instance_url);
            $message = 'Instance deleted successfully';
            $message_type = 'success';
        } else {
            $message = 'Failed to delete instance';
            $message_type = 'error';
        }
    }
}

// Get instances
global $wpdb;
$instances_table = $wpdb->prefix . 'nitter_instances';
$instances = $wpdb->get_results("SELECT * FROM $instances_table ORDER BY instance_url");

// Get next cleanup times
$cleanup_times = $cron_handler->get_next_cleanup_time();

// Get next auto-scrape time
$next_scrape = $cron_handler->get_next_auto_scrape_time();

// Get media stats
$media_stats = $media_handler->get_media_library_stats();

?>

<div class="nitter-admin-wrap">
    <h1>Nitter Scraper - Settings</h1>
    
    <?php if ($message): ?>
        <div class="nitter-message <?php echo esc_attr($message_type); ?>">
            <?php echo esc_html($message); ?>
        </div>
    <?php endif; ?>
    
    <h2>Service Status</h2>
    <div class="nitter-form">
        <div class="nitter-form-row">
            <button type="button" id="nitter-test-service" class="nitter-btn">Test Node.js Service</button>
            <small>Check if the Node.js scraping service is running on port 3001</small>
        </div>
        
        <div class="nitter-form-row">
            <button type="button" id="nitter-test-api" class="nitter-btn nitter-btn-secondary">Test API Connection</button>
            <small>Test the connection between WordPress and Node.js service</small>
        </div>
    </div>
    
    <h2>Nitter Instances</h2>
    <div class="nitter-form">
        <form method="post">
            <?php wp_nonce_field('nitter_add_instance', 'nonce'); ?>
            
            <div class="nitter-form-row">
                <label for="instance_url">Instance URL:</label>
                <input type="url" id="instance_url" name="instance_url" placeholder="https://nitter.example.com" required>
            </div>
            
            <div class="nitter-form-row">
                <button type="submit" name="add_instance" class="nitter-btn">Add Instance</button>
            </div>
        </form>
    </div>
    
    <?php if (!empty($instances)): ?>
        <table class="nitter-table">
            <thead>
                <tr>
                    <th>Instance URL</th>
                    <th>Status</th>
                    <th>Last Used</th>
                    <th>Response Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($instances as $instance): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($instance->instance_url); ?>" target="_blank">
                                <?php echo esc_html($instance->instance_url); ?>
                            </a>
                        </td>
                        <td>
                            <span class="nitter-status <?php echo $instance->is_active ? 'active' : 'inactive'; ?>">
                                <?php echo $instance->is_active ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($instance->last_used): ?>
                                <?php echo esc_html(date('Y-m-d H:i', strtotime($instance->last_used))); ?>
                            <?php else: ?>
                                <span style="color: #999;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($instance->response_time): ?>
                                <?php echo esc_html($instance->response_time); ?>ms
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <button type="button" 
                                    class="nitter-btn nitter-btn-secondary nitter-test-instance"
                                    data-instance-url="<?php echo esc_attr($instance->instance_url); ?>">
                                Test
                            </button>
                            
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('nitter_delete_instance', 'nonce'); ?>
                                <input type="hidden" name="instance_id" value="<?php echo esc_attr($instance->id); ?>">
                                <button type="submit" name="delete_instance" class="nitter-btn nitter-btn-danger"
                                        onclick="return confirm('Are you sure you want to delete this instance?')">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <h2>System Information</h2>
    <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin-bottom: 20px;">
        <h3>Cleanup Schedule</h3>
        <p><strong>Next Content Cleanup:</strong> <?php echo esc_html($cleanup_times['content']); ?></p>
        <p><strong>Next Log Cleanup:</strong> <?php echo esc_html($cleanup_times['logs']); ?></p>
        <p><strong>Next Auto-Scrape:</strong> <?php echo esc_html($next_scrape); ?></p>
        
        <h3>Media Library</h3>
        <p><strong>Total Images:</strong> <?php echo esc_html($media_stats['total_images']); ?></p>
        <p><strong>Total Size:</strong> <?php echo esc_html($media_stats['total_size']); ?></p>
        
        <h3>Database Tables</h3>
        <?php
        global $wpdb;
        $tables = array(
            'nitter_accounts' => 'Accounts',
            'nitter_tweets' => 'Tweets',
            'nitter_images' => 'Images',
            'nitter_instances' => 'Instances',
            'nitter_logs' => 'Logs'
        );
        
        foreach ($tables as $table => $label):
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
        ?>
            <p><strong><?php echo esc_html($label); ?>:</strong> <?php echo esc_html($count); ?> records</p>
        <?php endforeach; ?>
    </div>
    
    <h2>Maintenance</h2>
    <div class="nitter-form">
        <div class="nitter-form-row">
            <button type="button" id="nitter-manual-cleanup" class="nitter-btn nitter-btn-secondary">
                Run Manual Cleanup
            </button>
            <small>Manually run the cleanup process to remove old content and logs</small>
        </div>
        
        <div class="nitter-form-row">
            <button type="button" id="nitter-cleanup-orphaned" class="nitter-btn nitter-btn-secondary">
                Cleanup Orphaned Images
            </button>
            <small>Remove images from media library that are no longer referenced</small>
        </div>
        
        <div class="nitter-form-row">
            <button type="button" id="nitter-delete-all-content" class="nitter-btn nitter-btn-danger">
                Delete All Content
            </button>
            <small><strong>Warning:</strong> This will delete all tweets and images from all accounts</small>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Test instance
    $('.nitter-test-instance').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var instanceUrl = $button.data('instance-url');
        var originalText = $button.text();
        
        var formData = {
            action: 'nitter_test_instance',
            nonce: nitter_ajax.nonce,
            instance_url: instanceUrl
        };
        
        $button.html(originalText + ' <span class="nitter-loading"></span>').prop('disabled', true);
        
        $.post(nitter_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                alert('Instance is working: ' + response.data.message);
            } else {
                alert('Instance test failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            alert('Request failed');
        }).always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
    
    // Test API
    $('#nitter-test-api').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        
        var formData = {
            action: 'nitter_test_api',
            nonce: nitter_ajax.nonce
        };
        
        $button.html(originalText + ' <span class="nitter-loading"></span>').prop('disabled', true);
        
        $.post(nitter_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                alert('API connection successful');
            } else {
                alert('API test failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            alert('Request failed');
        }).always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
    
    // Manual cleanup
    $('#nitter-manual-cleanup').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        
        var formData = {
            action: 'nitter_manual_cleanup',
            nonce: nitter_ajax.nonce
        };
        
        $button.html(originalText + ' <span class="nitter-loading"></span>').prop('disabled', true);
        
        $.post(nitter_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                alert('Cleanup completed successfully');
                location.reload();
            } else {
                alert('Cleanup failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            alert('Request failed');
        }).always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
    
    // Cleanup orphaned images
    $('#nitter-cleanup-orphaned').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        
        var formData = {
            action: 'nitter_cleanup_orphaned',
            nonce: nitter_ajax.nonce
        };
        
        $button.html(originalText + ' <span class="nitter-loading"></span>').prop('disabled', true);
        
        $.post(nitter_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                alert('Orphaned images cleanup completed');
                location.reload();
            } else {
                alert('Cleanup failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            alert('Request failed');
        }).always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
});
</script>