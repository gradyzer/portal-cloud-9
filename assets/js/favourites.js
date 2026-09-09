/**
 * ============================================================================
 * Portal Cloud 9 - Favourites and Wishlist Handler
 * ============================================================================
 *
 * Handles all favourites and wishlist functionality including:
 * - Add and remove products from favourites via AJAX
 * - Social share panel for products (Facebook, Twitter, WhatsApp)
 * - Empty state display when wishlist is cleared
 * - Heart/bookmark button state synchronisation across the page
 * - Favourites count badge updates
 *
 * Dependencies: jQuery
 *
 * @package Portal_Cloud_9
 * @version 8.6.0
 * @author  Brian Agoi (Gradyzer)
 * @company Gradyzer
 * @license GPL-2.0+
 * ============================================================================
 */

(function($) {
    'use strict';

    const P9Favourites = {
        /**
         * Initialize all functionality
         */
        init: function() {
            this.heartButtons();
            this.removeButtons();
            this.bulkActions();
            this.addToCart();
            this.updateCounter();
            this.shareButtons();
        },

        /**
         * Show notification toast
         * @param {string} message - Message to display
         * @param {string} type - Notification type (info, success, error)
         */
        showNotification: function(message, type = 'info') {
            const $notification = $('<div class="p9-top-notification"></div>')
                .text(message)
                .addClass(type);

            $('body').append($notification);

            setTimeout(() => $notification.addClass('show'), 10);

            setTimeout(() => {
                $notification.removeClass('show');
                setTimeout(() => $notification.remove(), 300);
            }, 3000);
        },

        /**
         * Update all favourite counters on the page
         * @param {number} count - New count value
         */
        updateAllCounters: function(count) {
            $('.p9-fav-count-value').text(count);
            $('.p9-fav-counter-link').attr('data-count', count);
            $('.p9-favourites-count').text(count + (count === 1 ? ' item' : ' items'));
        },

        /**
         * Update bulk remove button state
         */
        updateBulkButton: function() {
            const selectedCount = $('.p9-bulk-checkbox:checked').length;
            const $bulkBtn = $('#p9-bulk-remove');
            const $bulkText = $('.p9-bulk-text');
            const $bulkCount = $('.p9-bulk-count');

            if (selectedCount > 0) {
                $bulkBtn
                    .removeClass('p9-hidden')
                    .addClass('p9-active')
                    .attr('data-selected', selectedCount)
                    .prop('disabled', false);

                $bulkCount.text('(' + selectedCount + ')').show();
                $bulkText.text('Remove Selected');
            } else {
                $bulkBtn
                    .addClass('p9-hidden')
                    .removeClass('p9-active')
                    .attr('data-selected', '0')
                    .prop('disabled', true);

                $bulkCount.hide();
                $bulkText.text('Bulk Remove');
            }
        },

        /**
         * Handle heart button clicks (toggle favourite)
         */
        heartButtons: function() {
            $(document).on('click', '.p9-favourite-btn', function(e) {
                e.preventDefault();

                const $btn = $(this);
                const productId = $btn.data('product-id');
                const currentlyActive = $btn.hasClass('is-active');

                // Disable button during request
                $btn.prop('disabled', true);

                $.ajax({
                    url: portalcloud9_favourites.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'portalcloud9_toggle_favourite',
                        nonce: portalcloud9_favourites.nonce,
                        product_id: productId
                    },
                    dataType: 'json',
                    timeout: 10000,
                    success: function(response) {
                        if (response.success) {
                            const isFavourite = response.data.is_favourite;
                            const newCount = response.data.count;

                            // Update button state
                            $btn
                                .toggleClass('is-active', isFavourite)
                                .attr({
                                    'data-is-favourite': isFavourite ? 'true' : 'false',
                                    'aria-pressed': isFavourite ? 'true' : 'false',
                                    'aria-label': isFavourite ? 'Remove from favourites' : 'Add to favourites'
                                });

                            // Update all counters
                            P9Favourites.updateAllCounters(newCount);

                            // Show notification
                            P9Favourites.showNotification(
                                response.data.message,
                                isFavourite ? 'success' : 'info'
                            );
                        } else if (response.success === false) {
                            // Check if login is required
                            if (response.data && response.data.login_required === true) {
                                P9Favourites.handleLoginRequired(productId);
                                return;
                            } else {
                                P9Favourites.showNotification(
                                    (response.data && response.data.message) || 'Operation failed',
                                    'error'
                                );
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        // Check if it's a 401 (Unauthorized)
                        if (xhr.status === 401) {
                            const response = xhr.responseJSON;
                            
                            if (response) {
                                // Check for login_required flag in different possible locations
                                const loginRequired = 
                                    (response.data && response.data.login_required) ||
                                    (response.login_required) ||
                                    false;
                                
                                if (loginRequired) {
                                    P9Favourites.handleLoginRequired(productId);
                                    return;
                                }
                            }
                        }
                        
                        console.error('Favourites error:', status);
                        P9Favourites.showNotification('Network error – please try again', 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        },

        /**
         * Handle login required redirect
         */
        handleLoginRequired: function(productId) {
            // Build the redirect URL (where to go after login) — include server-side nonce
            const portalUrl = window.location.origin + '/user-portal/favourites/';
            const redirectUrl = portalUrl + '?portcld9_add_favourite=' + productId;
            
            // Build login URL with the portal redirect
            const loginUrl = portalcloud9_favourites.login_url +
                '?redirect_to=' + encodeURIComponent(redirectUrl);
            
            // Show notification before redirect
            P9Favourites.showNotification(
                'Please login to add to favourites',
                'info'
            );
            
            // Redirect to login page after a short delay
            setTimeout(function() {
                window.location.href = loginUrl;
            }, 1000);
        },

        /**
         * Handle individual remove buttons
         */
        removeButtons: function() {
            $(document).on('click', '.p9-remove-fave', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const $btn = $(this);
                const productId = $btn.data('product-id');
                const $card = $btn.closest('.p9-favourite-card');

                if (!productId) {
                    console.error('No product ID found');
                    return;
                }

                // Check if AJAX URL and nonce exist
                if (!portalcloud9_favourites || !portalcloud9_favourites.ajax_url) {
                    console.error('AJAX URL not found');
                    P9Favourites.showNotification('Configuration error', 'error');
                    return;
                }

                if (!portalcloud9_favourites.nonce) {
                    console.error('Security nonce not found');
                    P9Favourites.showNotification('Security error', 'error');
                    return;
                }

                // Add removing animation
                $card.addClass('p9-removing');

                $.ajax({
                    url: portalcloud9_favourites.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'portalcloud9_remove_favourite',
                        nonce: portalcloud9_favourites.nonce,
                        product_id: productId
                    },
                    dataType: 'json',
                    timeout: 10000,
                    success: function(response) {
                        if (response.success) {
                            const newCount = response.data.count;

                            // Fade out and remove card
                            $card.fadeOut(400, function() {
                                $card.remove();

                                // Reload page if no items left
                                if (newCount === 0) {
                                    setTimeout(() => location.reload(), 300);
                                }
                            });

                            // Update counters
                            P9Favourites.updateAllCounters(newCount);
                            P9Favourites.updateBulkButton();

                            P9Favourites.showNotification('Removed from favourites', 'success');
                        } else {
                            $card.removeClass('p9-removing');
                            P9Favourites.showNotification(
                                response.data.message || 'Remove failed',
                                'error'
                            );
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error Details:');
                        console.error('Status:', status);
                        console.error('Error:', error);
                        console.error('Response:', xhr.responseText);
                        console.error('Status Code:', xhr.status);
                        
                        $card.removeClass('p9-removing');
                        
                        let errorMessage = 'Network error – please try again';
                        
                        // More specific error messages
                        if (xhr.status === 403) {
                            errorMessage = 'Permission denied - please refresh the page';
                        } else if (xhr.status === 404) {
                            errorMessage = 'Request not found';
                        } else if (xhr.status === 500) {
                            errorMessage = 'Server error';
                        } else if (status === 'timeout') {
                            errorMessage = 'Request timed out';
                        } else if (status === 'parsererror') {
                            errorMessage = 'Invalid response from server';
                        }
                        
                        P9Favourites.showNotification(errorMessage, 'error');
                    }
                });
            });
        },

        /**
         * Handle add to cart functionality
         */
        addToCart: function() {
            $(document).on('click', '.p9-add-to-cart', function(e) {
                e.preventDefault();

                const $btn = $(this);
                const productId = $btn.data('product-id');
                const $card = $btn.closest('.p9-favourite-card');
                const $btnText = $btn.find('.button-text');
                const $spinner = $btn.find('.loading-spinner');

                if (!productId) return;

                // Disable button and show spinner
                $btn.prop('disabled', true);
                $btnText.hide();
                $spinner.show();

                $.ajax({
                    url: portalcloud9_favourites.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'woocommerce_add_to_cart',
                        product_id: productId,
                        quantity: 1
                    },
                    dataType: 'json',
                    timeout: 10000,
                    success: function(response) {
                        if (response && !response.error) {
                            P9Favourites.showNotification('✓ Added to cart!', 'success');

                            // Remove from favourites after adding to cart
                            $card.addClass('p9-removing');

                            setTimeout(() => {
                                $.ajax({
                                    url: portalcloud9_favourites.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'portalcloud9_remove_favourite',
                                        nonce: portalcloud9_favourites.nonce,
                                        product_id: productId
                                    },
                                    dataType: 'json',
                                    success: function(removeResponse) {
                                        if (removeResponse.success) {
                                            const newCount = removeResponse.data.count;

                                            $card.fadeOut(400, function() {
                                                $card.remove();

                                                // Trigger WooCommerce fragment refresh
                                                $(document.body).trigger('wc_fragment_refresh');

                                                // Reload if no items left
                                                if (newCount === 0) {
                                                    setTimeout(() => location.reload(), 300);
                                                }
                                            });

                                            P9Favourites.updateAllCounters(newCount);
                                            P9Favourites.updateBulkButton();
                                        }
                                    },
                                    error: function() {
                                        // Still remove the card even if AJAX fails
                                        $card.fadeOut(400, function() {
                                            $card.remove();
                                        });
                                    }
                                });
                            }, 200);
                        } else {
                            P9Favourites.showNotification('Failed to add to cart', 'error');
                            $btn.prop('disabled', false);
                            $btnText.show();
                            $spinner.hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Add to cart error:', status, error);
                        P9Favourites.showNotification('Network error – please try again', 'error');
                        $btn.prop('disabled', false);
                        $btnText.show();
                        $spinner.hide();
                    }
                });
            });
        },

        /**
         * Handle bulk selection and removal
         */
        bulkActions: function() {
            // Select all checkbox
            $(document).on('change', '#p9-select-all', function() {
                const isChecked = $(this).is(':checked');
                $('.p9-bulk-checkbox').prop('checked', isChecked);
                P9Favourites.updateBulkButton();
            });

            // Individual checkboxes
            $(document).on('change', '.p9-bulk-checkbox', function() {
                const checkedCount = $('.p9-bulk-checkbox:checked').length;
                const totalCount = $('.p9-bulk-checkbox').length;

                $('#p9-select-all').prop('checked', checkedCount === totalCount);
                P9Favourites.updateBulkButton();
            });

            // Bulk remove button
            $(document).on('click', '#p9-bulk-remove', function(e) {
                e.preventDefault();

                const selectedCount = $(this).attr('data-selected');

                if (!selectedCount || selectedCount === '0') return;

                const productIds = [];
                $('.p9-bulk-checkbox:checked').each(function() {
                    productIds.push($(this).data('product-id'));
                });

                if (productIds.length === 0) return;

                // Confirm deletion
                const confirmMsg = productIds.length === 1 
                    ? 'Remove this item?' 
                    : 'Remove ' + productIds.length + ' items?';

                if (!confirm(confirmMsg)) return;

                // Disable button
                $(this)
                    .prop('disabled', true)
                    .find('.p9-bulk-text')
                    .text('Removing...');

                $.ajax({
                    url: portalcloud9_favourites.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'portalcloud9_bulk_remove_favourites',
                        nonce: portalcloud9_favourites.nonce,
                        product_ids: productIds
                    },
                    dataType: 'json',
                    timeout: 15000,
                    success: function(response) {
                        if (response.success) {
                            const newCount = response.data.count;

                            // Remove each card
                            productIds.forEach(function(id) {
                                $('.p9-favourite-card[data-product-id="' + id + '"]')
                                    .fadeOut(400, function() {
                                        $(this).remove();

                                        // Reload if no items left
                                        if (newCount === 0) {
                                            setTimeout(() => location.reload(), 500);
                                        }
                                    });
                            });

                            P9Favourites.updateAllCounters(newCount);
                            P9Favourites.showNotification(
                                response.data.message || 'Items removed',
                                'success'
                            );

                            // Reset select all checkbox
                            $('#p9-select-all').prop('checked', false);

                            setTimeout(() => P9Favourites.updateBulkButton(), 500);
                        } else {
                            P9Favourites.showNotification(
                                response.data.message || 'Bulk remove failed',
                                'error'
                            );
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        P9Favourites.showNotification('Network error – please try again', 'error');
                    },
                    complete: function() {
                        $('#p9-bulk-remove')
                            .prop('disabled', false)
                            .find('.p9-bulk-text')
                            .text('Remove Selected');

                        P9Favourites.updateBulkButton();
                    }
                });
            });
        },

        /**
         * Periodically update favourite status for all heart buttons
         */
        updateCounter: function() {
            setInterval(() => {
                if ($('.p9-favourite-btn').length > 0) {
                    const productIds = [];

                    $('.p9-favourite-btn').each(function() {
                        const id = $(this).data('product-id');
                        if (id) productIds.push(id);
                    });

                    if (productIds.length > 0) {
                        $.ajax({
                            url: portalcloud9_favourites.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'portalcloud9_get_favourite_status',
                                nonce: portalcloud9_favourites.nonce,
                                product_ids: productIds
                            },
                            dataType: 'json',
                            timeout: 5000,
                            success: function(response) {
                                if (response.success) {
                                    $.each(response.data, (productId, isFavourite) => {
                                        $('.p9-favourite-btn[data-product-id="' + productId + '"]')
                                            .toggleClass('is-active', isFavourite)
                                            .attr({
                                                'data-is-favourite': isFavourite ? 'true' : 'false',
                                                'aria-pressed': isFavourite ? 'true' : 'false'
                                            });
                                    });
                                }
                            }
                        });
                    }
                }
            }, 30000); // Check every 30 seconds
        },

        /**
         * Handle share buttons
         */
        shareButtons: function() {
            $(document).on('click', '.p9-share-btn', function(e) {
                e.preventDefault();

                const shareType = $(this).data('share');
                const url = window.location.href;
                const encodedUrl = encodeURIComponent(url);
                const text = encodeURIComponent('Check out my favourites!');

                switch (shareType) {
                    case 'facebook':
                        window.open(
                            'https://www.facebook.com/sharer/sharer.php?u=' + encodedUrl,
                            '_blank',
                            'width=600,height=400'
                        );
                        break;

                    case 'twitter':
                        window.open(
                            'https://twitter.com/intent/tweet?url=' + encodedUrl + '&text=' + text,
                            '_blank',
                            'width=600,height=400'
                        );
                        break;

                    case 'whatsapp':
                        window.open(
                            'https://wa.me/?text=' + text + '%20' + encodedUrl,
                            '_blank',
                            'width=600,height=400'
                        );
                        break;

                    case 'copy':
                        if (navigator.clipboard) {
                            navigator.clipboard.writeText(url)
                                .then(() => {
                                    P9Favourites.showNotification('Link copied to clipboard!', 'success');
                                })
                                .catch(() => {
                                    P9Favourites.showNotification('Failed to copy link', 'error');
                                });
                        } else {
                            P9Favourites.showNotification('Clipboard not supported', 'error');
                        }
                        break;
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        P9Favourites.init();
        P9Favourites.updateBulkButton();
        
        // ── Guest click on favourites count badge ─────────────────────────
        // When a logged-out visitor clicks the badge, intercept the click,
        // show a friendly toast, then redirect to login (with redirect_to
        // pointing at the Favourites tab so they land there after logging in).
        $(document).on('click', '.p9-fav-counter-link--guest', function(e) {
            e.preventDefault();
            const loginUrl = $(this).attr('href');
            P9Favourites.showNotification(
                'Please log in to view your favourites ❤️',
                'info'
            );
            setTimeout(function() {
                window.location.href = loginUrl;
            }, 1200);
        });

        // Check if product was just added after login
        const urlParams = new URLSearchParams(window.location.search);
        const addedProductId = urlParams.get('fav_added');
        
        if (addedProductId) {
            // Show success notification
            P9Favourites.showNotification('Product added to favourites!', 'success');
            
            // Clean up URL (remove the parameter)
            const cleanUrl = window.location.protocol + "//" + 
                           window.location.host + 
                           window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    });

})(jQuery);

/* ============================================
   FAVOURITES NOTIFICATION AUTO-HIDE
   ============================================ */
(function() {
    // Auto-hide notification after 5 seconds
    setTimeout(function() {
        const notification = document.getElementById('p9-login-notification');
        if (notification) {
            notification.classList.add('p9-fading-out');
            setTimeout(function() {
                notification.remove();
                // Clean up URL by removing the query parameter
                if (window.history && window.history.replaceState) {
                    const url = new URL(window.location);
                    url.searchParams.delete('favourite_added');
                    window.history.replaceState({}, '', url);
                }
            }, 500);
        }
    }, 5000);
})();
