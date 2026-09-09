/**
 * ============================================================================
 * Portal Cloud 9 - Dashboard Controller
 * ============================================================================
 *
 * Core dashboard JavaScript controller including:
 * - Tab navigation and active state management
 * - Dashboard summary stats and widget initialisation
 * - Responsive sidebar and mobile menu behaviour
 * - AJAX-powered content section loading
 * - Notification badge updates
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

jQuery(document).ready(function($) {
    'use strict';
    
    // ── Main dashboard object ─────────────────────────────────────────────────
    window.PortalCloud9Dashboard = {
        // Initialise all sub-modules and bind global events
        init: function() {
            this.bindEvents();
            this.initMobileMenu();
            this.updateNotificationCounts();
            this.startPeriodicUpdates();
            this.loadTabSpecificJS();
            this.initSidebarCollapse();
        },
        
        // ── Global event bindings ─────────────────────────────────────────────
        bindEvents: function() {
            $(document).on('click', '#p9-mobile-toggle', this.toggleMobileMenu);
            
            $(document).on('click', '.p9-modal-close, .p9-modal', function(e) {
                if (e.target === this) {
                    PortalCloud9Dashboard.closeModal();
                }
            });
            
            $(document).on('click', '.p9-btn[data-loading]', this.handleLoadingButton);
            $(document).on('input', 'textarea[data-auto-resize]', this.autoResizeTextarea);
            $(document).on('click', '[data-clipboard]', this.copyToClipboard);
        },
        
        // ── Mobile menu toggle ───────────────────────────────────────────────
        toggleMobileMenu: function(e) {
            e.preventDefault();
            $('.p9-sidebar').toggleClass('open');
        },
        
        // Close sidebar when clicking outside it or on a nav link
        initMobileMenu: function() {
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.p9-sidebar, #p9-mobile-toggle').length) {
                    $('.p9-sidebar').removeClass('open');
                }
            });
            
            $('.p9-nav-link').on('click', function() {
                $('.p9-sidebar').removeClass('open');
            });
        },
        
        // ── Notification badge update ─────────────────────────────────────────
        updateNotificationCounts: function() {
            if (typeof portalcloud9_ajax === 'undefined') return;
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'portcld9_get_unread_count',
                    nonce: portalcloud9_ajax.nonce
                },
                success: function(res) {
                    if (!res.success) return;
                    
                    const count = res.data.count || 0;
                    const $sidebarBadge = $('#p9-inbox-counter');
                    $sidebarBadge.find('.p9-counter-badge').text(count);
                    $sidebarBadge.toggle(count > 0);
                    
                    const $topbarBadge = $('#p9-message-bubble');
                    $topbarBadge.find('.p9-bubble-count').text(count);
                    $topbarBadge.toggle(count > 0);
                    
                    $(document).trigger('p9:notification-count-updated', [count]);
                }
            });
        },
        
        // Refresh notification counts every 30 seconds
        startPeriodicUpdates: function() {
            setInterval(function() {
                PortalCloud9Dashboard.updateNotificationCounts();
            }, 30000);
        },
        
        // ── Dynamic tab JS loader ─────────────────────────────────────────────
        loadTabSpecificJS: function() {
            const tab = this.getCurrentTab();
            const files = {
                products: 'products.js',
                'add-product': 'add-product.js',
                inbox: 'inbox.js',
                account: 'account.js'
            };
            
            if (files[tab]) {
                this.loadScript(portalcloud9_ajax.plugin_url + 'assets/js/' + files[tab]);
            }
        },
        
        // Parse the current tab name from the URL pathname
        getCurrentTab: function() {
            const match = window.location.pathname.match(/\/user-portal\/([^\/]+)/);
            return match ? match[1] : 'overview';
        },
        
        // Dynamically append a script tag, skipping if already loaded
        loadScript: function(src) {
            if ($('script[src="' + src + '"]').length) return;
            
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            document.head.appendChild(script);
        },
        
        // ── Collapsible sidebar ───────────────────────────────────────────────
        initSidebarCollapse: function() {
            const $sidebar = $('.p9-sidebar-desktop');
            const $mainContent = $('.p9-main-content-desktop');
            const $toggleBtn = $('#p9-collapse-menu');
            
            if (!$toggleBtn.length) return;
            
            // Check saved state
            if (localStorage.getItem('p9-menu-collapsed') === '1') {
                $sidebar.addClass('collapsed');
                $mainContent.addClass('expanded');
                $toggleBtn.find('.collapse-icon').text('▶');
            }
            
            $toggleBtn.on('click', function() {
                const collapsed = $sidebar.toggleClass('collapsed').hasClass('collapsed');
                $mainContent.toggleClass('expanded', collapsed);
                $(this).find('.collapse-icon').text(collapsed ? '▶' : '◀');
                
                localStorage.setItem('p9-menu-collapsed', collapsed ? '1' : '0');
            });
        },
        // ── Modal helpers ─────────────────────────────────────────────────────
        showModal: function(content, opts) {
            opts = $.extend({
                title: '',
                closable: true,
                className: '',
                width: 'auto',
                onOpen: null,
                onClose: null
            }, opts);
            
            let html = '<div class="p9-modal ' + opts.className + '">' +
                          '<div class="p9-modal-content" style="' + 
                          (opts.width !== 'auto' ? 'width:' + opts.width : '') + '">';
            
            if (opts.title) {
                html += '<div class="p9-modal-header">' +
                           '<h3>' + opts.title + '</h3>';
                
                if (opts.closable) {
                    html += '<button class="p9-modal-close">&times;</button>';
                }
                
                html += '</div>';
            }
            
            html += '<div class="p9-modal-body">' + content + '</div>' +
                    '</div>' +
                    '</div>';
            
            const $modal = $(html).appendTo('body');
            $modal.fadeIn(200);
            
            $(document).trigger('p9:modal-opened', [$modal]);
            
            if (opts.onOpen) {
                opts.onOpen($modal);
            }
            
            $modal.data('onClose', opts.onClose);
            return $modal;
        },
        
        // Fade out and remove the visible modal from the DOM
        closeModal: function() {
            const $modal = $('.p9-modal:visible');
            if (!$modal.length) return;
            
            const callback = $modal.data('onClose');
            
            $modal.fadeOut(200, function() {
                $modal.remove();
                if (callback) callback();
            });
        },
        
        // ── Toast notification system ─────────────────────────────────────────
        showNotification: function(msg, type, duration) {
            type = type || 'info';
            duration = duration || 5000;
            
            const icons = {
                success: '✅',
                error: '❌',
                warning: '⚠️',
                info: 'ℹ️'
            };
            
            const $notification = $(
                '<div class="p9-notification-toast p9-notification-' + type + '">' +
                    '<span class="p9-notification-icon">' + (icons[type] || icons.info) + '</span>' +
                    '<span class="p9-notification-content">' + msg + '</span>' +
                    '<button class="p9-notification-close">&times;</button>' +
                '</div>'
            );
            
            let $container = $('.p9-notifications-container');
            if (!$container.length) {
                $container = $('<div class="p9-notifications-container"></div>').appendTo('body');
                this.injectNotificationStyles();
            }
            
            $container.append($notification);
            $notification.slideDown(200);
            
            setTimeout(function() {
                $notification.slideUp(200, function() {
                    $(this).remove();
                });
            }, duration);
            
            $notification.find('.p9-notification-close').on('click', function() {
                $notification.slideUp(200, function() {
                    $(this).remove();
                });
            });
        },
        
        injectNotificationStyles: function() {
            if ($('#p9-notification-styles').length) return;
            
            const css = `
                <style id="p9-notification-styles">
                .p9-notifications-container {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 10001;
                    max-width: 400px;
                }
                
                .p9-notification-toast {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 16px 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    animation: slideInRight 0.3s ease;
                }
                
                .p9-notification-toast.p9-success {
                    border-left: 4px solid #10b981;
                }
                
                .p9-notification-toast.p9-error {
                    border-left: 4px solid #dc2626;
                }
                
                .p9-notification-toast.p9-warning {
                    border-left: 4px solid #f59e0b;
                }
                
                .p9-notification-toast.p9-info {
                    border-left: 4px solid #3b82f6;
                }
                
                .p9-notification-icon {
                    font-size: 18px;
                    flex-shrink: 0;
                }
                
                .p9-notification-content {
                    flex: 1;
                    font-weight: 500;
                }
                
                .p9-notification-close {
                    background: none;
                    border: none;
                    font-size: 20px;
                    cursor: pointer;
                    opacity: 0.7;
                    flex-shrink: 0;
                    margin-left: auto;
                }
                
                .p9-notification-close:hover {
                    opacity: 1;
                }
                
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                
                @media (max-width: 768px) {
                    .p9-notifications-container {
                        left: 20px;
                        right: 20px;
                        max-width: none;
                    }
                }
                </style>`;
            
            $('head').append(css);
        },
        
        // ── Confirmation dialog ───────────────────────────────────────────────
        confirmAction: function(msg, callback, opts) {
            opts = $.extend({
                title: 'Confirm Action',
                confirmText: 'Confirm',
                cancelText: 'Cancel',
                confirmClass: 'p9-btn-danger'
            }, opts);
            
            const content = 
                '<div class="p9-confirm-dialog">' +
                    '<p>' + msg + '</p>' +
                    '<div class="p9-confirm-actions">' +
                        '<button class="p9-btn p9-btn-outline p9-confirm-cancel">' + opts.cancelText + '</button>' +
                        '<button class="p9-btn ' + opts.confirmClass + ' p9-confirm-ok">' + opts.confirmText + '</button>' +
                    '</div>' +
                '</div>';
            
            const $modal = this.showModal(content, {
                title: opts.title,
                className: 'p9-confirm-modal',
                width: '400px'
            });
            
            $modal.find('.p9-confirm-cancel').on('click', () => this.closeModal());
            $modal.find('.p9-confirm-ok').on('click', () => {
                this.closeModal();
                if (callback) callback();
            });
        },
        
        // ── Utility: loading button state ────────────────────────────────────
        handleLoadingButton: function(e) {
            const $btn = $(this);
            const originalText = $btn.text();
            $btn
                .prop('disabled', true)
                .html('<div class="p9-btn-spinner"></div> Loading...')
                .data('original-text', originalText);
        },
        
        // Restore a loading button to its original text and enabled state
        restoreButton: function($btn) {
            $btn
                .prop('disabled', false)
                .text($btn.data('original-text') || 'Submit');
        },
        
        // ── Utility: auto-resize textarea ─────────────────────────────────────
        autoResizeTextarea: function() {
            const $textarea = $(this);
            $textarea.css('height', 'auto');
            $textarea.css('height', $textarea.prop('scrollHeight') + 'px');
        },
        
        // ── Utility: clipboard copy ───────────────────────────────────────────
        copyToClipboard: function(e) {
            e.preventDefault();
            const text = $(this).data('clipboard');
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    PortalCloud9Dashboard.showNotification('Copied to clipboard!', 'success');
                });
            } else {
                // Fallback for older browsers
                const $textarea = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $textarea.remove();
                PortalCloud9Dashboard.showNotification('Copied to clipboard!', 'success');
            }
        },
        
        // ── Utility: format currency amount ───────────────────────────────────
        formatCurrency: function(amount, currency) {
            currency = currency || '$';
            return currency + parseFloat(amount).toFixed(2);
        },
        
        // ── Utility: format date string ───────────────────────────────────────
        formatDate: function(date, format) {
            if (typeof date === 'string') {
                date = new Date(date);
            }
            
            format = format || 'MMM DD, YYYY';
            
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                           'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            
            const day = date.getDate().toString().padStart(2, '0');
            const month = months[date.getMonth()];
            const year = date.getFullYear();
            
            return format
                .replace('MMM', month)
                .replace('DD', day)
                .replace('YYYY', year);
        },
        
        // ── Utility: debounce function calls ─────────────────────────────────
        debounce: function(func, wait) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        },
        
        // ── AJAX wrapper with nonce injection and loading indicator ──────────
        ajaxRequest: function(opts) {
            const defaults = {
                type: 'POST',
                dataType: 'json',
                beforeSend: () => {
                    if (opts.showLoading !== false) {
                        PortalCloud9Dashboard.showLoading();
                    }
                },
                complete: () => {
                    if (opts.showLoading !== false) {
                        PortalCloud9Dashboard.hideLoading();
                    }
                },
                error: (xhr, status, err) => {
                    console.error('AJAX Error:', err);
                    PortalCloud9Dashboard.showNotification('An error occurred. Please try again.', 'error');
                    if (opts.error) opts.error(xhr, status, err);
                }
            };
            
            // Add nonce if available
            if (typeof portalcloud9_ajax !== 'undefined') {
                opts.data = opts.data || {};
                if (typeof opts.data === 'object') {
                    opts.data.nonce = portalcloud9_ajax.nonce;
                }
            }
            
            return $.ajax($.extend(defaults, opts));
        },
        
        // ── Loading overlay ───────────────────────────────────────────────────
        showLoading: function(msg) {
            msg = msg || 'Loading...';
            let $loading = $('#p9-loading');
            
            if (!$loading.length) {
                $loading = $(
                    '<div id="p9-loading" class="p9-loading-overlay">' +
                        '<div class="p9-loading-spinner">' +
                            '<div class="p9-spinner"></div>' +
                            '<p>' + msg + '</p>' +
                        '</div>' +
                    '</div>'
                ).appendTo('body');
            } else {
                $loading.find('p').text(msg);
            }
            
            $loading.fadeIn(200);
        },
        
        // Remove the loading overlay
        hideLoading: function() {
            $('#p9-loading').fadeOut(200);
        }
    };
    
    // Kick off the dashboard on DOM ready
    PortalCloud9Dashboard.init();
});
/* ============================================
   MOBILE DASHBOARD INIT
   ============================================ */
document.addEventListener('DOMContentLoaded', function () {
    // Add body class for admin bar handling
    document.body.classList.add('portalcloud9-dashboard-wrapper');
    
    const body = document.body;
    const hamburger = document.getElementById('portalcloud9-mobile-toggle');
    const closeBtn  = document.getElementById('portalcloud9-mobile-close');
    const sidebar   = document.getElementById('portalcloud9-mobile-sidebar');
    const overlay   = document.getElementById('portalcloud9-mobile-overlay');

    // Mobile menu (only when elements exist — not on desktop layout)
    if (hamburger && closeBtn && sidebar && overlay) {
        function openMenu() {
            sidebar.classList.add('open');
            overlay.classList.add('open');
            hamburger.classList.add('open');
        }
        function closeMenu() {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
            hamburger.classList.remove('open');
        }
        hamburger.addEventListener('click', openMenu);
        closeBtn.addEventListener('click', closeMenu);
        overlay.addEventListener('click', closeMenu);
    }
});

/* ============================================
   TAB NOTICE INIT
   ============================================ */
(function () {
    var notice = document.getElementById('portalcloud9-tab-notice');
    if (!notice) return;

    // Auto-dismiss after 6 seconds
    var timer = setTimeout(dismiss, 6000);

    notice.querySelector('.portalcloud9-tab-notice__close')
        .addEventListener('click', function () {
            clearTimeout(timer);
            dismiss();
        });

    function dismiss() {
        notice.style.animation = 'p9NoticeOut .3s cubic-bezier(.4,0,.2,1) forwards';
        setTimeout(function () { notice.remove(); }, 320);
    }
})();

/* ============================================
   OVERVIEW TAB INIT (LIVE CLOCK + ONLINE POLL)
   ============================================ */
(function () {
    'use strict';

    /* ---- Live clock (admin only) ---- */
    var clock = document.getElementById('p9-hero-clock');
    if (clock) {
        function tick() {
            var now    = new Date();
            var raw    = now.getHours();
            var ampm   = raw >= 12 ? 'PM' : 'AM';
            var h      = raw % 12 || 12; // convert 0 → 12 for midnight
            var hh     = ('0' + h).slice(-2);
            var m      = ('0' + now.getMinutes()).slice(-2);
            var s      = ('0' + now.getSeconds()).slice(-2);
            clock.textContent = hh + ':' + m + ':' + s + ' ' + ampm;
        }
        tick();
        setInterval(tick, 1000);
    }

    /* ---- Online-now real-time poll (admin only) ---- */
    var onlineEl     = document.getElementById('p9ov-online');
    var ajaxData     = (typeof portalcloud9_ajax !== 'undefined') ? portalcloud9_ajax : null;
    var visitorNonce = (typeof portcld9_overview_data !== 'undefined' && portcld9_overview_data.visitor_nonce) ? portcld9_overview_data.visitor_nonce : '';
    var prevCount    = null;
    var onlineTimer  = null;

    if (onlineEl && ajaxData && visitorNonce) {

        var pollOnline = function () {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxData.ajax_url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        var n = parseInt(res.data.online, 10) || 0;
                        if (prevCount !== null && prevCount !== n) {
                            onlineEl.style.transition = 'transform .15s, color .15s';
                            onlineEl.style.transform  = 'scale(1.3)';
                            setTimeout(function () { onlineEl.style.transform = 'scale(1)'; }, 200);
                        }
                        prevCount = n;
                        onlineEl.textContent = n;
                    }
                } catch (e) {}
            };
            xhr.send('action=portcld9_visitor_online&nonce=' + encodeURIComponent(visitorNonce));
        };

        var startOnlinePoll = function () {
            if (onlineTimer) { return; }
            onlineTimer = setInterval(pollOnline, 10000);
        };

        var stopOnlinePoll = function () {
            if (onlineTimer) { clearInterval(onlineTimer); onlineTimer = null; }
        };

        // Pause when tab is hidden, resume + immediately re-poll when visible again
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopOnlinePoll();
            } else {
                pollOnline();       // instant update the moment admin returns to tab
                startOnlinePoll();
            }
        });

        pollOnline();       // immediate poll on page load
        startOnlinePoll();
    }

    /* ---- Recent-order rows: open order in Orders tab ---- */
    document.addEventListener('click', function (e) {
        var row = e.target.closest('.p9-recent-order-row');
        if (!row) { return; }
        e.preventDefault();
        var ordersUrl = row.href;
        var orderId   = row.dataset.orderId;
        if (location.pathname.includes('/orders/') && typeof P9Orders !== 'undefined' && orderId) {
            P9Orders.openEditModal(orderId);
            return;
        }
        location.href = ordersUrl;
    });

}());

/* ============================================
   NOTIFICATION COUNTER UPDATE
   ============================================ */
        function updatePortalCloud9NotificationCounts(){
            if (typeof portalcloud9_ajax === 'undefined') return;
            jQuery.ajax({
                url: portalcloud9_ajax.ajax_url,
                method: 'POST',
                data: { action: 'portalcloud9_get_unread_count', nonce: portalcloud9_ajax.nonce },
                success: function(res){
                    if (!res.success) return;
                    var count = res.data.count || 0;
                    jQuery('.portalcloud9-counter-badge, .portalcloud9-bubble-count').text(count);
                    jQuery('#inbox-counter, #portalcloud9-message-bubble').toggle(count > 0);
                }
            });
        }
        jQuery(document).ready(function(){ updatePortalCloud9NotificationCounts(); setInterval(updatePortalCloud9NotificationCounts, 30000); });
