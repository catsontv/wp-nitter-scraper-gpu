<?php
if (!defined('ABSPATH')) {
    exit;
}

global $nitter_admin;
$database = $nitter_admin->get_database();
$api = $nitter_admin->get_api();

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

if (isset($_POST['import_accounts']) && wp_verify_nonce($_POST['nonce'], 'nitter_import_accounts')) {
    $accounts_text = sanitize_textarea_field($_POST['accounts_text']);
    $lines = explode("\n", $accounts_text);
    
    $accounts_to_import = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode(',', $line);
        $username = trim($parts[0]);
        $username = preg_replace('/^https?:\/\/(twitter\.com|x\.com)\//', '', $username);
        $username = preg_replace('/^@/', '', $username);
        
        if (empty($username)) continue;
        
        $retention = isset($parts[1]) ? intval(trim($parts[1])) : 30;
        
        $accounts_to_import[] = array(
            'username' => $username,
            'retention_days' => $retention
        );
    }
    
    if (!empty($accounts_to_import)) {
        $result = $database->bulk_add_accounts($accounts_to_import);
        $message = "Imported {$result['imported']} accounts. Skipped {$result['duplicates']} duplicates. {$result['invalid']} invalid.";
        $message_type = 'success';
    } else {
        $message = 'No valid accounts found in input';
        $message_type = 'error';
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
    
    <div class="nitter-form" style="margin-top: 20px;">
        <h2>Bulk Import Accounts (PHASE 2)</h2>
        <button type="button" class="nitter-btn" onclick="document.getElementById('import-modal').style.display='block'">Import Accounts</button>
        <button type="button" class="nitter-btn nitter-btn-secondary" onclick="exportAccounts()">Export Accounts</button>
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

<div id="import-modal" class="nitter-modal" style="display:none;">
    <div class="nitter-modal-content">
        <span class="nitter-modal-close" onclick="document.getElementById('import-modal').style.display='none'">&times;</span>
        <h2>Import Accounts</h2>
        <form method="post">
            <?php wp_nonce_field('nitter_import_accounts', 'nonce'); ?>
            <p>Enter one account per line. Format: <code>username</code> or <code>username,retention_days</code></p>
            <p>Examples:<br>
            <code>elonmusk</code><br>
            <code>NASA,90</code><br>
            <code>https://twitter.com/SpaceX,60</code><br>
            <code>@POTUS</code></p>
            <textarea name="accounts_text" rows="10" style="width:100%;font-family:monospace;" required></textarea>
            <p>
                <button type="submit" name="import_accounts" class="nitter-btn">Import</button>
                <button type="button" class="nitter-btn nitter-btn-secondary" onclick="document.getElementById('import-modal').style.display='none'">Cancel</button>
            </p>
        </form>
    </div>
</div>

<style>
.nitter-modal {
    display: none;
    position: fixed;
    z-index: 999999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
}

.nitter-modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 20px;
    border: 1px solid #888;
    width: 80%;
    max-width: 600px;
    border-radius: 5px;
}

.nitter-modal-close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.nitter-modal-close:hover,
.nitter-modal-close:focus {
    color: black;
}
</style>

<script>
function exportAccounts() {
    var accounts = <?php echo json_encode(array_map(function($a) {
        return $a->account_username . ',' . $a->retention_days;
    }, $accounts)); ?>;
    
    var content = accounts.join('\n');
    var blob = new Blob([content], { type: 'text/plain' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    var timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    a.download = 'nitter-accounts-' + timestamp + '.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>