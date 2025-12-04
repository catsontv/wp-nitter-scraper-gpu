<?php
if (!defined('ABSPATH')) {
    exit;
}

global $nitter_admin;
$database = $nitter_admin->get_database();
$api = $nitter_admin->get_api();

// Handle form submissions
$message = '';
$message_type = '';

if (isset($_POST['add_account']) && wp_verify_nonce($_POST['nonce'], 'nitter_add_account')) {
    $account_url = sanitize_url($_POST['account_url']);
    $retention_days = intval($_POST['retention_days']);
    
    if (empty($account_url)) {
        $message = 'Account URL is required';
        $message_type = 'error';
    } else {
        $username = $api->extract_username_from_url($account_url);
        if (!$username) {
            $message = 'Invalid account URL';
            $message_type = 'error';
        } else {
            $result = $database->add_account($account_url, $username, $retention_days);
            if ($result) {
                $message = 'Account added successfully';
                $message_type = 'success';
            } else {
                $message = 'Failed to add account. Account may already exist.';
                $message_type = 'error';
            }
        }
    }
}

$accounts = $database->get_accounts();
?>

<div class="nitter-admin-wrap">
    <h1>Nitter Scraper - Accounts</h1>
    
    <?php if ($message): ?>
        <div class="nitter-message <?php echo esc_attr($message_type); ?>">
            <?php echo esc_html($message); ?>
        </div>
    <?php endif; ?>
    
    <div class="nitter-form">
        <h2>Add New Account</h2>
        <form id="nitter-add-account-form" method="post">
            <?php wp_nonce_field('nitter_add_account', 'nonce'); ?>
            
            <div class="nitter-form-row">
                <label for="account_url">Account URL:</label>
                <input type="url" id="account_url" name="account_url" placeholder="https://x.com/username or https://nitter.net/username" required>
            </div>
            
            <div class="nitter-form-row">
                <label for="retention_days">Retention (days):</label>
                <input type="number" id="retention_days" name="retention_days" value="30" min="1" max="365" required>
                <small>Number of days to keep tweets before automatic deletion</small>
            </div>
            
            <div class="nitter-form-row">
                <button type="submit" name="add_account" class="nitter-btn">Add Account</button>
            </div>
        </form>
    </div>
    
    <h2>Accounts List</h2>
    
    <?php if (empty($accounts)): ?>
        <div class="nitter-message info">
            No accounts added yet. Add your first account using the form above.
        </div>
    <?php else: ?>
        <table class="nitter-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Account URL</th>
                    <th>Retention (days)</th>
                    <th>Status</th>
                    <th>Date Added</th>
                    <th>Last Scraped</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td>
                            <strong>@<?php echo esc_html($account->account_username); ?></strong>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($account->account_url); ?>" target="_blank">
                                <?php echo esc_html($account->account_url); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($account->retention_days); ?></td>
                        <td>
                            <span class="nitter-status account-status <?php echo $account->is_active ? 'active' : 'inactive'; ?>">
                                <?php echo $account->is_active ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td><?php echo esc_html(date('Y-m-d H:i', strtotime($account->date_added))); ?></td>
                        <td>
                            <?php if ($account->last_scraped): ?>
                                <?php echo esc_html(date('Y-m-d H:i', strtotime($account->last_scraped))); ?>
                            <?php else: ?>
                                <span style="color: #999;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <button type="button" 
                                    class="nitter-btn nitter-toggle-account <?php echo $account->is_active ? 'nitter-btn-secondary' : 'nitter-btn-success'; ?>"
                                    data-account-id="<?php echo esc_attr($account->id); ?>"
                                    data-status="<?php echo $account->is_active ? 'active' : 'inactive'; ?>">
                                <?php echo $account->is_active ? 'Disable' : 'Enable'; ?>
                            </button>
                            
                            <button type="button" 
                                    class="nitter-btn nitter-scrape-account"
                                    data-account-id="<?php echo esc_attr($account->id); ?>"
                                    <?php echo !$account->is_active ? 'disabled' : ''; ?>>
                                Scrape Now
                            </button>
                            
                            <button type="button" 
                                    class="nitter-btn nitter-btn-danger nitter-delete-account"
                                    data-account-id="<?php echo esc_attr($account->id); ?>">
                                Delete
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>