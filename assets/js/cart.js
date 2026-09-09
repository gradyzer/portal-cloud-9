/**
 * ============================================================================
 * Portal Cloud 9 - Shopping Cart Management
 * ============================================================================
 * 
 * Comprehensive cart management system with:
 * - Real-time quantity updates with debouncing
 * - Item addition and removal
 * - Cart total calculations
 * - Coupon application and removal
 * - Toast notifications for user feedback
 * - Empty cart state handling
 * - Loading states and animations
 * - AJAX-powered operations
 * 
 * Features:
 * - Automatic cart summary updates
 * - Quantity validation (min/max)
 * - Coupon code validation
 * - Responsive design support
 * - Smooth animations
 * - Error handling with user feedback
 * 
 * Dependencies: jQuery, WooCommerce
 * 
 * @package Portal_Cloud_9
 * @version 8.3.6
 * @author Brian Agoi (Gradyzer)
 * @company Gradyzer
 * @license GPL-2.0+
 * ============================================================================
 */

(function($) {
    'use strict';
    
    /**
     * Main Cart Manager Object
     * Handles all shopping cart operations
     */
    const P9Cart = {
        /**
         * Configuration settings
         */
        config: {
            updateDelay: 300,        // Debounce delay for quantity updates (ms)
            toastDuration: 4000,     // How long toast notifications show (ms)
            animationDuration: 400   // Animation duration for transitions (ms)
        },
        
        /**
         * jQuery selectors for DOM elements
         * Centralized for easy maintenance
         */
        selectors: {
            container: '.p9-cart-wrapper',
            item: '.p9-cart-item',
            itemsSection: '.p9-cart-items-section',
            qtyInput: '.p9-qty-input',
            qtyPlus: '.p9-qty-plus',
            qtyMinus: '.p9-qty-minus',
            removeBtn: '.p9-remove-btn',
            subtotal: '.p9-item-subtotal',
            summarySubtotal: '#p9-summary-subtotal',
            summaryShipping: '#p9-summary-shipping',
            summaryDiscount: '#p9-summary-discount',
            summaryTotal: '#p9-summary-total',
            cartCount: '.p9-cart-count-number',
            couponToggle: '.p9-coupon-toggle',
            couponForm: '.p9-coupon-form',
            couponInput: '.p9-coupon-input',
            couponApply: '.p9-coupon-apply',
            couponMessage: '.p9-coupon-message',
            appliedCoupons: '.p9-applied-coupons',
            removeCoupon: '.p9-remove-coupon',
            toastContainer: '.p9-toast-container'
        },
        
        /**
         * State management
         */
        state: {
            updateTimeout: null,     // Timeout ID for debouncing
            isUpdating: false        // Flag to prevent concurrent updates
        },
        
        /**
         * Get AJAX URL from localized script data.
         */
        getAjaxUrl: function() {
            if (typeof portcld9_cart_params !== 'undefined' && portcld9_cart_params.ajax_url) {
                return portcld9_cart_params.ajax_url;
            }
            // Fallback to WordPress global or products params
            if (typeof ajaxurl !== 'undefined') return ajaxurl;
            if (typeof portalcloud9_products !== 'undefined') return portalcloud9_products.ajax_url;
            return '';
        },
        
        /**
         * Initialize the cart manager
         * Sets up toast container and binds all events
         */
        init: function() {
            console.log('Portal Cloud 9 Cart: Script loaded');
            console.log('Portal Cloud 9 Cart: Checking for portcld9_cart_params...');
            console.log('Portal Cloud 9 Cart: typeof portcld9_cart_params =', typeof portcld9_cart_params);
            
            if (typeof portcld9_cart_params !== 'undefined') {
                console.log('Portal Cloud 9 Cart: portcld9_cart_params found!', portcld9_cart_params);
                console.log('Portal Cloud 9 Cart: ajax_url =', portcld9_cart_params.ajax_url);
                console.log('Portal Cloud 9 Cart: nonce =', portcld9_cart_params.nonce ? 'present' : 'MISSING');
            } else {
                console.error('Portal Cloud 9 Cart: portcld9_cart_params not defined!');
                console.log('Portal Cloud 9 Cart: Available global objects:', Object.keys(window).filter(k => k.includes('portal')));
                return;
            }
            
            this.createToastContainer();
            this.bindEvents();
            this.initCouponToggle();
        },
        
        /**
         * Create toast notification container if it doesn't exist
         * Toast container is used for showing success/error messages
         */
        createToastContainer: function() {
            if (!$(this.selectors.toastContainer).length) {
                $('body').append('<div class="p9-toast-container"></div>');
            }
        },
        
        /**
         * Bind all event listeners for cart interactions
         * Uses event delegation for dynamically added elements
         */
        bindEvents: function() {
            const self = this;
            
            // Quantity increase button
            $(document).on('click', this.selectors.qtyPlus, function(e) {
                e.preventDefault();
                self.handleQuantityChange($(this), 'increase');
            });
            
            // Quantity decrease button
            $(document).on('click', this.selectors.qtyMinus, function(e) {
                e.preventDefault();
                self.handleQuantityChange($(this), 'decrease');
            });
            
            // Direct quantity input changes
            $(document).on('change', this.selectors.qtyInput, function() {
                self.handleQuantityInput($(this));
            });
            
            // Prevent non-numeric input in quantity fields
            $(document).on('keypress', this.selectors.qtyInput, function(e) {
                // Allow only numbers (0-9)
                if (e.which < 48 || e.which > 57) {
                    e.preventDefault();
                }
            });
            
            // Remove item button
            $(document).on('click', this.selectors.removeBtn, function(e) {
                e.preventDefault();
                self.handleRemoveItem($(this));
            });
            
            // Apply coupon button
            $(document).on('click', this.selectors.couponApply, function(e) {
                e.preventDefault();
                self.handleApplyCoupon();
            });
            
            // Apply coupon on Enter key in coupon input
            $(document).on('keypress', this.selectors.couponInput, function(e) {
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    self.handleApplyCoupon();
                }
            });
            
            // Remove coupon button
            $(document).on('click', this.selectors.removeCoupon, function(e) {
                e.preventDefault();
                self.handleRemoveCoupon($(this));
            });
            
            // Toast close button
            $(document).on('click', '.p9-toast-close', function() {
                self.removeToast($(this).closest('.p9-toast'));
            });
        },
        
        /**
         * Initialize coupon toggle functionality
         * Shows/hides the coupon application form
         */
        initCouponToggle: function() {
            const self = this;
            $(document).on('click', this.selectors.couponToggle, function(e) {
                e.preventDefault();
                $(this).toggleClass('p9-open');
                $(self.selectors.couponForm).toggleClass('p9-visible');
            });
        },
        
        /**
         * Handle quantity increase/decrease button clicks
         * 
         * @param {jQuery} $button - The clicked button element
         * @param {string} action - 'increase' or 'decrease'
         */
        handleQuantityChange: function($button, action) {
            const $item = $button.closest(this.selectors.item);
            const $input = $item.find(this.selectors.qtyInput);
            
            // Get current, min, and max quantities
            const currentQty = parseInt($input.val(), 10) || 1;
            const minQty = parseInt($input.attr('min'), 10) || 1;
            const maxQty = parseInt($input.attr('max'), 10) || 9999;
            
            let newQty = currentQty;
            
            // Calculate new quantity based on action
            if (action === 'increase' && currentQty < maxQty) {
                newQty = currentQty + 1;
            } else if (action === 'decrease' && currentQty > minQty) {
                newQty = currentQty - 1;
            }
            
            // Update if quantity changed
            if (newQty !== currentQty) {
                $input.val(newQty);
                this.updateQuantity($item, newQty);
            }
            
            // Update button disabled states
            $item.find(this.selectors.qtyMinus).prop('disabled', newQty <= minQty);
            $item.find(this.selectors.qtyPlus).prop('disabled', newQty >= maxQty);
        },
        
        /**
         * Handle direct quantity input changes
         * Validates and clamps to min/max range
         * 
         * @param {jQuery} $input - The quantity input element
         */
        handleQuantityInput: function($input) {
            const $item = $input.closest(this.selectors.item);
            let newQty = parseInt($input.val(), 10);
            const minQty = parseInt($input.attr('min'), 10) || 1;
            const maxQty = parseInt($input.attr('max'), 10) || 9999;
            
            // Validate and clamp quantity
            if (isNaN(newQty) || newQty < minQty) {
                newQty = minQty;
            } else if (newQty > maxQty) {
                newQty = maxQty;
            }
            
            $input.val(newQty);
            this.updateQuantity($item, newQty);
        },
        
        /**
         * Update cart item quantity via AJAX
         * Debounced to prevent excessive server requests
         * 
         * @param {jQuery} $item - The cart item element
         * @param {number} quantity - New quantity value
         */
        updateQuantity: function($item, quantity) {
            const self = this;
            const cartItemKey = $item.data('cart-key');
            
            // Clear existing timeout (debouncing)
            clearTimeout(this.state.updateTimeout);
            
            // Set new timeout for update
            this.state.updateTimeout = setTimeout(function() {
                $item.addClass('p9-updating');
                
                $.ajax({
                    url: self.getAjaxUrl(),
                    type: 'POST',
                    data: {
                        action: 'portcld9_update_cart_quantity',
                        cart_item_key: cartItemKey,
                        quantity: quantity,
                        nonce: portcld9_cart_params.nonce
                    },
                    success: function(response) {
                        $item.removeClass('p9-updating');
                        
                        if (response.success) {
                            // Update item subtotal
                            $item.find(self.selectors.subtotal).html(response.data.item_subtotal);
                            
                            // Update cart summary
                            self.updateSummary(response.data);
                            
                            // Update cart count badge
                            $(self.selectors.cartCount).text(response.data.cart_count);
                            
                            // Show success notification
                            self.showToast('success', 'Cart Updated', 'Quantity updated successfully');
                        } else {
                            self.showToast('error', 'Error', response.data.message || 'Failed to update cart');
                        }
                    },
                    error: function() {
                        $item.removeClass('p9-updating');
                        self.showToast('error', 'Error', 'Connection failed. Please try again.');
                    }
                });
            }, this.config.updateDelay);
        },
        
        /**
         * Handle remove item button click
         * Shows loading state and removes item with animation
         * 
         * @param {jQuery} $button - The remove button element
         */
        handleRemoveItem: function($button) {
            const self = this;
            const $item = $button.closest(this.selectors.item);
            const cartItemKey = $item.data('cart-key');
            const productName = $item.find('.p9-cart-item-name').text().trim();
            
            // Show loading state
            $button.addClass('p9-loading');
            const originalHtml = $button.html();
            $button.html('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Removing...');
            
            $.ajax({
                url: self.getAjaxUrl(),
                type: 'POST',
                data: {
                    action: 'portcld9_remove_cart_item',
                    cart_item_key: cartItemKey,
                    nonce: portcld9_cart_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Animate removal
                        $item.addClass('p9-removing');
                        
                        setTimeout(function() {
                            $item.remove();
                            
                            // Update cart summary
                            self.updateSummary(response.data);
                            
                            // Update cart count
                            $(self.selectors.cartCount).text(response.data.cart_count);
                            
                            // Show empty cart if no items left
                            if (response.data.cart_count === 0) {
                                self.showEmptyCart();
                            }
                            
                            // Show success notification
                            self.showToast('success', 'Item Removed', `"${productName}" removed from cart`);
                        }, self.config.animationDuration);
                    } else {
                        $button.removeClass('p9-loading').html(originalHtml);
                        self.showToast('error', 'Error', response.data.message || 'Failed to remove item');
                    }
                },
                error: function() {
                    $button.removeClass('p9-loading').html(originalHtml);
                    self.showToast('error', 'Error', 'Connection failed. Please try again.');
                }
            });
        },
        
        /**
         * Handle coupon application
         * Validates input and applies coupon code
         */
        handleApplyCoupon: function() {
            const self = this;
            const $input = $(this.selectors.couponInput);
            const $button = $(this.selectors.couponApply);
            const $message = $(this.selectors.couponMessage);
            const couponCode = $input.val().trim();
            
            // Validate coupon code
            if (!couponCode) {
                $message.removeClass('p9-success').addClass('p9-error')
                    .text('Please enter a coupon code').show();
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).text('Applying...');
            $message.hide();
            
            $.ajax({
                url: self.getAjaxUrl(),
                type: 'POST',
                data: {
                    action: 'portcld9_apply_coupon',
                    coupon_code: couponCode,
                    nonce: portcld9_cart_params.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Apply');
                    
                    if (response.success) {
                        // Clear input
                        $input.val('');
                        
                        // Show success message
                        $message.removeClass('p9-error').addClass('p9-success')
                            .text(response.data.message).show();
                        
                        // Update cart summary
                        self.updateSummary(response.data);
                        
                        // Add visual coupon tag
                        self.addAppliedCoupon(couponCode);
                        
                        // Show toast notification
                        self.showToast('success', 'Coupon Applied', response.data.message);
                    } else {
                        $message.removeClass('p9-success').addClass('p9-error')
                            .text(response.data.message).show();
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Apply');
                    $message.removeClass('p9-success').addClass('p9-error')
                        .text('Connection failed').show();
                }
            });
        },
        
        /**
         * Add visual representation of applied coupon
         * 
         * @param {string} code - Coupon code to display
         */
        addAppliedCoupon: function(code) {
            const $container = $(this.selectors.appliedCoupons);
            const couponHtml = `
                <div class="p9-applied-coupon" data-coupon="${code}">
                    <span class="p9-coupon-code">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                            <line x1="7" y1="7" x2="7.01" y2="7"/>
                        </svg>
                        ${code.toUpperCase()}
                    </span>
                    <button class="p9-remove-coupon" data-coupon="${code}" aria-label="Remove coupon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            `;
            $container.append(couponHtml);
        },
        
        /**
         * Handle coupon removal
         * 
         * @param {jQuery} $button - The remove coupon button
         */
        handleRemoveCoupon: function($button) {
            const self = this;
            const couponCode = $button.data('coupon');
            const $couponElement = $button.closest('.p9-applied-coupon');
            
            $.ajax({
                url: self.getAjaxUrl(),
                type: 'POST',
                data: {
                    action: 'portcld9_remove_coupon',
                    coupon_code: couponCode,
                    nonce: portcld9_cart_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Animate removal
                        $couponElement.fadeOut(300, function() {
                            $(this).remove();
                        });
                        
                        // Update cart summary
                        self.updateSummary(response.data);
                        
                        // Show notification
                        self.showToast('success', 'Coupon Removed', `Coupon "${couponCode.toUpperCase()}" removed`);
                    } else {
                        self.showToast('error', 'Error', response.data.message || 'Failed to remove coupon');
                    }
                },
                error: function() {
                    self.showToast('error', 'Error', 'Connection failed');
                }
            });
        },
        
        /**
         * Update cart summary totals
         * Updates subtotal, shipping, discount, and total
         * 
         * @param {Object} data - Cart data from server
         */
        updateSummary: function(data) {
            // Update subtotal
            if (data.cart_subtotal) {
                $(this.selectors.summarySubtotal).html(data.cart_subtotal);
            }
            
            // Update shipping (show/hide row as needed)
            if (data.cart_shipping !== undefined) {
                const $shippingRow = $(this.selectors.summaryShipping).closest('.p9-summary-row');
                if (data.cart_shipping) {
                    $shippingRow.show().find(this.selectors.summaryShipping).html(data.cart_shipping);
                } else {
                    $shippingRow.hide();
                }
            }
            
            // Update discount (show/hide row as needed)
            if (data.cart_discount !== undefined) {
                const $discountRow = $(this.selectors.summaryDiscount).closest('.p9-summary-row');
                if (data.cart_discount && data.cart_discount !== '$0.00' && data.cart_discount !== '0') {
                    $discountRow.show().find(this.selectors.summaryDiscount).html('-' + data.cart_discount);
                } else {
                    $discountRow.hide();
                }
            }
            
            // Update total
            if (data.cart_total) {
                $(this.selectors.summaryTotal).html(data.cart_total);
            }
        },
        
        /**
         * Show empty cart state
         * Replaces cart items with empty cart message and CTA
         */
        showEmptyCart: function() {
            const emptyHtml = `
                <div class="p9-empty-cart">
                    <div class="p9-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="9" cy="21" r="1"/>
                            <circle cx="20" cy="21" r="1"/>
                            <path d="m1 1 4 4 1.7 9.4a2 2 0 0 0 2 1.6h9.6a2 2 0 0 0 2-1.6L23 6H6"/>
                        </svg>
                    </div>
                    <h2 class="p9-empty-title">Your cart is empty</h2>
                    <p class="p9-empty-message">Looks like you haven't added anything to your cart yet. Start shopping to fill it up!</p>
                    <a href="${portcld9_cart_params.shop_url}" class="p9-btn p9-btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        Start Shopping
                    </a>
                </div>
            `;
            $(this.selectors.container).find('.p9-cart-container').html(emptyHtml);
        },
        
        /**
         * Show toast notification
         * 
         * @param {string} type - 'success', 'error', or 'info'
         * @param {string} title - Notification title
         * @param {string} message - Notification message
         */
        showToast: function(type, title, message) {
            const self = this;
            
            // Toast icons for different types
            const icons = {
                success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
            };
            
            // Create toast element
            const $toast = $(`
                <div class="p9-toast p9-toast-${type}">
                    <div class="p9-toast-icon">${icons[type]}</div>
                    <div class="p9-toast-content">
                        <h4 class="p9-toast-title">${title}</h4>
                        <p class="p9-toast-message">${message}</p>
                    </div>
                    <button class="p9-toast-close" aria-label="Close notification">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            `);
            
            // Append to container
            $(this.selectors.toastContainer).append($toast);
            
            // Auto-remove after duration
            setTimeout(function() {
                self.removeToast($toast);
            }, this.config.toastDuration);
        },
        
        /**
         * Remove toast notification with animation
         * 
         * @param {jQuery} $toast - Toast element to remove
         */
        removeToast: function($toast) {
            $toast.addClass('p9-toast-out');
            setTimeout(function() {
                $toast.remove();
            }, 300);
        }
    };
    
    /**
     * Initialize cart on document ready
     */
    $(document).ready(function() {
        if ($(P9Cart.selectors.container).length) {
            P9Cart.init();
        }
    });
    
    /**
     * Expose to global scope for debugging
     */
    window.P9Cart = P9Cart;
    
})(jQuery);
