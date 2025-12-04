(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        console.log('Nitter JS loading...');
        
        // Global variables
        var isLoading = false;
        var currentImages = [];
        var currentIndex = 0;
        
        // Create modal function
        function createImageModal() {
            $('#nitter-image-modal').remove();
            
            var modalHtml = '<div id="nitter-image-modal" class="nitter-image-modal">' +
                '<span class="nitter-modal-close">&times;</span>' +
                '<div class="nitter-modal-nav">' +
                '<button id="nitter-modal-prev" class="nitter-modal-nav-btn">&lt;</button>' +
                '<button id="nitter-modal-next" class="nitter-modal-nav-btn">&gt;</button>' +
                '</div>' +
                '<img class="nitter-modal-content" id="nitter-modal-img">' +
                '<div class="nitter-modal-counter">' +
                '<span id="nitter-modal-current">1</span> / <span id="nitter-modal-total">1</span>' +
                '</div>' +
                '</div>';
            
            $('body').append(modalHtml);
            console.log('Modal created');
        }
        
        createImageModal();
        
        // Image click handler
        $(document).on('click', '.nitter-tweet-image', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Image clicked!');
            
            var $tweet = $(this).closest('.nitter-tweet');
            var $images = $tweet.find('.nitter-tweet-image');
            
            currentImages = [];
            $images.each(function() {
                currentImages.push($(this).attr('src'));
            });
            
            currentIndex = $images.index(this);
            
            console.log('Found ' + currentImages.length + ' images, showing index ' + currentIndex);
            
            showModalImage();
            $('#nitter-image-modal').addClass('active');
        });
        
        // Modal handlers
        $(document).on('click', '#nitter-image-modal, .nitter-modal-close', function(e) {
            if (e.target === this) {
                $('#nitter-image-modal').removeClass('active');
            }
        });
        
        $(document).on('click', '#nitter-modal-prev', function(e) {
            e.stopPropagation();
            currentIndex = (currentIndex - 1 + currentImages.length) % currentImages.length;
            showModalImage();
        });
        
        $(document).on('click', '#nitter-modal-next', function(e) {
            e.stopPropagation();
            currentIndex = (currentIndex + 1) % currentImages.length;
            showModalImage();
        });
        
        $(document).keyup(function(e) {
            if ($('#nitter-image-modal').hasClass('active')) {
                if (e.keyCode === 27) $('#nitter-image-modal').removeClass('active');
                if (e.keyCode === 37) $('#nitter-modal-prev').click();
                if (e.keyCode === 39) $('#nitter-modal-next').click();
            }
        });
        
        function showModalImage() {
            if (currentImages.length > 0) {
                $('#nitter-modal-img').attr('src', currentImages[currentIndex]);
                $('#nitter-modal-current').text(currentIndex + 1);
                $('#nitter-modal-total').text(currentImages.length);
                
                if (currentImages.length <= 1) {
                    $('.nitter-modal-nav, .nitter-modal-counter').hide();
                } else {
                    $('.nitter-modal-nav, .nitter-modal-counter').show();
                }
            }
        }
        
        // Add account form
        $(document).on('submit', '#nitter-add-account-form', function(e) {
            e.preventDefault();
            
            if (isLoading) return;
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var originalText = $button.text();
            
            console.log('Adding account...');
            
            var formData = {
                action: 'nitter_add_account',
                nonce: nitter_ajax.nonce,
                account_url: $form.find('input[name="account_url"]').val(),
                retention_days: $form.find('input[name="retention_days"]').val()
            };
            
            isLoading = true;
            $button.text('Adding...').prop('disabled', true);
            
            $.post(nitter_ajax.ajax_url, formData, function(response) {
                console.log('Add account response:', response);
                if (response.success) {
                    alert('Account added successfully');
                    $form[0].reset();
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Failed: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Request failed');
            }).always(function() {
                isLoading = false;
                $button.text(originalText).prop('disabled', false);
            });
        });
        
        // Manual scrape account
        $(document).on('click', '.nitter-scrape-account', function(e) {
            e.preventDefault();
            
            if (isLoading) return;
            
            var $button = $(this);
            var accountId = $button.data('account-id');
            var originalText = $button.text();
            
            console.log('Scraping account:', accountId);
            
            var formData = {
                action: 'nitter_scrape_account',
                nonce: nitter_ajax.nonce,
                account_id: accountId
            };
            
            isLoading = true;
            $button.text('Scraping...').prop('disabled', true);
            
            $.post(nitter_ajax.ajax_url, formData, function(response) {
                console.log('Scrape response:', response);
                if (response.success) {
                    alert('Scraping started successfully');
                } else {
                    alert('Failed: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Request failed');
            }).always(function() {
                isLoading = false;
                $button.text(originalText).prop('disabled', false);
            });
        });
        
        // Toggle account status
        $(document).on('click', '.nitter-toggle-account', function(e) {
            e.preventDefault();
            
            if (isLoading) return;
            
            var $button = $(this);
            var accountId = $button.data('account-id');
            var currentStatus = $button.data('status');
            var newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            
            var formData = {
                action: 'nitter_toggle_account',
                nonce: nitter_ajax.nonce,
                account_id: accountId,
                status: newStatus
            };
            
            isLoading = true;
            $button.prop('disabled', true);
            
            $.post(nitter_ajax.ajax_url, formData, function(response) {
                if (response.success) {
                    $button.data('status', newStatus);
                    $button.text(newStatus === 'active' ? 'Disable' : 'Enable');
                    var $statusCell = $button.closest('tr').find('.account-status');
                    $statusCell.removeClass('active inactive').addClass(newStatus)
                              .text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
                    alert('Account status updated');
                } else {
                    alert('Failed: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Request failed');
            }).always(function() {
                isLoading = false;
                $button.prop('disabled', false);
            });
        });
        
        // Delete account
        $(document).on('click', '.nitter-delete-account', function(e) {
            e.preventDefault();
            
            if (isLoading) return;
            
            if (!confirm('Are you sure you want to delete this account? This will also delete all tweets and images.')) {
                return;
            }
            
            var $button = $(this);
            var accountId = $button.data('account-id');
            
            var formData = {
                action: 'nitter_delete_account',
                nonce: nitter_ajax.nonce,
                account_id: accountId
            };
            
            isLoading = true;
            $button.prop('disabled', true);
            
            $.post(nitter_ajax.ajax_url, formData, function(response) {
                if (response.success) {
                    $button.closest('tr').fadeOut(function() {
                        $(this).remove();
                    });
                    alert('Account deleted successfully');
                } else {
                    alert('Failed: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Request failed');
            }).always(function() {
                isLoading = false;
                $button.prop('disabled', false);
            });
        });
        
        // Test service
        $(document).on('click', '#nitter-test-service', function(e) {
            e.preventDefault();
            
            if (isLoading) return;
            
            var $button = $(this);
            var originalText = $button.text();
            
            var formData = {
                action: 'nitter_test_service',
                nonce: nitter_ajax.nonce
            };
            
            isLoading = true;
            $button.text('Testing...').prop('disabled', true);
            
            $.post(nitter_ajax.ajax_url, formData, function(response) {
                if (response.success) {
                    alert('Node.js service is running');
                } else {
                    alert('Service test failed: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                alert('Request failed');
            }).always(function() {
                isLoading = false;
                $button.text(originalText).prop('disabled', false);
            });
        });
        
        // Test function
        window.testModal = function() {
            $('#nitter-modal-img').attr('src', 'https://via.placeholder.com/800x600?text=Test+Image');
            $('#nitter-image-modal').addClass('active');
            console.log('Test modal opened');
        };
        
        console.log('Nitter admin JS ready');
        
    });
    
})(jQuery);