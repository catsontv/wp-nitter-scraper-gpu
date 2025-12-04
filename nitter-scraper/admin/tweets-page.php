<?php
if (!defined('ABSPATH')) {
    exit;
}

global $nitter_admin;
$database = $nitter_admin->get_database();
$media_handler = $nitter_admin->get_media_handler();

// Get accounts for filter dropdown
$accounts = $database->get_accounts();

// Function to format time ago like Twitter
function twitter_time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return $time . 's';
    if ($time < 3600) return floor($time/60) . 'm';
    if ($time < 86400) return floor($time/3600) . 'h';
    if ($time < 2592000) return floor($time/86400) . 'd';
    
    return date('M j', strtotime($datetime));
}
?>

<div class="nitter-admin-wrap">
    <h1>Nitter Scraper - Tweets</h1>
    
    <div class="nitter-filters">
        <label for="nitter-account-filter">Filter by Account:</label>
        <select id="nitter-account-filter">
            <option value="">All Accounts</option>
            <?php foreach ($accounts as $account): ?>
                <option value="<?php echo esc_attr($account->id); ?>">
                    <?php echo esc_html($account->account_username); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <button type="button" id="nitter-clear-filter" class="nitter-btn nitter-btn-secondary">
            Clear Filter
        </button>
        
        <button type="button" id="nitter-refresh-tweets" class="nitter-btn">
            Refresh
        </button>
    </div>
    
    <div id="nitter-tweets-container" class="nitter-tweets-container">
        <div style="padding: 20px; text-align: center;">
            Loading tweets...
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var currentAccountId = '';
    var currentOffset = 0;
    var isLoading = false;
    var hasMoreTweets = true;
    
    // Copy GIF URL to clipboard
    $(document).on('click', '.nitter-copy-gif-url', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var url = $(this).data('url');
        var button = $(this);
        
        // Copy to clipboard
        navigator.clipboard.writeText(url).then(function() {
            // Show success feedback
            var originalText = button.html();
            button.html('✅ Copied!');
            button.css('background', '#00ba7c');
            
            setTimeout(function() {
                button.html(originalText);
                button.css('background', '#1d9bf0');
            }, 2000);
        }).catch(function() {
            alert('Failed to copy URL. Please copy manually: ' + url);
        });
    });
    
    // Prevent copy button from triggering image modal
    $(document).on('click', '.nitter-gif-item', function(e) {
        if ($(e.target).hasClass('nitter-copy-gif-url')) {
            e.stopPropagation();
        }
    });
    
    // Load tweets function
    function loadTweets(accountId, offset, append) {
        if (isLoading) return;
        
        offset = offset || 0;
        append = append || false;
        
        var $container = $('#nitter-tweets-container');
        
        if (!append) {
            $container.empty().html('<div style="padding: 20px; text-align: center;">Loading tweets...</div>');
            currentOffset = 0;
            hasMoreTweets = true;
        }
        
        var formData = {
            action: 'nitter_load_tweets',
            nonce: nitter_ajax.nonce,
            account_id: accountId || '',
            offset: offset,
            limit: 20
        };
        
        isLoading = true;
        
        $.post(nitter_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                if (append && response.data.html.trim()) {
                    $container.append(response.data.html);
                } else if (!append) {
                    $container.html(response.data.html);
                    currentOffset = 0;
                }
                
                if (append) {
                    currentOffset = offset + 20;
                }
                
                if (!response.data.html.trim() || response.data.count < 20) {
                    hasMoreTweets = false;
                }
                
                initImageModal();
            } else {
                if (!append) {
                    $container.html('<div style="padding: 20px; text-align: center; color: #666;">No tweets found</div>');
                }
            }
        }).fail(function() {
            if (!append) {
                $container.html('<div style="padding: 20px; text-align: center; color: #d32f2f;">Failed to load tweets</div>');
            }
        }).always(function() {
            isLoading = false;
        });
    }
    
    // Initialize image modal
    function initImageModal() {
        $('#nitter-image-modal').remove();
        
        var modalHtml = `
            <div id="nitter-image-modal" style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.95); cursor:pointer;">
                <div id="nitter-modal-close" style="position:absolute; top:15px; right:15px; width:40px; height:40px; background:rgba(255,255,255,0.2); border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:1000000; font-size:24px; color:#fff; font-weight:bold;">×</div>
                <div style="display:flex; align-items:center; justify-content:center; width:100%; height:100%; position:relative;">
                    <div id="nitter-modal-prev" style="position:absolute; left:20px; top:50%; transform:translateY(-50%); width:50px; height:50px; background:rgba(255,255,255,0.2); border-radius:50%; display:none; align-items:center; justify-content:center; cursor:pointer; z-index:1000000; font-size:24px; color:#fff; font-weight:bold;">‹</div>
                    <img id="nitter-modal-img" style="max-width:90%; max-height:90%; object-fit:contain; border-radius:8px; cursor:default;">
                    <div id="nitter-modal-next" style="position:absolute; right:20px; top:50%; transform:translateY(-50%); width:50px; height:50px; background:rgba(255,255,255,0.2); border-radius:50%; display:none; align-items:center; justify-content:center; cursor:pointer; z-index:1000000; font-size:24px; color:#fff; font-weight:bold;">›</div>
                </div>
                <div id="nitter-modal-counter" style="position:absolute; bottom:20px; left:50%; transform:translateX(-50%); color:#fff; background:rgba(0,0,0,0.5); padding:8px 16px; border-radius:20px; font-size:14px; display:none;">1 / 1</div>
            </div>
        `;
        
        $('body').append(modalHtml);
        
        var currentImages = [];
        var currentIndex = 0;
        
        $('.nitter-tweet-image').off('click').on('click', function(e) {
            if ($(e.target).closest('.nitter-copy-gif-url').length) return;
            
            e.preventDefault();
            e.stopPropagation();
            
            var clickedImage = $(this);
            var tweetContainer = clickedImage.closest('.nitter-tweet');
            
            currentImages = [];
            tweetContainer.find('.nitter-tweet-image').each(function() {
                currentImages.push($(this).data('full-url') || $(this).attr('src'));
            });
            
            var clickedSrc = clickedImage.data('full-url') || clickedImage.attr('src');
            currentIndex = currentImages.indexOf(clickedSrc);
            
            showModalImage();
            $('#nitter-image-modal').show();
        });
        
        function showModalImage() {
            if (currentImages.length === 0) return;
            
            $('#nitter-modal-img').attr('src', currentImages[currentIndex]);
            
            if (currentImages.length > 1) {
                $('#nitter-modal-counter').text((currentIndex + 1) + ' / ' + currentImages.length).show();
                $('#nitter-modal-prev').css('display', 'flex');
                $('#nitter-modal-next').css('display', 'flex');
            } else {
                $('#nitter-modal-counter').hide();
                $('#nitter-modal-prev').hide();
                $('#nitter-modal-next').hide();
            }
        }
        
        $('#nitter-image-modal').on('click', function(e) {
            if (e.target === this) $(this).hide();
        });
        
        $('#nitter-modal-close').on('click', function(e) {
            e.stopPropagation();
            $('#nitter-image-modal').hide();
        });
        
        $('#nitter-modal-prev').on('click', function(e) {
            e.stopPropagation();
            if (currentIndex > 0) {
                currentIndex--;
                showModalImage();
            }
        });
        
        $('#nitter-modal-next').on('click', function(e) {
            e.stopPropagation();
            if (currentIndex < currentImages.length - 1) {
                currentIndex++;
                showModalImage();
            }
        });
    }
    
    $('#nitter-account-filter').on('change', function() {
        currentAccountId = $(this).val() === '' ? null : $(this).val();
        currentOffset = 0;
        hasMoreTweets = true;
        loadTweets(currentAccountId, 0, false);
    });
    
    $('#nitter-clear-filter').on('click', function(e) {
        e.preventDefault();
        $('#nitter-account-filter').val('');
        currentAccountId = null;
        currentOffset = 0;
        hasMoreTweets = true;
        loadTweets(currentAccountId, 0, false);
    });
    
    $('#nitter-refresh-tweets').on('click', function(e) {
        e.preventDefault();
        currentOffset = 0;
        hasMoreTweets = true;
        loadTweets(currentAccountId, 0, false);
    });
    
    $(window).on('scroll', function() {
        if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100 && !isLoading && hasMoreTweets) {
            currentOffset += 20;
            loadTweets(currentAccountId, currentOffset, true);
        }
    });
    
    loadTweets();
});
</script>