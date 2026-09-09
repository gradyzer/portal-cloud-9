/**
 * ============================================================================
 * Portal Cloud 9 - Phone Contacts and Call Tracking
 * ============================================================================
 *
 * Handles seller phone number display and call tracking including:
 * - Phone number click event capture and AJAX logging
 * - Reveal phone number for logged-in users
 * - Call history pagination and rendering
 * - Message seller inline form submission
 * - Stats refresh after new call events
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
    
    // ─────────────────────────────────────────────────────────────────────
    // SECTION 1: Phone number click capture and AJAX logging
    // Fires when a customer clicks a seller phone number on a product page.
    // Phone number click tracking
    $(document).on('click', '.pc9-phone-number', function(e) {
        const $link = $(this);
        const productId = $link.data('product-id');
        const phoneNumber = $link.data('phone');
        
        console.log('📞 Phone link clicked!');
        console.log('Product ID:', productId);
        console.log('Phone Number:', phoneNumber);
        console.log('Ajax URL:', portalcloud9_ajax?.ajax_url);
        console.log('Nonce:', portalcloud9_ajax?.nonce);
        
        if (!productId || !phoneNumber) {
            console.error('❌ Missing product ID or phone number');
            return;
        }
        
        if (typeof portalcloud9_ajax === 'undefined') {
            console.error('❌ portalcloud9_ajax object not defined');
            return;
        }
        
        $.ajax({
            url: portalcloud9_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'portcld9_track_phone_click',
                nonce: portalcloud9_ajax.nonce,
                product_id: productId,
                phone_number: phoneNumber
            },
            success: function(response) {
                console.log('📡 AJAX Response:', response);
                
                if (response.success && response.data.tracked) {
                    console.log('✅ Phone click tracked successfully:', response.data);
                } else if (response.success && !response.data.tracked) {
                    console.log('ℹ️ Click not tracked:', response.data.message);
                } else {
                    console.error('❌ Tracking failed:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', { xhr, status, error });
            }
        });
    });
    
    // ─────────────────────────────────────────────────────────────────────
    // SECTION 2: Phone contacts manager object (admin/seller dashboard tab)
    // Phone contacts management
    window.PC9PhoneContacts = {
        currentPage: 1,
        currentSellerId: null,
        currentProductId: null,
        
        // Initialise the manager: load stats, contacts and bind events
        init: function() {
            this.loadStats();
            this.loadContacts();
            this.bindEvents();
        },
        
        // ── Event bindings ────────────────────────────────────────────────
        bindEvents: function() {
            const self = this;
            
            // Search functionality
            $('#pc9-contact-search').on('input', $.debounce(500, function() {
                self.currentPage = 1;
                self.loadContacts();
            }));
            
            // Filter button
            $('#pc9-filter-btn').on('click', function() {
                self.currentPage = 1;
                self.loadContacts();
            });
            
            // Reset filters
            $('#pc9-reset-filter-btn').on('click', function() {
                $('#pc9-contact-search').val('');
                $('#pc9-date-from').val('');
                $('#pc9-date-to').val('');
                self.currentPage = 1;
                self.loadContacts();
            });
            
            // Message seller button
            $(document).on('click', '.pc9-message-seller-btn', function() {
                const sellerId = $(this).data('seller-id');
                const productId = $(this).data('product-id');
                const sellerName = $(this).data('seller-name');
                const productName = $(this).data('product-name');
                
                self.openMessageModal(sellerId, productId, sellerName, productName);
            });
            
            // Send message button
            $('#pc9-send-message-btn').on('click', function() {
                self.sendMessage();
            });
            
            // View details button
            $(document).on('click', '.pc9-view-details-btn', function() {
                const contactData = $(this).data('contact');
                self.showDetails(contactData);
            });
            
            // Modal close handlers
            $('.pc9-modal-close, .pc9-modal-cancel').on('click', function() {
                $(this).closest('.pc9-modal').fadeOut(200);
            });
            
            $('.pc9-modal').on('click', function(e) {
                if ($(e.target).hasClass('pc9-modal')) {
                    $(this).fadeOut(200);
                }
            });
            
            // Pagination
            $(document).on('click', '.pc9-page-btn', function() {
                const page = $(this).data('page');
                if (page) {
                    self.currentPage = page;
                    self.loadContacts();
                }
            });
        },
        
        // ── Stats loader ──────────────────────────────────────────────────
        // Fetches summary stat cards and top products/sellers via AJAX.
        loadStats: function() {
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'portcld9_get_contact_stats',
                    nonce: portalcloud9_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        // Update stats
                        $('#pc9-stat-total').text(data.total_clicks);
                        $('#pc9-stat-today').text(data.today_clicks);
                        $('#pc9-stat-week').text(data.week_clicks);
                        $('#pc9-stat-month').text(data.month_clicks);
                        $('#pc9-stat-users').text(data.unique_users);
                        $('#pc9-stat-products').text(data.unique_products);
                        
                        // Add animation class
                        $('.pc9-stat-value').each(function() {
                            $(this).addClass('pc9-stat-animate');
                        });
                        
                        // Update top products
                        const $topProducts = $('#pc9-top-products');
                        if (data.top_products && data.top_products.length) {
                            let html = '<div class="pc9-top-list-items">';
                            
                            data.top_products.forEach((item, index) => {
                                html += `
                                    <div class="pc9-top-item">
                                        <div class="pc9-top-rank">${index + 1}</div>
                                        <div class="pc9-top-info">
                                            <div class="pc9-top-name">${item.product_name || 'Unknown Product'}</div>
                                            <div class="pc9-top-count">${item.click_count} clicks</div>
                                        </div>
                                    </div>
                                `;
                            });
                            
                            html += '</div>';
                            $topProducts.html(html);
                        } else {
                            $topProducts.html('<div class="pc9-empty-state"><p>No data yet</p></div>');
                        }
                        
                        // Update top sellers
                        const $topSellers = $('#pc9-top-sellers');
                        if (data.top_sellers && data.top_sellers.length) {
                            let html = '<div class="pc9-top-list-items">';
                            
                            data.top_sellers.forEach((item, index) => {
                                html += `
                                    <div class="pc9-top-item">
                                        <div class="pc9-top-rank">${index + 1}</div>
                                        <div class="pc9-top-info">
                                            <div class="pc9-top-name">${item.seller_name || 'Unknown Seller'}</div>
                                            <div class="pc9-top-count">${item.click_count} clicks</div>
                                        </div>
                                    </div>
                                `;
                            });
                            
                            html += '</div>';
                            $topSellers.html(html);
                        } else {
                            $topSellers.html('<div class="pc9-empty-state"><p>No data yet</p></div>');
                        }
                    }
                }
            });
        },
        
        // ── Contacts list loader ───────────────────────────────────────────
        // Fetches the paginated contact history table with active filters.
        loadContacts: function() {
            const self = this;
            const search = $('#pc9-contact-search').val();
            const dateFrom = $('#pc9-date-from').val();
            const dateTo = $('#pc9-date-to').val();
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'portcld9_get_phone_contacts',
                    nonce: portalcloud9_ajax.nonce,
                    page: this.currentPage,
                    search: search,
                    date_from: dateFrom,
                    date_to: dateTo
                },
                beforeSend: function() {
                    $('#pc9-contacts-tbody').html(`
                        <tr>
                            <td colspan="6" class="pc9-loading-row">
                                <div class="pc9-loader"></div>
                                <span>Loading contacts...</span>
                            </td>
                        </tr>
                    `);
                },
                success: function(response) {
                    if (response.success) {
                        self.renderContacts(response.data.contacts);
                        self.renderPagination(response.data);
                    } else {
                        $('#pc9-contacts-tbody').html(`
                            <tr>
                                <td colspan="6" class="pc9-error-row">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Error loading contacts</span>
                                </td>
                            </tr>
                        `);
                    }
                },
                error: function() {
                    $('#pc9-contacts-tbody').html(`
                        <tr>
                            <td colspan="6" class="pc9-error-row">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>Failed to load contacts</span>
                            </td>
                        </tr>
                    `);
                }
            });
        },
        
        // ── Contact row renderer ───────────────────────────────────────────
        // Builds and injects the contact history table rows from response data.
        renderContacts: function(contacts) {
            const $tbody = $('#pc9-contacts-tbody');
            
            if (!contacts || contacts.length === 0) {
                $tbody.html(`
                    <tr>
                        <td colspan="6" class="pc9-empty-state">
                            <i class="fas fa-phone-slash"></i>
                            <p>No phone contacts found</p>
                            <p>When users click phone numbers on products, they'll appear here.</p>
                        </td>
                    </tr>
                `);
                return;
            }
            
            let html = '';
            
            contacts.forEach(contact => {
                html += `
                    <tr class="pc9-contact-row">
                        <td>
                            <div class="pc9-contact-date">
                                <i class="fas fa-clock"></i>
                                ${contact.formatted_date}
                            </div>
                        </td>
                        <td>
                            <div class="pc9-contact-user">
                                <strong>${contact.user_name}</strong>
                                <small>${contact.user_email}</small>
                            </div>
                        </td>
                        <td>
                            <div class="pc9-contact-seller">
                                <strong>${contact.seller_name}</strong>
                                <small>${contact.seller_email}</small>
                            </div>
                        </td>
                        <td>
                            <div class="pc9-contact-product">
                                <a href="${contact.product_view_url}" target="_blank">
                                    ${contact.product_name}
                                </a>
                            </div>
                        </td>
                        <td>
                            <div class="pc9-contact-phone">
                                <i class="fas fa-phone"></i>
                                <a href="tel:${contact.phone_number}">${contact.phone_number}</a>
                            </div>
                        </td>
                        <td>
                            <div class="pc9-contact-actions">
                                <button type="button" 
                                        class="pc9-btn pc9-btn-sm pc9-btn-primary pc9-message-seller-btn"
                                        data-seller-id="${contact.seller_id}"
                                        data-product-id="${contact.product_id}"
                                        data-seller-name="${contact.seller_name}"
                                        data-product-name="${contact.product_name}">
                                    <i class="fas fa-comment"></i> Message
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            $tbody.html(html);
        },
        
        // ── Pagination renderer ────────────────────────────────────────────
        // Builds previous, page number and next pagination buttons.
        renderPagination: function(data) {
            const $pagination = $('#pc9-contacts-pagination');
            
            if (data.total_pages <= 1) {
                $pagination.hide();
                return;
            }
            
            let html = '<div class="pc9-pagination-inner">';
            
            // Previous button
            if (data.page > 1) {
                html += `
                    <button class="pc9-page-btn" data-page="${data.page - 1}">
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                `;
            }
            
            // Page numbers
            html += '<div class="pc9-page-numbers">';
            
            for (let i = 1; i <= data.total_pages; i++) {
                if (i === 1 || i === data.total_pages || (i >= data.page - 2 && i <= data.page + 2)) {
                    const activeClass = i === data.page ? 'active' : '';
                    html += `<button class="pc9-page-btn ${activeClass}" data-page="${i}">${i}</button>`;
                } else if (i === data.page - 3 || i === data.page + 3) {
                    html += '<span class="pc9-page-ellipsis">...</span>';
                }
            }
            
            html += '</div>';
            
            // Next button
            if (data.page < data.total_pages) {
                html += `
                    <button class="pc9-page-btn" data-page="${data.page + 1}">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                `;
            }
            
            html += '</div>';
            $pagination.html(html).show();
        },
        
        // ── Message modal ─────────────────────────────────────────────────
        // Opens the modal to compose a message to the seller about this inquiry.
        openMessageModal: function(sellerId, productId, sellerName, productName) {
            this.currentSellerId = sellerId;
            this.currentProductId = productId;
            
            $('#pc9-msg-seller-name').text(sellerName);
            $('#pc9-msg-product-name').text(productName);
            $('#pc9-message-text').val('');
            
            $('#pc9-message-modal').css('display', 'flex').hide().fadeIn(200);
        },
        
        // Submits the message form via AJAX and shows success/error feedback.
        sendMessage: function() {
            const message = $('#pc9-message-text').val().trim();
            
            if (!message) {
                alert('Please enter a message');
                return;
            }
            
            const $btn = $('#pc9-send-message-btn');
            const originalText = $btn.html();
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'portcld9_send_contact_message',
                    nonce: portalcloud9_ajax.nonce,
                    seller_id: this.currentSellerId,
                    product_id: this.currentProductId,
                    message: message
                },
                beforeSend: function() {
                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
                },
                success: function(response) {
                    if (response.success) {
                        $('#pc9-message-text').val('');
                        alert('Message sent successfully! The seller will see it in their inbox.');
                        $('#pc9-message-modal').fadeOut(200);
                    } else {
                        alert('Failed to send message: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', { xhr, status, error, response: xhr.responseText });
                    alert('Error sending message. Please try again. Check console for details.');
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalText);
                }
            });
        },
        
        // Opens a detail view for a specific contact record.
        showDetails: function(contact) {
            console.log('Show details for:', contact);
            // Implement details view if needed
        }
    };
    
    // ─────────────────────────────────────────────────────────────────────
    // Polyfill: add $.debounce to jQuery if the debounce plugin is not loaded
    // Add debounce if not available
    $.debounce = function(delay, fn) {
        let timer = null;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() {
                fn.apply(context, args);
            }, delay);
        };
    };
})(jQuery);
/* ============================================
   PHONE CONTACTS INIT
   ============================================ */
// Initialize on page load
jQuery(document).ready(function($) {
    if (typeof PC9PhoneContacts !== 'undefined') {
        PC9PhoneContacts.init();
    }
});
