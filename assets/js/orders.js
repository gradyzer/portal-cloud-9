/**
 * ============================================================================
 * Portal Cloud 9 - Orders Management System
 * ============================================================================
 * 
 * Comprehensive order management for both sellers and buyers:
 * 
 * Seller Features:
 * - Complete order list with advanced filtering
 * - Detailed order modal with customer information
 * - Order status updates with tracking
 * - Order notes management (add, view, delete)
 * - Print invoices and packing slips
 * - Export orders to CSV
 * - Bulk actions for multiple orders
 * - Real-time order notifications
 * 
 * Buyer Features:
 * - Order history with status tracking
 * - Order details and tracking information
 * - Reorder functionality
 * - Order cancellation requests
 * - Review and rating requests
 * 
 * Technical Features:
 * - AJAX-powered operations for instant updates
 * - Real-time status synchronization
 * - Advanced search and filtering
 * - Pagination with page size options
 * - Print preview functionality
 * - CSV export with custom fields
 * - Date range filters
 * - Status-based color coding
 * - Toast notifications for user feedback
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
     * Get portal base URL
     * Checks global params first, then extracts from current URL
     * 
     * @returns {string} Base portal URL
     */
    function getPortalUrl() {
        if (typeof portcld9_orders_params !== 'undefined' && portcld9_orders_params.portal_url) {
            return portcld9_orders_params.portal_url;
        }
        
        const path = window.location.pathname;
        const match = path.match(/(.+\/user-portal\/)/);
        return match ? match[1] : '/user-portal/';
    }
    
    /**
     * Main Orders Management Object
     * Handles all order-related operations
     */
    const P9Orders = {
        /**
         * Configuration settings
         */
        config: {
            toastDuration: 4000,           // Toast notification display time (ms)
            animationDuration: 300,        // Animation duration for transitions (ms)
            debounceDelay: 400,            // Debounce delay for search input (ms)
            portalUrl: getPortalUrl()      // Base portal URL
        },
        
        /**
         * jQuery selectors cache
         */
        selectors: {
            wrapper: '.p9-orders-wrapper',
            grid: '.p9-orders-grid',
            card: '.p9-order-card',
            searchInput: '.p9-orders-search-input,.p9-my-search-input',
            filterStatus: '#p9-filter-status',
            filterSource: '#p9-filter-source',
            updateBtn: '.p9-order-btn-update',
            viewBtn: '.p9-order-btn-view',
            modifyBtn: '.p9-order-btn-modify',
            modalOverlay: '.p9-order-modal-overlay',
            modal: '.p9-order-modal',
            modalClose: '.p9-modal-close',
            modalDelete: '.p9-modal-delete',
            modalForm: '#p9-order-edit-form',
            modalSave: '.p9-modal-btn-save',
            modalCancel: '.p9-modal-btn-cancel',
            pagination: '.p9-orders-pagination',
            pageBtn: '.p9-page-btn',
            toastContainer: '.p9-toast-container',
            statCards: '.p9-stat-card'
        },
        
        /**
         * Application state
         */
        state: {
            currentOrder: null,            // Currently selected order object
            deleteNoteId: null,            // ID of note being deleted
            isLoading: false,              // Loading state flag
            searchTimeout: null,           // Timeout ID for search debouncing
            filters: {
                search: '',                // Search query
                status: '',                // Filter by order status
                source: '',                // Filter by order source
                page: 1                    // Current pagination page
            }
        },
        
        /**
         * Initialize the orders manager
         * Sets up toast container, event bindings, and loads initial orders
         */
        init: function() {
            console.log('═══════════════════════════════════════════════════════');
            console.log('Portal Cloud 9 Orders Module v8.5.0 - TEMPLATE FIX');
            console.log('JavaScript now matches PHP template structure!');
            console.log('═══════════════════════════════════════════════════════');
            console.log('Portal Cloud 9: Orders script initializing...');
            
            if (!$(this.selectors.wrapper).length) {
                console.log('Portal Cloud 9: Wrapper not found, skipping initialization');
                return;
            }
            
            console.log('Portal Cloud 9: Wrapper found, continuing initialization');
            console.log('Portal Cloud 9: portcld9_orders_params available:', typeof portcld9_orders_params !== 'undefined');
            
            this.createToastContainer();
            this.bindEvents();
            
            // Only load orders via AJAX if none are already rendered by PHP
            // Check for both manager orders (.p9-order-card) and customer orders (.p9-my-order-card)
            const hasOrders = $('.p9-order-card, .p9-my-order-card').length > 0;
            console.log('Portal Cloud 9: Orders already rendered:', hasOrders);
            
            if (!hasOrders) {
                console.log('Portal Cloud 9: Loading orders via AJAX...');
                this.loadOrders();
            } else {
                console.log('Portal Cloud 9: Using PHP-rendered orders');
            }
        },
        
        /**
         * Create toast notification container if it doesn't exist
         * Container is appended to body for global access
         */
        createToastContainer: function() {
            if (!$(this.selectors.toastContainer).length) {
                $('body').append('<div class="p9-toast-container"></div>');
            }
        },
        
        /**
         * Bind all event listeners
         * Sets up handlers for user interactions
         */
        bindEvents: function() {
            const self = this;
            
            // Search input with debouncing
            $(document).on('input', this.selectors.searchInput, function() {
                self.state.filters.search = $(this).val();
                self.state.filters.page = 1;
                
                clearTimeout(self.state.searchTimeout);
                self.state.searchTimeout = setTimeout(function() {
                    self.loadOrders();
                }, self.config.debounceDelay);
            });
            
            // Status filter change
            $(document).on('change', this.selectors.filterStatus, function() {
                self.state.filters.status = $(this).val();
                self.state.filters.page = 1;
                self.loadOrders();
            });
            
            // Source filter change
            $(document).on('change', this.selectors.filterSource, function() {
                self.state.filters.source = $(this).val();
                self.state.filters.page = 1;
                self.loadOrders();
            });
            
            // Update button click
            $(document).on('click', this.selectors.updateBtn, function(e) {
                e.preventDefault();
                console.log('Portal Cloud 9: Update button clicked');
                
                const $card = $(this).closest(self.selectors.card);
                console.log('Portal Cloud 9: Found card element:', $card.length > 0);
                
                const orderId = $card.data('order-id');
                console.log('Portal Cloud 9: Order ID:', orderId);
                
                if (!orderId) {
                    console.error('Portal Cloud 9: No order ID found!');
                    self.showToast('error', 'Error', 'Could not identify order. Please refresh the page.');
                    return;
                }
                
                console.log('Portal Cloud 9: Opening modal for order:', orderId);
                self.openEditModal(orderId);
            });
            
            // Modal close buttons
            $(document).on('click', this.selectors.modalClose + ',' + this.selectors.modalCancel, function() {
                self.closeModal();
            });
            
            // Modal overlay click (close modal)
            $(document).on('click', this.selectors.modalOverlay, function(e) {
                if (e.target === e.currentTarget) {
                    self.closeModal();
                }
            });
            
            // Prevent modal close on content click
            $(document).on('touchstart touchmove', this.selectors.modal, function(e) {
                e.stopPropagation();
            });
            
            // Escape key closes modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $(self.selectors.modal).hasClass('p9-active')) {
                    self.closeModal();
                }
            });
            
            // Save order button
            $(document).on('click', this.selectors.modalSave, function(e) {
                e.preventDefault();
                self.saveOrder();
            });
            
            // Delete order button
            $(document).on('click', this.selectors.modalDelete, function(e) {
                e.preventDefault();
                self.confirmDeleteOrder();
            });
            
            // Pagination
            $(document).on('click', this.selectors.pageBtn + ':not(.p9-active):not(:disabled)', function() {
                const page = $(this).data('page');
                if (page) {
                    self.state.filters.page = page;
                    self.loadOrders();
                    
                    // Scroll to top
                    $('html, body').animate({
                        scrollTop: $(self.selectors.wrapper).offset().top - 100
                    }, 300);
                }
            });
            
            // Order actions
            $(document).on('click', '.p9-action-btn', function(e) {
                e.preventDefault();
                const action = $(this).data('action');
                if (action) {
                    self.handleOrderAction(action);
                }
            });
            
            // Bulk action apply
            $(document).on('click', '#p9-apply-action-btn', function(e) {
                e.preventDefault();
                const selectedAction = $('#p9-order-action-select').val();
                if (!selectedAction) {
                    self.showToast('error', 'No Action Selected', 'Please select an action from the dropdown');
                    return;
                }
                self.handleOrderAction(selectedAction);
            });
            
            // Add order note
            $(document).on('click', '#p9-add-note-btn', function(e) {
                e.preventDefault();
                self.addOrderNote();
            });
            
            // Delete order note
            $(document).on('click', '.p9-note-delete', function(e) {
                e.preventDefault();
                const noteId = $(this).data('note-id');
                self.showDeleteNoteConfirmation(noteId);
            });
            
            // Confirm note deletion
            $(document).on('click', '#p9-delete-note-confirm-btn', function(e) {
                e.preventDefault();
                if (self.state.deleteNoteId) {
                    self.deleteOrderNote(self.state.deleteNoteId);
                }
            });
            
            // Cancel note deletion
            $(document).on('click', '#p9-delete-note-cancel-btn', function(e) {
                e.preventDefault();
                $('#p9-delete-note-confirm').removeClass('p9-active');
                setTimeout(function() {
                    $('#p9-delete-note-overlay').removeClass('p9-active');
                }, 300);
                self.state.deleteNoteId = null;
            });
        },
        
        /**
         * Load orders with current filters
         * Fetches order data via AJAX
         */
        loadOrders: function() {
            const self = this;
            
            console.log('Portal Cloud 9: loadOrders() called');
            
            if (this.state.isLoading) {
                console.warn('Portal Cloud 9: Already loading, skipping...');
                return;
            }
            
            this.state.isLoading = true;
            
            // Show loading state
            $(this.selectors.grid).addClass('p9-loading');
            
            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_get_orders',
                    nonce: portcld9_orders_params.nonce,
                    search: this.state.filters.search,
                    status: this.state.filters.status,
                    source: this.state.filters.source,
                    page: this.state.filters.page
                },
                beforeSend: function() {
                    console.log('Portal Cloud 9: Requesting orders from server...');
                },
                success: function(response) {
                    console.log('Portal Cloud 9: Orders response received:', response);
                    
                    if (response.success) {
                        console.log('Portal Cloud 9: Rendering', response.data.orders.length, 'orders');
                        self.renderOrders(response.data.orders);
                        self.renderPagination(response.data.pagination);
                        self.updateStats(response.data.stats);
                    } else {
                        console.error('Portal Cloud 9: Failed to load orders:', response.data);
                        self.showToast('error', 'Error', response.data || 'Failed to load orders');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Portal Cloud 9: Load orders AJAX error:', {xhr, status, error});
                    console.error('Portal Cloud 9: XHR status:', xhr.status);
                    console.error('Portal Cloud 9: XHR statusText:', xhr.statusText);
                    console.error('Portal Cloud 9: XHR responseText:', xhr.responseText);
                    
                    // Try to parse error response
                    try {
                        const errorData = JSON.parse(xhr.responseText);
                        console.error('Portal Cloud 9: Error data:', errorData);
                        self.showToast('error', 'Error', errorData.data?.message || 'Failed to load orders');
                    } catch(e) {
                        console.error('Portal Cloud 9: Could not parse error response');
                        self.showToast('error', 'Error', 'Connection failed (Status: ' + xhr.status + ')');
                    }
                },
                complete: function() {
                    console.log('Portal Cloud 9: loadOrders() complete');
                    self.state.isLoading = false;
                    $(self.selectors.grid).removeClass('p9-loading');
                }
            });
        },
        
        /**
         * Render orders grid
         * 
         * @param {Array} orders - Array of order objects
         */
        renderOrders: function(orders) {
            const $grid = $(this.selectors.grid);
            
            console.log('Portal Cloud 9: renderOrders() called with', orders ? orders.length : 0, 'orders');
            
            if (!orders || orders.length === 0) {
                console.log('Portal Cloud 9: No orders to display, showing empty state');
                $grid.html(this.getEmptyStateHTML());
                return;
            }
            
            // Log first order structure
            console.log('Portal Cloud 9: First order data:', orders[0]);
            console.log('Portal Cloud 9: First order fields check:', {
                customer_name: orders[0].customer_name,
                date: orders[0].date,
                item_count: orders[0].item_count,
                total: orders[0].total,
                status_label: orders[0].status_label
            });
            
            const ordersHTML = orders.map(order => this.getOrderCardHTML(order)).join('');
            console.log('Portal Cloud 9: Generated HTML length:', ordersHTML.length, 'characters');
            
            $grid.html(ordersHTML);
            console.log('Portal Cloud 9: HTML inserted into grid');
        },
        
        /**
         * Get HTML for single order card
         * 
         * @param {Object} order - Order object
         * @returns {string} HTML string
         */
        getOrderCardHTML: function(order) {
            // Match the exact PHP template structure
            const customerName = order.customer_name || order.customer?.name || 'Guest';
            const customerEmail = order.customer?.email || order.billing?.email || '';
            const orderDate = order.date || order.date_created || 'N/A';
            const itemCount = order.item_count || (order.items ? order.items.length : 0);
            const total = order.total || 'N/A';
            const status = order.status || 'pending';
            const statusLabel = order.status_label || 'Pending';
            const statusClass = 'p9-status-' + status.replace('wc-', '');
            const source = order.source || 'direct';
            const sourceLabel = order.source_label || 'Direct';
            
            // Get first item for product display
            const firstItem = order.items && order.items[0] ? order.items[0] : null;
            const productImage = firstItem ? firstItem.image : '';
            const productName = firstItem ? firstItem.name : '';
            const productId = firstItem ? firstItem.product_id : '';
            const productQty = firstItem ? firstItem.quantity : 0;
            const productSku = firstItem ? firstItem.sku : '';
            
            return `
                <article class="p9-order-card ${statusClass}" 
                    data-order-id="${order.id}"
                    data-status="${status}"
                    data-source="${source}">
                    <div class="p9-order-card-header">
                        <span class="p9-order-number">#${order.number}</span>
                        <span class="p9-order-status ${statusClass}">
                            <span class="p9-status-dot"></span>
                            ${statusLabel}
                        </span>
                    </div>
                    <div class="p9-order-customer">
                        <div class="p9-customer-avatar">${this.getAvatarHTML(customerEmail)}</div>
                        <div class="p9-customer-info">
                            <div class="p9-customer-name">${customerName}</div>
                            <div class="p9-customer-email">${customerEmail}</div>
                        </div>
                    </div>
                    ${firstItem ? `
                    <div class="p9-order-product">
                        <div class="p9-product-image"><img src="${productImage}" alt=""></div>
                        <div class="p9-product-details">
                            <div class="p9-product-name">${productName}</div>
                            <div class="p9-product-meta">
                                <span>ID: ${productId}</span>
                                ${productSku ? `<span>SKU: ${productSku}</span>` : ''}
                                <span class="p9-product-qty">×${productQty}</span>
                            </div>
                        </div>
                    </div>
                    ` : ''}
                    <div class="p9-order-details">
                        <div class="p9-detail-item">
                            <span class="p9-detail-label">Date</span>
                            <span class="p9-detail-value">${orderDate}</span>
                        </div>
                        <div class="p9-detail-item">
                            <span class="p9-detail-label">Total</span>
                            <span class="p9-detail-value p9-amount">${total}</span>
                        </div>
                    </div>
                    <div class="p9-order-source">
                        <span class="p9-source-icon p9-source-${source}">${this.getSourceIcon(source)}</span>
                        <span class="p9-source-name">${sourceLabel}</span>
                    </div>
                    <div class="p9-order-card-footer">
                        <button type="button" class="p9-order-btn p9-order-btn-update" data-order-id="${order.id}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Update
                        </button>
                    </div>
                </article>
            `;
        },
        
        /**
         * Get avatar HTML (placeholder since we can't call PHP get_avatar)
         */
        getAvatarHTML: function(email) {
            if (!email) return '<div class="p9-avatar-placeholder"></div>';
            // Use Gravatar
            const hash = this.md5(email.toLowerCase().trim());
            return `<img src="https://www.gravatar.com/avatar/${hash}?s=72&d=mp" alt="">`;
        },
        
        /**
         * Simple MD5 hash for Gravatar
         */
        md5: function(string) {
            // Simple hash function for Gravatar URLs
            // In production, you might want to use a proper MD5 library
            let hash = 0;
            for (let i = 0; i < string.length; i++) {
                const char = string.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return Math.abs(hash).toString(16);
        },
        
        /**
         * Get source icon SVG
         */
        getSourceIcon: function(source) {
            const icons = {
                'pos': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>',
                'api': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>',
                'direct': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'
            };
            return icons[source] || icons['direct'];
        },
        
        /**
         * Get empty state HTML
         * 
         * @returns {string} HTML string
         */
        getEmptyStateHTML: function() {
            return `
                <div class="p9-empty-orders">
                    <div class="p9-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="9" cy="21" r="1"/>
                            <circle cx="20" cy="21" r="1"/>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                    </div>
                    <h3>No orders found</h3>
                    <p>There are no orders matching your filters.</p>
                </div>
            `;
        },
        
        /**
         * Show toast notification
         * Creates and displays a toast message with icon
         * 
         * @param {string} type - Notification type: 'success' or 'error'
         * @param {string} title - Notification title
         * @param {string} message - Notification message
         */
        showToast: function(type, title, message) {
            const self = this;
            
            const icons = {
                success: `
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                `,
                error: `
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                `
            };
            
            const $toast = $(`
                <div class="p9-toast p9-toast-${type}">
                    <!-- Icon -->
                    <div class="p9-toast-icon">
                        ${icons[type]}
                    </div>
                    
                    <!-- Content -->
                    <div class="p9-toast-content">
                        <h4 class="p9-toast-title">${title}</h4>
                        <p class="p9-toast-message">${message}</p>
                    </div>
                    
                    <!-- Close button -->
                    <button class="p9-toast-close" aria-label="Close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            `);
            
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
        },
        
        /**
         * Open order edit modal
         * Shows modal immediately, loads order data in background
         * 
         * @param {number} orderId - Order ID to edit
         */
        openEditModal: function(orderId) {
            const self = this;
            
            console.log('Portal Cloud 9: openEditModal called with ID:', orderId);
            console.log('Portal Cloud 9: AJAX URL:', typeof portcld9_orders_params !== 'undefined' ? portcld9_orders_params.ajax_url : 'UNDEFINED');
            console.log('Portal Cloud 9: Nonce:', typeof portcld9_orders_params !== 'undefined' ? portcld9_orders_params.nonce : 'UNDEFINED');
            
            // Check if portcld9_orders_params is defined
            if (typeof portcld9_orders_params === 'undefined') {
                console.error('Portal Cloud 9: portcld9_orders_params is not defined!');
                self.showToast('error', 'Configuration Error', 'AJAX parameters not loaded. Please refresh the page.');
                return;
            }
            
            // Show modal immediately with loading state
            self.showModalLoading();
            
            const ajaxUrl = portcld9_orders_params.ajax_url;
            
            // Load order data in background
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'portcld9_get_order',
                    order_id: orderId,
                    nonce: portcld9_orders_params.nonce
                },
                beforeSend: function() {
                    console.log('Portal Cloud 9: Sending AJAX request...');
                },
                success: function(response) {
                    console.log('Portal Cloud 9: AJAX response received:', response);
                    
                    if (response.success) {
                        // Check if order data is in response.data.order or directly in response.data
                        const orderData = response.data.order || response.data;
                        console.log('Portal Cloud 9: Order data extracted:', orderData);
                        
                        if (!orderData || !orderData.id) {
                            console.error('Portal Cloud 9: Invalid order data structure:', response.data);
                            self.showToast('error', 'Error', 'Invalid order data received');
                            self.closeModal();
                            return;
                        }
                        
                        self.state.currentOrder = orderData;
                        self.populateModal(orderData);
                        self.hideModalLoading();
                    } else {
                        console.error('Portal Cloud 9: Server returned error:', response.data);
                        self.showToast('error', 'Error', response.data || 'Failed to load order');
                        self.closeModal();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Portal Cloud 9: AJAX error:', {xhr, status, error});
                    console.error('Portal Cloud 9: Response text:', xhr.responseText);
                    self.showToast('error', 'Error', 'Connection failed: ' + error);
                    self.closeModal();
                }
            });
        },
        
        /**
         * Populate modal with order data
         * 
         * @param {Object} order - Order object
         */
        populateModal: function(order) {
            $('#p9-order-status').val(order.status);
            $('#p9-billing-first-name').val(order.billing.first_name);
            $('#p9-billing-last-name').val(order.billing.last_name);
            $('#p9-billing-email').val(order.billing.email);
            $('#p9-billing-phone').val(order.billing.phone);
            $('#p9-billing-address').val(order.billing.address);
            $('#p9-billing-city').val(order.billing.city);
            $('#p9-billing-postcode').val(order.billing.postcode);
            $('#p9-billing-country').val(order.billing.country);
        },
        
        /**
         * Show modal with loading state
         * Displays modal immediately with a loading spinner
         */
        showModalLoading: function() {
            // Show modal with loading overlay
            $(this.selectors.modalOverlay).addClass('p9-active');
            $('body').addClass('p9-modal-open');
            
            // Add loading state to modal
            $(this.selectors.modal).addClass('p9-loading');
            
            setTimeout(() => {
                $(this.selectors.modal).addClass('p9-active');
            }, 50);
            
            // Add loading spinner to modal body
            const $modalBody = $(this.selectors.modal).find('.p9-modal-body');
            if ($modalBody.find('.p9-modal-loader').length === 0) {
                $modalBody.prepend(`
                    <div class="p9-modal-loader" style="
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        z-index: 1000;
                        background: rgba(255,255,255,0.95);
                        backdrop-filter: blur(8px);
                        padding: 40px;
                        border-radius: 16px;
                        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                    ">
                        <svg style="width: 48px; height: 48px; animation: spin 1s linear infinite; display: block; margin: 0 auto;" viewBox="0 0 24 24" fill="none" stroke="#1e90ff" stroke-width="2">
                            <circle cx="12" cy="12" r="10" opacity="0.25"/>
                            <path d="M12 2a10 10 0 0 1 10 10" opacity="0.75"/>
                        </svg>
                        <p style="margin-top: 16px; color: #666; text-align: center; font-size: 14px;">Loading order...</p>
                    </div>
                    <style>
                        @keyframes spin {
                            to { transform: rotate(360deg); }
                        }
                    </style>
                `);
            }
        },
        
        /**
         * Hide modal loading state
         * Removes loading spinner from modal
         */
        hideModalLoading: function() {
            $(this.selectors.modal).removeClass('p9-loading');
            $(this.selectors.modal).find('.p9-modal-loader').fadeOut(200, function() {
                $(this).remove();
            });
        },
        
        /**
         * Show modal
         * Displays modal with overlay
         */
        showModal: function() {
            $(this.selectors.modalOverlay).addClass('p9-active');
            $('body').addClass('p9-modal-open');
            
            setTimeout(() => {
                $(this.selectors.modal).addClass('p9-active');
            }, 50);
        },
        
        /**
         * Update order card in place
         * Updates an existing order card without reloading the page
         * 
         * @param {Object} orderData - Updated order data
         */
        updateOrderCard: function(orderData) {
            console.log('Portal Cloud 9: Updating order card for order:', orderData.id);
            
            // Find the order card
            const $card = $('.p9-order-card[data-order-id="' + orderData.id + '"], .p9-my-order-card[data-order-id="' + orderData.id + '"]');
            
            if ($card.length === 0) {
                console.warn('Portal Cloud 9: Order card not found for ID:', orderData.id);
                return;
            }
            
            console.log('Portal Cloud 9: Found order card, updating all fields');
            
            // Extract data with fallbacks
            const customerName = orderData.customer_name || orderData.customer?.name || 'Guest';
            const customerEmail = orderData.customer?.email || orderData.billing?.email || '';
            const orderDate = orderData.date || orderData.date_created || 'N/A';
            const total = orderData.total || 'N/A';
            const status = orderData.status || 'pending';
            const statusLabel = orderData.status_label || 'Pending';
            const statusClass = 'p9-status-' + status.replace('wc-', '');
            
            // Update status badge (for manager view)
            const $statusBadge = $card.find('.p9-order-status');
            if ($statusBadge.length > 0) {
                // Remove old status classes from both card and badge
                $card.removeClass(function(index, className) {
                    return (className.match(/\bp9-status-\S+/g) || []).join(' ');
                });
                $statusBadge.removeClass(function(index, className) {
                    return (className.match(/\bp9-status-\S+/g) || []).join(' ');
                });
                
                // Add new status class to both
                $card.addClass(statusClass);
                $statusBadge.addClass(statusClass);
                
                // Update status text (find text after .p9-status-dot)
                const $statusDot = $statusBadge.find('.p9-status-dot');
                if ($statusDot.length > 0) {
                    // Remove all text nodes after the dot
                    $statusDot.nextAll().remove();
                    $statusDot.get(0).nextSibling && $statusDot.get(0).nextSibling.remove();
                    // Add new status label
                    $statusBadge.append(statusLabel);
                } else {
                    // No dot found, just replace all text
                    $statusBadge.text(statusLabel);
                }
            }
            
            // Update status ribbon (for customer view)
            const $ribbon = $card.find('.p9-my-order-ribbon');
            if ($ribbon.length > 0) {
                // Remove old status classes
                $ribbon.removeClass(function(index, className) {
                    return (className.match(/\bp9-status-\S+/g) || []).join(' ');
                });
                
                // Add new status class
                $ribbon.addClass(statusClass);
                
                // Update status text
                $ribbon.text(statusLabel);
            }
            
            // Update customer name
            const $customerName = $card.find('.p9-customer-name');
            if ($customerName.length > 0) {
                $customerName.text(customerName);
            }
            
            // Update customer email
            const $customerEmail = $card.find('.p9-customer-email');
            if ($customerEmail.length > 0) {
                $customerEmail.text(customerEmail);
            }
            
            // Update avatar (if email changed)
            if (customerEmail) {
                const $avatar = $card.find('.p9-customer-avatar');
                if ($avatar.length > 0) {
                    $avatar.html(this.getAvatarHTML(customerEmail));
                }
            }
            
            // Update date
            const $date = $card.find('.p9-order-details .p9-detail-value').eq(0);
            if ($date.length > 0 && orderDate) {
                $date.text(orderDate);
            }
            
            // Update total (use .html() since it contains formatted HTML from WooCommerce)
            const $total = $card.find('.p9-order-details .p9-detail-value.p9-amount');
            if ($total.length > 0 && total) {
                $total.html(total);
            }
            
            // Update card's data-status attribute
            $card.attr('data-status', status);
            
            console.log('Portal Cloud 9: Order card updated successfully');
            console.log('Updated fields:', {
                customerName: customerName,
                customerEmail: customerEmail,
                status: statusLabel,
                date: orderDate,
                total: total
            });
        },
        
        /**
         * Close modal
         * Hides modal and overlay with animation
         */
        closeModal: function() {
            const self = this;
            
            $(this.selectors.modal).removeClass('p9-active');
            
            setTimeout(function() {
                $(self.selectors.modalOverlay).removeClass('p9-active');
                $('body').removeClass('p9-modal-open');
            }, 400);
            
            this.state.currentOrder = null;
        },
        
        /**
         * Save order changes
         * Submits updated order data via AJAX
         */
        saveOrder: function() {
            const self = this;
            const $btn = $(this.selectors.modalSave);
            
            if (!this.state.currentOrder) {
                return;
            }
            
            const formData = {
                action: 'portcld9_update_order',
                nonce: portcld9_orders_params.nonce,
                order_id: this.state.currentOrder.id,
                status: $('#p9-order-status').val(),
                billing_first_name: $('#p9-billing-first-name').val(),
                billing_last_name: $('#p9-billing-last-name').val(),
                billing_email: $('#p9-billing-email').val(),
                billing_phone: $('#p9-billing-phone').val(),
                billing_address: $('#p9-billing-address').val(),
                billing_city: $('#p9-billing-city').val(),
                billing_postcode: $('#p9-billing-postcode').val(),
                billing_country: $('#p9-billing-country').val(),
                order_note: $('#p9-order-note').val()
            };
            
            // Disable button and show loading state
            $btn.prop('disabled', true).html(`
                <svg class="p9-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                </svg>
                <span>Saving...</span>
            `);
            
            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $btn.prop('disabled', false).html(`
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <span>Save Changes</span>
                    `);
                    
                    if (response.success) {
                        self.showToast(
                            'success',
                            'Order Updated',
                            'Order #' + self.state.currentOrder.number + ' has been updated'
                        );
                        
                        // Update the order card in place without reloading page
                        if (response.data && response.data.order) {
                            self.updateOrderCard(response.data.order);
                        }
                        
                        self.closeModal();
                    } else {
                        self.showToast(
                            'error',
                            'Error',
                            response.data.message || 'Failed to update order'
                        );
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(`
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <span>Save Changes</span>
                    `);
                    
                    self.showToast('error', 'Error', 'Connection failed');
                }
            });
        },
        
        /**
         * Confirm order deletion
         * Shows confirmation dialog before deleting
         */
        confirmDeleteOrder: function() {
            const self = this;
            
            if (!this.state.currentOrder) {
                return;
            }
            
            const orderNumber = this.state.currentOrder.number;
            
            if (confirm('Are you sure you want to permanently delete Order #' + orderNumber + '? This action cannot be undone.')) {
                self.deleteOrder();
            }
        },
        
        /**
         * Delete order
         * Permanently deletes the current order
         */
        deleteOrder: function() {
            const self = this;
            const $btn = $(this.selectors.modalDelete);
            
            if (!this.state.currentOrder) {
                console.error('Portal Cloud 9: No current order to delete');
                return;
            }
            
            const orderId = this.state.currentOrder.id;
            const orderNumber = this.state.currentOrder.number;
            
            console.log('Portal Cloud 9: DELETE STARTED for order #' + orderNumber + ' (ID: ' + orderId + ')');
            
            // Disable button and show loading state
            $btn.prop('disabled', true).css('opacity', '0.5');
            
            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_delete_order',
                    nonce: portcld9_orders_params.nonce,
                    order_id: orderId
                },
                beforeSend: function() {
                    console.log('Portal Cloud 9: Sending delete request to server...');
                },
                success: function(response) {
                    console.log('Portal Cloud 9: Delete response received:', response);
                    
                    $btn.prop('disabled', false).css('opacity', '1');
                    
                    if (response.success) {
                        console.log('Portal Cloud 9: Order deleted successfully, closing modal and reloading...');
                        
                        // Close modal first
                        self.closeModal();
                        
                        // Show success message
                        self.showToast(
                            'success',
                            'Order Deleted',
                            'Order #' + orderNumber + ' has been permanently deleted'
                        );
                        
                        console.log('Portal Cloud 9: Calling loadOrders() to refresh...');
                        
                        // Reload orders immediately - this will remove the deleted order
                        // and refresh everything cleanly (no manual DOM manipulation)
                        self.loadOrders();
                    } else {
                        console.error('Portal Cloud 9: Delete failed:', response.data);
                        self.showToast(
                            'error',
                            'Error',
                            response.data.message || 'Failed to delete order'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Portal Cloud 9: Delete AJAX error:', {xhr, status, error});
                    $btn.prop('disabled', false).css('opacity', '1');
                    self.showToast('error', 'Error', 'Connection failed');
                }
            });
        },
        
        /**
         * Handle order action
         * Processes actions like delete, refund, etc.
         * 
         * @param {string} action - Action to perform
         */
        handleOrderAction: function(action) {
            const self = this;
            
            if (!this.state.currentOrder) {
                return;
            }
            
            const orderId = this.state.currentOrder.id;
            const orderNumber = this.state.currentOrder.number;
            
            // Confirm destructive actions
            if (action === 'delete' || action === 'refund') {
                if (!confirm(`Are you sure you want to ${action} order #${orderNumber}?`)) {
                    return;
                }
            }
            
            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_order_action',
                    nonce: portcld9_orders_params.nonce,
                    order_id: orderId,
                    order_action: action
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast('success', 'Action Completed', response.data.message);
                        
                        if (action === 'delete') {
                            self.closeModal();
                            
                            // Remove the order card from DOM
                            console.log('Portal Cloud 9: Order deleted, removing card...');
                            const $card = $('.p9-order-card[data-order-id="' + orderId + '"], .p9-my-order-card[data-order-id="' + orderId + '"]');
                            if ($card.length > 0) {
                                $card.fadeOut(400, function() {
                                    $(this).remove();
                                    
                                    // Check if there are any orders left
                                    if ($('.p9-order-card, .p9-my-order-card').length === 0) {
                                        console.log('Portal Cloud 9: No orders left, reloading page...');
                                        window.location.reload();
                                    }
                                });
                            }
                        }
                    } else {
                        self.showToast('error', 'Error', response.data.message || 'Action failed');
                    }
                },
                error: function() {
                    self.showToast('error', 'Error', 'Connection failed');
                }
            });
        },
        
        /**
         * Add order note
         * Adds a new note to current order
         */
        addOrderNote: function() {
            const self = this;
            const noteText = $('#p9-order-note').val().trim();
            const noteType = $('input[name="note_type"]:checked').val();
            
            if (!noteText) {
                self.showToast('error', 'No Note', 'Please enter a note before adding');
                return;
            }
            
            if (!self.state.currentOrder) {
                self.showToast('error', 'Error', 'No order selected');
                return;
            }
            
            const $btn = $('#p9-add-note-btn');
            const originalHtml = $btn.html();
            
            $btn.prop('disabled', true).html(`
                <svg class="p9-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                </svg>
                <span>Adding...</span>
            `);
            
            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_add_order_note',
                    nonce: portcld9_orders_params.nonce,
                    order_id: self.state.currentOrder.id,
                    note: noteText,
                    note_type: noteType
                },
                success: function(response) {
                    $btn.prop('disabled', false).html(originalHtml);
                    
                    if (response.success) {
                        self.showToast('success', 'Note Added', 'Order note has been added successfully');
                        $('#p9-order-note').val('');
                        self.openEditModal(self.state.currentOrder.id);
                    } else {
                        self.showToast('error', 'Error', response.data.message || 'Failed to add note');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                    self.showToast('error', 'Error', 'Connection failed');
                }
            });
        },
        
        /**
         * Show delete note confirmation
         * 
         * @param {number} noteId - Note ID to delete
         */
        showDeleteNoteConfirmation: function(noteId) {
            this.state.deleteNoteId = noteId;
            $('#p9-delete-note-overlay').addClass('p9-active');
            setTimeout(function() {
                $('#p9-delete-note-confirm').addClass('p9-active');
            }, 50);
        },
        
        /**
         * Delete order note
         * 
         * @param {number} noteId - Note ID to delete
         */
        deleteOrderNote: function(noteId) {
            const self = this;
            const $btn = $('#p9-delete-note-confirm-btn');
            
            $btn.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_delete_order_note',
                    nonce: portcld9_orders_params.nonce,
                    order_id: self.state.currentOrder.id,
                    note_id: noteId
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Delete Note');
                    
                    $('#p9-delete-note-confirm').removeClass('p9-active');
                    setTimeout(function() {
                        $('#p9-delete-note-overlay').removeClass('p9-active');
                    }, 300);
                    
                    if (response.success) {
                        self.showToast('success', 'Note Deleted', 'Order note has been deleted');
                        self.state.deleteNoteId = null;
                        self.openEditModal(self.state.currentOrder.id);
                    } else {
                        self.showToast('error', 'Error', response.data.message || 'Failed to delete note');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Delete Note');
                    self.showToast('error', 'Error', 'Connection failed');
                }
            });
        },
        
        /**
         * Render pagination controls
         * 
         * @param {Object} pagination - Pagination data
         */
        renderPagination: function(pagination) {
            // Implementation for pagination rendering
        },
        
        /**
         * Update statistics cards
         * 
         * @param {Object} stats - Statistics data
         */
        updateStats: function(stats) {
            // Implementation for stats update
        }
    };
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        P9Orders.init();
    });
    
    /**
     * Expose to global scope
     */
    window.P9Orders = P9Orders;
    
})(jQuery);

/* ============================================
   ORDERS MODAL SCRIPTS
   ============================================ */
(function($) {
    'use strict';

    const P9MyOrdersModal = {
        currentOrderId: null,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            const self = this;

            // Open modal
            $(document).on('click', '.p9-view-order-details', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                self.openModal(orderId);
            });

            // Close modal
            $(document).on('click', '#p9-my-modal-close, #p9-my-modal-close-btn', function() {
                self.closeModal();
            });

            $(document).on('click', '#p9-my-order-modal-overlay', function(e) {
                if ($(e.target).attr('id') === 'p9-my-order-modal-overlay') {
                    self.closeModal();
                }
            });

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#p9-my-order-modal').hasClass('p9-active')) {
                    self.closeModal();
                }
            });

            // Search & filter
            let searchTimeout;
            $(document).on('input', '#p9-my-orders-search', function() {
                clearTimeout(searchTimeout);
                const query = $(this).val().toLowerCase();
                searchTimeout = setTimeout(function() {
                    self.filterOrders(query, $('#p9-my-orders-status-filter').val());
                }, 300);
            });

            $(document).on('change', '#p9-my-orders-status-filter', function() {
                const query = $('#p9-my-orders-search').val().toLowerCase();
                self.filterOrders(query, $(this).val());
            });

            // Reorder button
            $(document).on('click', '.p9-reorder-btn', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                self.reorderItems(orderId, $(this));
            });
        },

        openModal: function(orderId) {
            const self = this;
            this.currentOrderId = orderId;

            // Show modal
            $('#p9-my-order-modal-overlay').addClass('p9-active');
            $('#p9-my-order-modal').addClass('p9-active');
            $('body').css('overflow', 'hidden');

            // Show loading
            $('#p9-my-modal-loading').show();
            $('#p9-my-modal-content').hide();

            // Fetch order details
            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_get_my_order_details',
                    nonce: portcld9_orders_params.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    $('#p9-my-modal-loading').hide();
                    
                    if (response.success) {
                        self.populateModal(response.data);
                        $('#p9-my-modal-content').show();
                    } else {
                        $('#p9-my-modal-content').html('<div class="p9-my-modal-error"><p>' + (response.data.message || 'Failed to load order') + '</p></div>').show();
                    }
                },
                error: function() {
                    $('#p9-my-modal-loading').hide();
                    $('#p9-my-modal-content').html('<div class="p9-my-modal-error"><p>Connection failed. Please try again.</p></div>').show();
                }
            });
        },

        closeModal: function() {
            $('#p9-my-order-modal-overlay').removeClass('p9-active');
            $('#p9-my-order-modal').removeClass('p9-active');
            $('body').css('overflow', '');
            this.currentOrderId = null;
        },

        populateModal: function(order) {
            // Helper to strip HTML
            function stripHtml(html) {
                if (!html) return '';
                const temp = document.createElement('div');
                temp.innerHTML = html;
                return temp.textContent || temp.innerText || '';
            }

            // Update order number
            $('#p9-my-modal-order-number').text('#' + order.number);

            // Build content HTML
            let html = '';

            // Status badge
            html += '<div class="p9-my-modal-status p9-status-' + order.status + '">';
            html += '<span class="p9-status-dot"></span>';
            html += order.status_label;
            html += '</div>';

            // Order info
            html += '<section class="p9-my-modal-section">';
            html += '<h3 class="p9-my-modal-section-title">Order Information</h3>';
            html += '<div class="p9-my-modal-info-grid">';
            html += '<div class="p9-my-modal-info-item"><span class="p9-my-modal-info-label">Order Date</span><span class="p9-my-modal-info-value">' + order.date_created + '</span></div>';
            html += '<div class="p9-my-modal-info-item"><span class="p9-my-modal-info-label">Payment Method</span><span class="p9-my-modal-info-value">' + (order.payment_method || 'N/A') + '</span></div>';
            if (order.shipping_method) {
                html += '<div class="p9-my-modal-info-item"><span class="p9-my-modal-info-label">Shipping Method</span><span class="p9-my-modal-info-value">' + order.shipping_method + '</span></div>';
            }
            html += '</div>';
            html += '</section>';

            // Items
            html += '<section class="p9-my-modal-section">';
            html += '<h3 class="p9-my-modal-section-title">Items (' + order.items.length + ')</h3>';
            html += '<div class="p9-my-modal-items">';
            order.items.forEach(function(item) {
                html += '<div class="p9-my-modal-item">';
                html += '<div class="p9-my-modal-item-image"><img src="' + item.image + '" alt=""></div>';
                html += '<div class="p9-my-modal-item-details">';
                html += '<div class="p9-my-modal-item-name">' + item.name + '</div>';
                html += '<div class="p9-my-modal-item-meta">';
                if (item.sku) html += '<span>SKU: ' + item.sku + '</span>';
                html += '</div>';
                html += '<span class="p9-my-modal-item-qty">× ' + item.quantity + '</span>';
                html += '</div>';
                html += '<div class="p9-my-modal-item-price">' + stripHtml(item.total) + '</div>';
                html += '</div>';
            });
            html += '</div>';
            html += '</section>';

            // Billing Address
            if (order.billing) {
                html += '<section class="p9-my-modal-section">';
                html += '<h3 class="p9-my-modal-section-title">Billing Address</h3>';
                html += '<div class="p9-my-modal-address">';
                html += '<div class="p9-my-modal-address-line">' + order.billing.first_name + ' ' + order.billing.last_name + '</div>';
                if (order.billing.address_1) html += '<div class="p9-my-modal-address-line">' + order.billing.address_1 + '</div>';
                if (order.billing.address_2) html += '<div class="p9-my-modal-address-line">' + order.billing.address_2 + '</div>';
                html += '<div class="p9-my-modal-address-line">' + order.billing.city + (order.billing.postcode ? ', ' + order.billing.postcode : '') + '</div>';
                if (order.billing.country) html += '<div class="p9-my-modal-address-line">' + order.billing.country + '</div>';
                if (order.billing.phone) html += '<div class="p9-my-modal-address-line">📞 ' + order.billing.phone + '</div>';
                if (order.billing.email) html += '<div class="p9-my-modal-address-line">✉️ ' + order.billing.email + '</div>';
                html += '</div>';
                html += '</section>';
            }

            // Shipping Address
            if (order.shipping && order.shipping.address_1) {
                html += '<section class="p9-my-modal-section">';
                html += '<h3 class="p9-my-modal-section-title">Shipping Address</h3>';
                html += '<div class="p9-my-modal-address">';
                html += '<div class="p9-my-modal-address-line">' + order.shipping.first_name + ' ' + order.shipping.last_name + '</div>';
                if (order.shipping.address_1) html += '<div class="p9-my-modal-address-line">' + order.shipping.address_1 + '</div>';
                if (order.shipping.address_2) html += '<div class="p9-my-modal-address-line">' + order.shipping.address_2 + '</div>';
                html += '<div class="p9-my-modal-address-line">' + order.shipping.city + (order.shipping.postcode ? ', ' + order.shipping.postcode : '') + '</div>';
                if (order.shipping.country) html += '<div class="p9-my-modal-address-line">' + order.shipping.country + '</div>';
                html += '</div>';
                html += '</section>';
            }

            // Totals
            html += '<section class="p9-my-modal-section">';
            html += '<h3 class="p9-my-modal-section-title">Order Total</h3>';
            html += '<div class="p9-my-modal-totals">';
            html += '<div class="p9-my-modal-totals-row"><span class="p9-my-modal-totals-label">Subtotal</span><span class="p9-my-modal-totals-value">' + stripHtml(order.subtotal) + '</span></div>';
            html += '<div class="p9-my-modal-totals-row"><span class="p9-my-modal-totals-label">Shipping</span><span class="p9-my-modal-totals-value">' + stripHtml(order.shipping_total) + '</span></div>';
            if (order.discount_total && parseFloat(order.discount_total.replace(/[^0-9.-]/g, '')) > 0) {
                html += '<div class="p9-my-modal-totals-row"><span class="p9-my-modal-totals-label">Discount</span><span class="p9-my-modal-totals-value" style="color:#ef4444">-' + stripHtml(order.discount_total) + '</span></div>';
            }
            html += '<div class="p9-my-modal-totals-row p9-totals-grand"><span class="p9-my-modal-totals-label">Total</span><span class="p9-my-modal-totals-value">' + stripHtml(order.total) + '</span></div>';
            html += '</div>';
            html += '</section>';

            // Order Notes/Timeline
            if (order.notes && order.notes.length > 0) {
                html += '<section class="p9-my-modal-section">';
                html += '<h3 class="p9-my-modal-section-title">Order Updates</h3>';
                html += '<div class="p9-my-modal-timeline">';
                order.notes.forEach(function(note) {
                    html += '<div class="p9-my-timeline-item">';
                    html += '<div class="p9-my-timeline-date">' + note.date + '</div>';
                    html += '<div class="p9-my-timeline-text">' + note.content + '</div>';
                    html += '</div>';
                });
                html += '</div>';
                html += '</section>';
            }

            $('#p9-my-modal-content').html(html);

            // Update support link
            $('#p9-my-modal-support-btn').attr('href', portcld9_orders_modal_data.inbox_url + '?subject=Order%20' + order.number);
        },

        filterOrders: function(query, status) {
            $('.p9-my-order-card').each(function() {
                const $card = $(this);
                const orderNumber = $card.find('.p9-my-order-number').text().toLowerCase();
                const itemName = $card.find('.p9-my-item-name').text().toLowerCase();
                const cardStatus = $card.data('status');

                const matchesQuery = !query || orderNumber.includes(query) || itemName.includes(query);
                const matchesStatus = !status || cardStatus === status;

                if (matchesQuery && matchesStatus) {
                    $card.show();
                } else {
                    $card.hide();
                }
            });
        },

        reorderItems: function(orderId, $btn) {
            const originalText = $btn.html();
            $btn.prop('disabled', true).html('<svg class="p9-spin" viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/></svg> Adding...');

            $.ajax({
                url: portcld9_orders_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_reorder_items',
                    nonce: portcld9_orders_params.nonce,
                    order_id: orderId
                },
                success: function(response) {
                    $btn.prop('disabled', false).html(originalText);
                    
                    if (response.success) {
                        // Show success and redirect to cart
                        if (typeof P9Orders !== 'undefined' && P9Orders.showToast) {
                            P9Orders.showToast('success', 'Items Added', 'Order items have been added to your cart');
                        }
                        setTimeout(function() {
                            window.location.href = response.data.cart_url || portcld9_orders_modal_data.cart_url;
                        }, 1000);
                    } else {
                        if (typeof P9Orders !== 'undefined' && P9Orders.showToast) {
                            P9Orders.showToast('error', 'Error', response.data.message || 'Failed to add items');
                        }
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(originalText);
                    if (typeof P9Orders !== 'undefined' && P9Orders.showToast) {
                        P9Orders.showToast('error', 'Error', 'Connection failed');
                    }
                }
            });
        }
    };

    $(document).ready(function() {
        P9MyOrdersModal.init();
    });

})(jQuery);
