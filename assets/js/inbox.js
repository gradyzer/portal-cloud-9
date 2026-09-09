/**
 * ============================================================================
 * Portal Cloud 9 - Inbox and Messaging System
 * ============================================================================
 *
 * Handles all real-time messaging and inbox functionality including:
 * - Conversation thread loading and rendering
 * - Message send, receive and read-receipt handling
 * - Inbox search and conversation filtering
 * - Mobile modal thread overlay management
 * - Unread message badge count updates
 * - Attachment and media message support
 * - Polling for new messages
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
    
    const P9Inbox = {
        currentSenderId: null,
        currentThreadId: null,
        currentProductId: null,
        selectedConversations: new Set(),
        initialized: false,
        isMobile: false,
        menuOpen: false,
        pollInterval: null,
        inboxPollInterval: null,
        pollRate: 10000,
        lastMessageId: null,
        lastInboxCheck: null,
        isPaused: false,
        _visibilityBound: false,
        heartbeatInterval: null,
        desktopStatusCheckInterval: null,
        $wrapper: null,
        $sidebar: null,
        $main: null,
        $conversations: null,
        $threadView: null,
        $placeholder: null,
        $mobileModal: null,
        $mobileOverlay: null,
        $loading: null,
        $toastContainer: null,
        $mobileBackdrop: null,
        
        init: function() {
            if (this.initialized) {
                console.log('P9Inbox: Already initialized');
                return;
            }
            
            console.log('P9Inbox: Initializing...');
            this.initialized = true;
            this.isMobile = window.innerWidth <= 768;
            this.cacheElements();
            this.bindEvents();
            this.handleResize();
            
            if (!this.isMobile) {
                this.autoLoadFirstThread();
            }
            
            this.startPolling();
            this.startHeartbeat();
            console.log('P9Inbox: Initialized successfully with real-time updates');
        },
        
        cacheElements: function() {
            this.$wrapper = $('.p9-inbox-wrapper');
            this.$sidebar = $('#p9-inbox-sidebar');
            this.$main = $('#p9-inbox-main');
            this.$conversations = $('#p9-conversations-container');
            this.$threadView = $('#p9-thread-view');
            this.$placeholder = $('#p9-thread-placeholder');
            this.$mobileModal = $('#p9-mobile-thread-modal');
            this.$mobileOverlay = $('#p9-mobile-modal-overlay');
            this.$loading = $('#p9-loading-overlay');
            this.$toastContainer = $('#p9-toast-container');
            this.$mobileBackdrop = $('<div class="p9-mobile-modal-backdrop"></div>');
            
            // Append backdrop if mobile modal exists
            if (this.$mobileModal.length) {
                this.$mobileModal.after(this.$mobileBackdrop);
            }
            
            // Set initial wrapper height
            this.setWrapperHeight();
        },
        
        setWrapperHeight: function() {
            if (this.isMobile) {
                this.$wrapper.css({
                    'height': '100vh',
                    'height': '100dvh',
                    'height': '-webkit-fill-available',
                    'position': 'fixed',
                    'top': '0',
                    'left': '0',
                    'right': '0',
                    'bottom': '0'
                });
            } else {
                this.$wrapper.css({
                    'height': '100vh',
                    'height': '100dvh',
                    'position': 'relative'
                });
            }
        },
        
        startPolling: function() {
            const self = this;

            // Clear any existing intervals
            if (this.pollInterval)       clearInterval(this.pollInterval);
            if (this.inboxPollInterval)  clearInterval(this.inboxPollInterval);

            // ── Thread message polling ────────────────────────────────────────
            // Only fires when a thread is open. 10 s is plenty for a chat UI.
            this.pollInterval = setInterval(function() {
                if (!self.isPaused && self.currentSenderId) {
                    self.checkForNewMessages();
                }
            }, 10000);  // 10 seconds

            // ── Inbox list polling ────────────────────────────────────────────
            // The sidebar thread list — 30 s is fine for this.
            // On first load, do an immediate check so unread count shows fast.
            this.checkForInboxUpdates();
            this.inboxPollInterval = setInterval(function() {
                if (!self.isPaused) {
                    self.checkForInboxUpdates();
                }
            }, 30000);  // 30 seconds

            // ── Page Visibility API ───────────────────────────────────────────
            // Pause all polling while the tab is hidden; burst-refresh on return.
            if (!this._visibilityBound) {
                this._visibilityBound = true;
                document.addEventListener('visibilitychange', function() {
                    if (document.hidden) {
                        self.isPaused = true;
                        console.log('P9Inbox: Polling paused (tab hidden)');
                    } else {
                        self.isPaused = false;
                        console.log('P9Inbox: Polling resumed (tab visible) — burst refresh');
                        // Immediate refresh on tab return
                        self.checkForInboxUpdates();
                        if (self.currentSenderId) {
                            self.checkForNewMessages();
                        }
                    }
                });
            }

            console.log('P9Inbox: Polling started — messages every 10 s, inbox list every 30 s');
        },
        
        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
            if (this.inboxPollInterval) {
                clearInterval(this.inboxPollInterval);
                this.inboxPollInterval = null;
            }
            console.log('P9Inbox: Polling stopped');
        },
        
        pollForUpdates: function() {
            if (this.currentSenderId) {
                this.checkForNewMessages();
            }
            this.checkForInboxUpdates();
        },
        
        checkForNewMessages: function() {
            const self = this;
            if (!this.currentSenderId) return;
            
            const $lastMessage = this.isMobile && this.$mobileModal.hasClass('is-active') ?
                $('#p9-mobile-messages-list .p9-message-bubble:last') :
                $('#p9-messages-list .p9-message-bubble:last');
            
            const lastId = $lastMessage.data('message-id') || this.lastMessageId || 0;
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_check_new_messages',
                    nonce: portalcloud9_ajax.nonce,
                    sender_id: this.currentSenderId,
                    last_message_id: lastId
                },
                success: function(response) {
                    if (response.success && response.data.new_messages && response.data.new_messages.length > 0) {
                        console.log('P9Inbox: New messages received:', response.data.new_messages.length);
                        self.appendNewMessages(response.data.new_messages);
                    }
                },
                error: function() {
                    console.log('P9Inbox: Poll check failed (silent)');
                }
            });
        },
        
        appendNewMessages: function(messages) {
            const self = this;
            const isMobileView = this.isMobile && this.$mobileModal.hasClass('is-active');
            const $container = isMobileView ? 
                $('#p9-mobile-messages-list') : 
                $('#p9-messages-list');
            
            messages.forEach(function(msg) {
                if ($container.find(`[data-message-id="${msg.id}"]`).length > 0) {
                    return;
                }
                
                const bubbleClass = msg.is_sent ? 'sent' : 'received';
                const $bubble = $(
                    `<div class="p9-message-bubble ${bubbleClass}" data-message-id="${msg.id}">
                        <div class="p9-message-content">
                            ${self.escapeHtml(msg.message)}
                            <span class="p9-message-timestamp">${msg.date_formatted}</span>
                        </div>
                    </div>`
                );
                
                $bubble.hide().appendTo($container).fadeIn(300);
                self.lastMessageId = msg.id;
            });
            
            if (messages.length > 0) {
                const scrollContainer = isMobileView ? 
                    '#p9-mobile-messages-container' : 
                    '#p9-messages-wrapper';
                this.scrollToBottom(scrollContainer);
                
                const hasNewReceived = messages.some(m => !m.is_sent);
                if (hasNewReceived) {
                    this.playNotificationSound();
                }
            }
        },
        
        checkForInboxUpdates: function() {
            const self = this;
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_check_inbox_updates',
                    nonce: portalcloud9_ajax.nonce,
                    last_check: this.lastInboxCheck || ''
                },
                success: function(response) {
                    if (response.success) {
                        self.lastInboxCheck = response.data.timestamp;
                        
                        if (response.data.unread_count !== undefined) {
                            self.updateUnreadBadge(response.data.unread_count);
                        }
                        
                        if (response.data.has_changes && response.data.threads) {
                            self.updateConversationList(response.data.threads);
                        }
                    }
                },
                error: function() {
                    // Silent error
                }
            });
        },
        
        updateUnreadBadge: function(count) {
            const $pill = $('.p9-unread-pill');
            
            if (count > 0) {
                if ($pill.length) {
                    $pill.text(count + ' new');
                } else {
                    $('.p9-sidebar-title-group').append(
                        `<span class="p9-unread-pill">${count} new</span>`
                    );
                }
            } else {
                $pill.remove();
            }
            
            $('.portalcloud9-counter-badge, .portalcloud9-bubble-count').text(count);
            $('#inbox-counter, #portalcloud9-message-bubble').toggle(count > 0);
        },
        
        updateConversationList: function(threads) {
            const self = this;
            
            threads.forEach(function(thread) {
                const $card = $(`.p9-conversation-card[data-sender-id="${thread.sender_id}"]`);
                
                if ($card.length) {
                    $card.find('.p9-conv-preview').text(thread.latest_message);
                    $card.find('.p9-conv-time').text(thread.latest_date_formatted);
                    
                    if (thread.is_unread && !$card.hasClass('is-unread')) {
                        $card.addClass('is-unread');
                        
                        if (!$card.find('.p9-online-dot').length) {
                            $card.find('.p9-conv-avatar').append('<span class="p9-online-dot"></span>');
                        }
                        
                        if (!$card.find('.p9-new-badge').length) {
                            $card.find('.p9-conv-badge').html('<span class="p9-new-badge">New</span>');
                        }
                    }
                    
                    if (thread.is_unread) {
                        $card.prependTo(self.$conversations);
                    }
                } else {
                    self.addNewConversationCard(thread);
                }
            });
            
            const count = $('.p9-conversation-card').length;
            $('.p9-thread-count').text(count + ' conversation' + (count !== 1 ? 's' : ''));
        },
        
        addNewConversationCard: function(thread) {
            const productHtml = thread.product ? 
                `<div class="p9-conv-product">
                    <svg viewBox="0 0 24 24">
                        <path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/>
                    </svg>
                    <span>${this.escapeHtml(thread.product.title)}</span>
                </div>` : '';
            
            const unreadBadge = thread.is_unread ? 
                `<div class="p9-conv-badge">
                    <span class="p9-new-badge">New</span>
                </div>` : '';
            
            const onlineDot = thread.is_unread ? '<span class="p9-online-dot"></span>' : '';
            
            const $card = $(
                `<article class="p9-conversation-card ${thread.is_unread ? 'is-unread' : ''}" 
                         data-sender-id="${thread.sender_id}" 
                         data-thread-id="${thread.sender_id}" 
                         data-message-id="${thread.latest_message_id}" 
                         data-sender-name="${this.escapeHtml(thread.sender_name)}" 
                         tabindex="0" role="button">
                    <div class="p9-conv-select" onclick="event.stopPropagation();">
                        <label class="p9-checkbox-label">
                            <input type="checkbox" class="p9-conv-checkbox" data-thread-id="${thread.sender_id}">
                            <span class="p9-checkbox-box"></span>
                        </label>
                    </div>
                    <div class="p9-conv-avatar">
                        <img src="${thread.sender_avatar}" alt="${this.escapeHtml(thread.sender_name)}" loading="lazy">
                        ${onlineDot}
                    </div>
                    <div class="p9-conv-content">
                        <div class="p9-conv-header">
                            <h3 class="p9-conv-name">${this.escapeHtml(thread.sender_name)}</h3>
                            <time class="p9-conv-time">${thread.latest_date_formatted}</time>
                        </div>
                        ${productHtml}
                        <p class="p9-conv-preview">${this.escapeHtml(thread.latest_message)}</p>
                    </div>
                    ${unreadBadge}
                </article>`
            );
            
            $card.hide().prependTo(this.$conversations).slideDown(300);
            this.showToast(`New message from ${thread.sender_name}`, 'success');
        },
        
        playNotificationSound: function() {
            // Sound notification can be implemented here
        },
        
        bindEvents: function() {
            const self = this;
            
            // Remove any existing event handlers first
            $(document).off('click.p9inbox');
            $(document).off('keydown.p9inbox');
            
            // Conversation card click - FIXED: Using event delegation
            $(document).on('click.p9inbox', '.p9-conversation-card', function(e) {
                // Don't trigger if clicking on checkbox
                if ($(e.target).closest('.p9-conv-select, .p9-checkbox-label, .p9-checkbox-box').length) {
                    e.stopPropagation();
                    return;
                }
                
                e.preventDefault();
                e.stopPropagation();
                console.log('P9Inbox: Conversation card clicked', $(this).data('sender-name'));
                self.handleConversationClick($(this));
            });
            
            // Conversation card keyboard navigation
            $(document).on('keydown.p9inbox', '.p9-conversation-card', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    self.handleConversationClick($(this));
                }
            });
            
            // Search functionality
            $('#p9-search-conversations').on('input.p9inbox', function() {
                self.handleSearch($(this).val());
            });
            
            $('#p9-search-clear').on('click.p9inbox', function() {
                $('#p9-search-conversations').val('').trigger('input').focus();
                $(this).removeClass('show');
            });
            
            // Bulk selection
            $(document).on('change.p9inbox', '.p9-conv-checkbox', function(e) {
                e.stopPropagation();
                self.handleCheckboxChange($(this));
            });
            
            $('#p9-select-all').on('change.p9inbox', function() {
                self.handleSelectAll($(this).is(':checked'));
            });
            
            $('#p9-delete-selected').on('click.p9inbox', function() {
                self.handleDeleteSelected();
            });
            
            $('#p9-cancel-bulk').on('click.p9inbox', function() {
                self.cancelBulkSelection();
            });
            
            // Refresh inbox
            $('#p9-refresh-inbox, #p9-refresh-empty').on('click.p9inbox', function() {
                self.refreshInbox();
            });
            
            // Mark all as read
            $('#p9-mark-all-read').on('click.p9inbox', function() {
                self.markAllAsRead();
            });
            
            // Quick reply buttons
            $(document).on('click.p9inbox', '.p9-quick-btn', function() {
                const message = $(this).data('message');
                $('#p9-reply-textarea').val(message).trigger('input').focus();
            });
            
            // Desktop reply form
            $('#p9-reply-form').on('submit.p9inbox', function(e) {
                e.preventDefault();
                self.handleReplySend('#p9-reply-textarea', '#p9-send-btn');
            });
            
            $('#p9-reply-textarea').on('input.p9inbox', function() {
                self.updateCharCount($(this), '#p9-char-count');
                self.autoResizeTextarea($(this));
            });
            
            // Desktop thread delete
            $('#p9-thread-delete').on('click.p9inbox', function() {
                if (self.currentSenderId) {
                    self.deleteThread(self.currentSenderId);
                }
            });
            
            // Topbar back button (in main header)
            $('#p9-mobile-thread-back-btn').on('click.p9inbox', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeMobileModal();
            });
            
            // Topbar delete button (in main header)
            $('#p9-mobile-thread-delete-btn').on('click.p9inbox', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (self.currentThreadSender) {
                    if (confirm('Are you sure you want to delete this conversation?')) {
                        self.deleteThread(self.currentThreadSender);
                        self.closeMobileModal();
                    }
                }
            });
            
            this.$mobileOverlay.on('click.p9inbox', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeMobileModal();
            });
            
            // Mobile menu
            $('#p9-mobile-menu-btn').on('click.p9inbox', function(e) {
                e.stopPropagation();
                e.preventDefault();
                self.toggleMobileMenu();
            });
            
            $(document).on('click.p9inbox', function(e) {
                if (self.menuOpen && !$(e.target).closest('#p9-mobile-menu-dropdown, #p9-mobile-menu-btn').length) {
                    self.closeMobileMenu();
                }
            });
            
            // Mobile menu actions
            $('#p9-mobile-delete-thread').on('click.p9inbox', function(e) {
                e.preventDefault();
                if (self.currentSenderId) {
                    self.deleteThread(self.currentSenderId);
                }
                self.closeMobileMenu();
            });
            
            $('#p9-mobile-mark-read').on('click.p9inbox', function(e) {
                e.preventDefault();
                if (self.currentSenderId) {
                    self.markThreadAsRead(self.currentSenderId);
                }
                self.closeMobileMenu();
            });
            
            // Mobile quick reply buttons
            $(document).on('click.p9inbox', '.p9-mobile-quick-btn', function(e) {
                e.preventDefault();
                const message = $(this).data('message');
                $('#p9-mobile-textarea').val(message).trigger('input').focus();
            });
            
            // Mobile reply form
            $('#p9-mobile-reply-form').on('submit.p9inbox', function(e) {
                e.preventDefault();
                self.handleReplySend('#p9-mobile-textarea', '#p9-mobile-send-btn');
            });
            
            $('#p9-mobile-textarea').on('input.p9inbox', function() {
                self.updateCharCount($(this), '#p9-mobile-char-count', true);
                self.autoResizeTextarea($(this));
            });
            
            // Mobile keyboard visibility handling
            let initialViewportHeight = window.innerHeight;
            
            $('#p9-mobile-textarea').on('focus.p9inbox', function() {
                // Detect keyboard visibility by viewport height change
                const checkKeyboard = function() {
                    const currentHeight = window.innerHeight;
                    const viewportChanged = initialViewportHeight - currentHeight > 100;
                    
                    if (viewportChanged || window.visualViewport) {
                        self.$mobileModal.addClass('keyboard-visible');
                        
                        // Scroll to ensure input is visible
                        setTimeout(function() {
                            $('#p9-mobile-textarea')[0].scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'nearest',
                                inline: 'nearest'
                            });
                        }, 300);
                    }
                };
                
                // Check immediately and after a delay
                setTimeout(checkKeyboard, 100);
                setTimeout(checkKeyboard, 300);
                
                // Use visualViewport API if available (more reliable on iOS)
                if (window.visualViewport) {
                    const handleViewportChange = function() {
                        if (window.visualViewport.height < window.innerHeight) {
                            self.$mobileModal.addClass('keyboard-visible');
                        } else {
                            self.$mobileModal.removeClass('keyboard-visible');
                        }
                    };
                    
                    window.visualViewport.addEventListener('resize', handleViewportChange);
                    window.visualViewport.addEventListener('scroll', handleViewportChange);
                    
                    // Store cleanup function
                    if (!self.visualViewportCleanup) {
                        self.visualViewportCleanup = function() {
                            window.visualViewport.removeEventListener('resize', handleViewportChange);
                            window.visualViewport.removeEventListener('scroll', handleViewportChange);
                        };
                    }
                }
            });
            
            $('#p9-mobile-textarea').on('blur.p9inbox', function() {
                // Delay removing class to allow for smooth transitions
                setTimeout(function() {
                    self.$mobileModal.removeClass('keyboard-visible');
                }, 300);
            });
            
            // Update initial viewport height on window resize
            $(window).on('resize.p9keyboard', function() {
                if (!$('#p9-mobile-textarea').is(':focus')) {
                    initialViewportHeight = window.innerHeight;
                }
            });
            
            // Mobile scroll handling
            $('#p9-scroll-bottom-btn').on('click.p9inbox', function(e) {
                e.preventDefault();
                self.scrollToBottom('#p9-mobile-messages-container');
            });
            
            $('#p9-mobile-messages-container').on('scroll.p9inbox', function() {
                self.handleMobileScroll($(this));
            });
            
            // Window resize
            $(window).on('resize.p9inbox', $.debounce ? 
                $.debounce(150, function() {
                    self.handleResize();
                }) : 
                function() {
                    self.handleResize();
                }
            );
            
            // Keyboard shortcuts
            $(document).on('keydown.p9inbox', function(e) {
                if (e.key === 'Escape') {
                    if (self.menuOpen) {
                        self.closeMobileMenu();
                    } else if (self.$mobileModal.hasClass('is-active')) {
                        self.closeMobileModal();
                    }
                }
            });
            
            // Prevent body scroll when modal is open
            $(document).on('touchmove.p9inbox', function(e) {
                if (self.$mobileModal.hasClass('is-active')) {
                    e.preventDefault();
                }
            }, { passive: false });
            
            // Cleanup on page unload
            $(window).on('beforeunload.p9inbox', function() {
                self.stopPolling();
            });
            
            console.log('P9Inbox: Event handlers bound successfully');
        },
        
        handleConversationClick: function($card) {
            const senderId = $card.attr('data-sender-id');
            const messageId = $card.attr('data-message-id');
            const senderName = $card.attr('data-sender-name');
            
            console.log('P9Inbox: Handling conversation click', { 
                senderId, 
                messageId, 
                senderName, 
                isMobile: this.isMobile,
                cardExists: $card.length > 0
            });
            
            if (!senderId) {
                this.showToast('Unable to load conversation', 'error');
                return;
            }
            
            // Remove active class from all cards
            $('.p9-conversation-card').removeClass('is-active');
            
            // Add active class to clicked card
            $card.addClass('is-active');
            
            // Mark as read if unread
            if ($card.hasClass('is-unread')) {
                $card.removeClass('is-unread');
                $card.find('.p9-online-dot, .p9-new-badge').fadeOut(200);
            }
            
            this.currentSenderId = senderId;
            this.currentThreadId = messageId;
            this.lastMessageId = null;
            
            if (this.isMobile) {
                console.log('P9Inbox: Opening mobile modal for sender:', senderId);
                this.openMobileModal(senderId, senderName);
            } else {
                console.log('P9Inbox: Loading desktop thread for sender:', senderId);
                this.loadThread(senderId);
            }
        },
        
        autoLoadFirstThread: function() {
            const self = this;
            const $first = $('.p9-conversation-card').first();
            
            if ($first.length) {
                setTimeout(function() {
                    console.log('P9Inbox: Auto-loading first thread');
                    self.handleConversationClick($first);
                }, 300);
            }
        },
        
        loadThread: function(senderId, isMobile = false) {
            const self = this;
            console.log('P9Inbox: Loading thread for sender:', senderId, 'isMobile:', isMobile);
            
            if (!senderId) {
                this.showToast('Invalid conversation', 'error');
                return;
            }
            
            if (isMobile) {
                $('#p9-mobile-loading').show();
            } else {
                this.showLoading();
            }
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_load_thread',
                    nonce: portalcloud9_ajax.nonce,
                    sender_id: senderId
                },
                success: function(response) {
                    console.log('P9Inbox: Thread loaded successfully', response);
                    
                    if (isMobile) {
                        $('#p9-mobile-loading').hide();
                    } else {
                        self.hideLoading();
                    }
                    
                    if (response.success) {
                        if (response.data.product_id) {
                            self.currentProductId = response.data.product_id;
                        }
                        
                        self.renderThread(response.data, isMobile);
                        
                        setTimeout(function() {
                            self.markThreadAsRead(senderId);
                        }, 500);
                    } else {
                        self.showToast(response.data || 'Failed to load conversation', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('P9Inbox: Load thread error:', error);
                    
                    if (isMobile) {
                        $('#p9-mobile-loading').hide();
                    } else {
                        self.hideLoading();
                    }
                    
                    self.showToast('Failed to load conversation', 'error');
                }
            });
        },
        
        renderThread: function(data, isMobile = false) {
            const { messages, sender, product } = data;
            console.log('P9Inbox: Rendering thread', { 
                messageCount: messages ? messages.length : 0, 
                isMobile 
            });
            
            if (isMobile) {
                this.renderMobileThread(messages, sender, product);
            } else {
                this.renderDesktopThread(messages, sender, product);
            }
        },
        
        renderDesktopThread: function(messages, sender, product) {
            console.log('P9Inbox: Rendering desktop thread for sender:', sender);
            
            const self = this;
            
            // Clear any previous desktop status interval
            if (this.desktopStatusCheckInterval) {
                clearInterval(this.desktopStatusCheckInterval);
                this.desktopStatusCheckInterval = null;
            }
            
            // Hide placeholder and show thread view
            this.$placeholder.hide();
            this.$threadView.show();
            
            // Fix messages area height now that thread-view is visible
            setTimeout(window.p9FixInboxHeight, 0);
            setTimeout(window.p9FixInboxHeight, 200);
            
            // Update thread header
            $('#p9-thread-avatar').attr('src', sender.avatar);
            $('#p9-thread-name').text(sender.name);
            
            // Reset status to "Checking..."
            $('#p9-thread-status').removeClass('online offline').text('Checking...');
            $('#p9-desktop-status-dot').removeClass('online offline');
            
            // Real-time status check function
            var desktopCheckStatus = function() {
                var userId = sender.id || 0;
                
                // Skip for guests
                if (!userId || userId === 0 || sender.is_guest) {
                    $('#p9-thread-status').removeClass('online offline').text('Guest');
                    $('#p9-desktop-status-dot').removeClass('online offline');
                    return;
                }
                
                console.log('P9Inbox: Desktop status check for user ID:', userId);
                
                $.ajax({
                    url: portalcloud9_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'portcld9_check_user_status',
                        nonce: portalcloud9_ajax.nonce,
                        user_id: userId
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var isOnline = response.data.is_online;
                            var $statusText = $('#p9-thread-status');
                            var $statusDot = $('#p9-desktop-status-dot');
                            
                            $statusText.removeClass('online offline');
                            $statusDot.removeClass('online offline');
                            
                            if (isOnline) {
                                $statusText.addClass('online').text('Online');
                                $statusDot.addClass('online');
                                console.log('P9Inbox: Desktop ✅ User is ONLINE (last seen ' + response.data.time_diff_minutes + ' min ago)');
                            } else {
                                $statusText.addClass('offline').text('Offline');
                                $statusDot.addClass('offline');
                                console.log('P9Inbox: Desktop ❌ User is OFFLINE (last seen ' + response.data.time_diff_minutes + ' min ago)');
                            }
                        }
                    },
                    error: function() {
                        $('#p9-thread-status').addClass('offline').text('Offline');
                        $('#p9-desktop-status-dot').addClass('offline');
                    }
                });
            };
            
            // Check immediately on thread open
            desktopCheckStatus();
            
            // Then every 30 seconds
            this.desktopStatusCheckInterval = setInterval(desktopCheckStatus, 30000);
            
            // Clear and render messages
            const $list = $('#p9-messages-list').empty();
            this.renderMessages(messages, $list);
            
            // Update product info if exists
            if (product) {
                $('#p9-product-thumb').attr('src', product.image);
                $('#p9-product-name').text(product.title);
                $('#p9-product-price').html(product.price);
                $('#p9-product-stock').text(product.stock_status);
                $('#p9-product-link').attr('href', product.url);
                $('#p9-product-context').show();
                setTimeout(window.p9FixInboxHeight, 0);
            } else {
                $('#p9-product-context').hide();
                setTimeout(window.p9FixInboxHeight, 0);
            }
            
            // Scroll to bottom
            this.scrollToBottom('#p9-messages-wrapper');
        },
        
        renderMobileThread: function(messages, sender, product) {
            console.log('P9Inbox: Rendering mobile thread for sender:', sender.name);
            
            // Update mobile header
            $('#p9-mobile-user-avatar').attr('src', sender.avatar);
            $('#p9-mobile-thread-title').text(sender.name);
            
            // Clear and render messages
            const $list = $('#p9-mobile-messages-list').empty();
            this.renderMessages(messages, $list);
            
            // Update product info if exists
            if (product) {
                console.log('P9Inbox: Mobile product data:', product);
                $('#p9-mobile-product-img').attr('src', product.image);
                $('#p9-mobile-product-title').text(product.title);
                $('#p9-mobile-product-price').html(product.price);
                $('#p9-mobile-product-stock').text(product.stock_status);
                $('#p9-mobile-product-link').attr('href', product.url);
                $('#p9-mobile-product-card').show();
            } else {
                $('#p9-mobile-product-card').hide();
            }
            
            // Scroll to bottom
            setTimeout(() => {
                this.scrollToBottom('#p9-mobile-messages-container');
            }, 100);
        },
        
        renderMessages: function(messages, $container) {
            const self = this;
            
            if (!messages || messages.length === 0) {
                $container.html('<p class="p9-no-messages">No messages in this conversation</p>');
                return;
            }
            
            messages.forEach(function(msg) {
                const bubbleClass = msg.is_sent ? 'sent' : 'received';
                const $bubble = $(
                    `<div class="p9-message-bubble ${bubbleClass}" data-message-id="${msg.id}">
                        <div class="p9-message-content">
                            ${self.escapeHtml(msg.message)}
                            <span class="p9-message-timestamp">${msg.date_formatted}</span>
                        </div>
                    </div>`
                );
                
                $container.append($bubble);
                self.lastMessageId = msg.id;
            });
        },
        
        handleReplySend: function(textareaSelector, buttonSelector) {
            const self = this;
            const $textarea = $(textareaSelector);
            const $btn = $(buttonSelector);
            const message = $textarea.val().trim();
            
            if (!message) {
                this.showToast('Please enter a message', 'error');
                return;
            }
            
            if (!this.currentSenderId) {
                this.showToast('No conversation selected', 'error');
                return;
            }
            
            if ($btn.prop('disabled')) {
                return;
            }
            
            $btn.prop('disabled', true);
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_send_reply',
                    nonce: portalcloud9_ajax.nonce,
                    sender_id: this.currentSenderId,
                    product_id: this.currentProductId || 0,
                    message: message
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    
                    if (response.success) {
                        $textarea.val('').trigger('input');
                        
                        const msgId = response.data.message_id || Date.now();
                        const $bubble = $(
                            `<div class="p9-message-bubble sent" data-message-id="${msgId}">
                                <div class="p9-message-content">
                                    ${self.escapeHtml(message)}
                                    <span class="p9-message-timestamp">Just now</span>
                                </div>
                            </div>`
                        );
                        
                        if (self.isMobile && self.$mobileModal.hasClass('is-active')) {
                            $('#p9-mobile-messages-list').append($bubble);
                            self.scrollToBottom('#p9-mobile-messages-container');
                        } else {
                            $('#p9-messages-list').append($bubble);
                            self.scrollToBottom('#p9-messages-wrapper');
                        }
                        
                        self.lastMessageId = msgId;
                        self.showToast('Message sent', 'success');
                    } else {
                        self.showToast(response.data || 'Failed to send message', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false);
                    console.error('P9Inbox: Send reply error:', error);
                    self.showToast('Failed to send message', 'error');
                }
            });
        },
        
        updateCharCount: function($textarea, counterSelector, compact = false) {
            const len = $textarea.val().length;
            
            if (compact) {
                $(counterSelector).text(len + '/1000');
            } else {
                $(counterSelector).text(len);
            }
        },
        
        autoResizeTextarea: function($textarea) {
            $textarea.css('height', 'auto');
            const scrollHeight = $textarea[0].scrollHeight;
            const maxHeight = parseInt($textarea.css('max-height'), 10) || 150;
            $textarea.css('height', Math.min(scrollHeight, maxHeight) + 'px');
        },
        
        hideWebsiteFooter: function() {
            // Store original footer states for restoration
            if (!this.footerStates) {
                this.footerStates = [];
            }
            
            // Find all common footer elements
            const footerSelectors = [
                'footer',
                '.site-footer',
                '#footer',
                '#colophon',
                '.footer',
                '[role="contentinfo"]',
                '.footer-wrapper',
                '#site-footer',
                '.main-footer',
                '#main-footer'
            ];
            
            footerSelectors.forEach(function(selector) {
                $(selector).each(function() {
                    const $footer = $(this);
                    // Don't hide Portal Cloud 9 elements
                    if (!$footer.closest('.p9-inbox-wrapper, .p9-mobile-thread-modal').length) {
                        // Store original display state
                        const originalDisplay = $footer.css('display');
                        const originalVisibility = $footer.css('visibility');
                        $footer.data('p9-original-display', originalDisplay);
                        $footer.data('p9-original-visibility', originalVisibility);
                        // Hide the footer
                        $footer.css({
                            'display': 'none',
                            'visibility': 'hidden'
                        }).addClass('p9-footer-hidden');
                    }
                });
            });
            
            // Also hide WordPress admin bar on mobile
            if (window.innerWidth <= 768) {
                $('#wpadminbar').css('display', 'none').addClass('p9-adminbar-hidden');
            }
        },
        
        showWebsiteFooter: function() {
            // Restore all hidden footers
            $('.p9-footer-hidden').each(function() {
                const $footer = $(this);
                const originalDisplay = $footer.data('p9-original-display') || '';
                const originalVisibility = $footer.data('p9-original-visibility') || '';
                $footer.css({
                    'display': originalDisplay,
                    'visibility': originalVisibility
                }).removeClass('p9-footer-hidden');
            });
            
            // Restore admin bar
            $('.p9-adminbar-hidden').css('display', '').removeClass('p9-adminbar-hidden');
        },
        
        openMobileModal: function(senderId, senderName) {
            console.log('P9Inbox: Opening mobile modal for', senderName);
            
            // Hide main inbox
            this.$sidebar.hide();
            
            // Hide website footer elements
            this.hideWebsiteFooter();
            
            // On mobile, transform the topbar for thread view
            if (window.innerWidth <= 768) {
                // Hide normal topbar elements
                $('#portalcloud9-mobile-toggle').hide();
                $('.portalcloud9-page-title').hide();
                $('.portalcloud9-topbar-actions').hide();
                $('.portalcloud9-topbar-avatar').hide();
                
                // Show thread action buttons
                $('#p9-mobile-thread-back-btn').show().css('display', 'flex');
                $('#p9-mobile-thread-delete-btn').show().css('display', 'flex');
                
                // Show thread user info in center
                $('#p9-thread-user-info').show().css('display', 'flex');
                $('#p9-thread-user-name').text(senderName || 'Loading...');
                
                // Get user avatar and status from the conversation card if available
                var $conversation = $('.p9-conversation-card[data-sender-id="' + senderId + '"]');
                var avatarUrl = $conversation.find('.p9-conv-avatar img').attr('src') || '';
                var otherPartyId = $conversation.data('other-party-id') || 0;
                
                // If we have otherPartyId, use it; otherwise try to extract from senderId
                var userIdToCheck = otherPartyId;
                if (!userIdToCheck || userIdToCheck === 0) {
                    // Try to extract from thread_key format: "user_{id}_{product_id}"
                    if (senderId.indexOf('user_') === 0) {
                        var parts = senderId.split('_');
                        if (parts.length >= 2) {
                            userIdToCheck = parseInt(parts[1]);
                        }
                    }
                }
                
                console.log('P9Inbox: Checking status for user ID:', userIdToCheck);
                
                if (avatarUrl) {
                    $('#p9-thread-avatar').attr('src', avatarUrl).attr('alt', senderName);
                } else {
                    // Fallback: generate avatar from name initial
                    var initial = senderName ? senderName.charAt(0).toUpperCase() : '?';
                    $('#p9-thread-avatar').attr('src', 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="%231E90FF"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="white" font-family="Arial" font-size="20" font-weight="bold">' + initial + '</text></svg>');
                }
                
                // Check online/offline status in real-time via AJAX
                var self = this;
                
                // Function to check user status
                var checkUserStatus = function() {
                    if (!userIdToCheck || userIdToCheck === 0) {
                        console.log('P9Inbox: No valid user ID to check status');
                        $('#p9-thread-status-indicator').removeClass('online').addClass('offline');
                        $('#p9-thread-status-text').removeClass('online').addClass('offline').text('Offline');
                        return;
                    }
                    
                    console.log('P9Inbox: Sending status check request for user ID:', userIdToCheck);
                    
                    $.ajax({
                        url: portalcloud9_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'portcld9_check_user_status',
                            nonce: portalcloud9_ajax.nonce,
                            user_id: userIdToCheck
                        },
                        success: function(response) {
                            console.log('P9Inbox: Status check FULL response:', response);
                            
                            if (response.success && response.data) {
                                console.log('P9Inbox: Status details:', {
                                    'is_online': response.data.is_online,
                                    'status': response.data.status,
                                    'last_seen': response.data.last_seen,
                                    'current_time': response.data.current_time,
                                    'time_diff_minutes': response.data.time_diff_minutes,
                                    'threshold_minutes': response.data.threshold_minutes
                                });
                                
                                var isOnline = response.data.is_online;
                                var $statusIndicator = $('#p9-thread-status-indicator');
                                var $statusText = $('#p9-thread-status-text');
                                $statusIndicator.removeClass('online offline away');
                                $statusText.removeClass('online offline away');
                                
                                if (isOnline) {
                                    $statusIndicator.addClass('online');
                                    $statusText.addClass('online').text('Online');
                                    console.log('P9Inbox: ✅ User is ONLINE (last seen ' + response.data.time_diff_minutes + ' minutes ago)');
                                } else {
                                    $statusIndicator.addClass('offline');
                                    $statusText.addClass('offline').text('Offline');
                                    console.log('P9Inbox: ❌ User is OFFLINE (last seen ' + response.data.time_diff_minutes + ' minutes ago)');
                                }
                            } else {
                                console.error('P9Inbox: Invalid response structure:', response);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('P9Inbox: Status check AJAX error:', {
                                'status': status,
                                'error': error,
                                'responseText': xhr.responseText
                            });
                            // Fallback to offline if AJAX fails
                            $('#p9-thread-status-indicator').removeClass('online').addClass('offline');
                            $('#p9-thread-status-text').removeClass('online').addClass('offline').text('Offline');
                        }
                    });
                };
                
                // Check status immediately
                checkUserStatus();
                
                // Check status every 30 seconds while thread is open
                self.statusCheckInterval = setInterval(checkUserStatus, 30000);
                
                // Store current sender for delete action
                this.currentThreadSender = senderId;
                
                console.log('P9Inbox: Topbar transformed to thread mode with user info');
            }
            
            // Show modal with animation
            this.$mobileOverlay.addClass('is-active');
            this.$mobileModal.addClass('is-active');
            $('body').addClass('p9-mobile-modal-open').css('overflow', 'hidden');
            
            // Set title and clear previous content
            $('#p9-mobile-thread-title').text(senderName || 'Loading...');
            $('#p9-mobile-messages-list').empty();
            $('#p9-mobile-product-card').hide();
            $('#p9-mobile-textarea').val('').trigger('input');
            
            // Ensure reply section is visible and properly positioned
            $('.p9-mobile-reply-section').css({
                'display': 'flex',
                'visibility': 'visible',
                'opacity': '1',
                'position': 'absolute',
                'bottom': '0',
                'left': '0',
                'right': '0',
                'z-index': '100',
                'min-height': '120px'
            });
            
            // Also ensure form and quick replies are visible
            $('.p9-mobile-quick-replies, .p9-mobile-reply-form').css({
                'display': 'flex',
                'visibility': 'visible',
                'opacity': '1'
            });
            
            // Force textarea visibility
            $('#p9-mobile-textarea').css({
                'display': 'block',
                'visibility': 'visible',
                'opacity': '1'
            });
            
            // Debug log for iOS
            console.log('P9Inbox: Reply section visibility enforced');
            
            // iOS-specific: Double-check visibility after a short delay
            setTimeout(function() {
                $('.p9-mobile-reply-section').css({
                    'display': 'flex',
                    'visibility': 'visible',
                    'opacity': '1',
                    'position': 'absolute'
                });
                
                console.log('P9Inbox: iOS visibility double-check completed');
            }, 100);
            
            // Load thread
            this.loadThread(senderId, true);
        },
        
        closeMobileModal: function() {
            console.log('P9Inbox: Closing mobile modal');
            
            // Clear status check interval
            if (this.statusCheckInterval) {
                clearInterval(this.statusCheckInterval);
                this.statusCheckInterval = null;
            }
            
            // Hide modal with animation
            this.$mobileOverlay.removeClass('is-active');
            this.$mobileModal.removeClass('is-active');
            $('body').removeClass('p9-mobile-modal-open').css('overflow', '');
            
            // Restore website footer
            this.showWebsiteFooter();
            
            // Show sidebar again
            this.$sidebar.show();
            
            // On mobile, restore topbar to normal state
            if (window.innerWidth <= 768) {
                // Show normal topbar elements
                $('#portalcloud9-mobile-toggle').show().css('display', 'flex');
                $('.portalcloud9-page-title').show().css('display', 'flex');
                $('.portalcloud9-topbar-actions').show();
                $('.portalcloud9-topbar-avatar').show();
                
                // Hide thread action buttons
                $('#p9-mobile-thread-back-btn').hide();
                $('#p9-mobile-thread-delete-btn').hide();
                
                // Hide thread user info
                $('#p9-thread-user-info').hide();
                
                console.log('P9Inbox: Topbar restored to normal mode');
            }
            
            // Clear form
            $('#p9-mobile-textarea').val('').trigger('input');
            this.closeMobileMenu();
            
            // Reset current sender
            this.currentSenderId = null;
            this.lastMessageId = null;
        },
        
        toggleMobileMenu: function() {
            if (this.menuOpen) {
                this.closeMobileMenu();
            } else {
                this.openMobileMenu();
            }
        },
        
        openMobileMenu: function() {
            $('#p9-mobile-menu-dropdown').show();
            this.menuOpen = true;
        },
        
        closeMobileMenu: function() {
            $('#p9-mobile-menu-dropdown').hide();
            this.menuOpen = false;
        },
        
        handleMobileScroll: function($container) {
            const scrollTop = $container.scrollTop();
            const scrollHeight = $container[0].scrollHeight;
            const clientHeight = $container[0].clientHeight;
            
            if (scrollHeight - scrollTop - clientHeight > 100) {
                $('#p9-scroll-bottom-btn').show();
            } else {
                $('#p9-scroll-bottom-btn').hide();
            }
        },
        
        handleSearch: function(query) {
            const $clearBtn = $('#p9-search-clear');
            query = query.toLowerCase().trim();
            
            if (query) {
                $clearBtn.addClass('show');
            } else {
                $clearBtn.removeClass('show');
            }
            
            $('.p9-conversation-card').each(function() {
                const $card = $(this);
                const name = ($card.attr('data-sender-name') || '').toLowerCase();
                const preview = $card.find('.p9-conv-preview').text().toLowerCase();
                const product = $card.find('.p9-conv-product span').text().toLowerCase();
                
                if (name.includes(query) || preview.includes(query) || product.includes(query)) {
                    $card.show();
                } else {
                    $card.hide();
                }
            });
        },
        
        handleCheckboxChange: function($checkbox) {
            const threadId = $checkbox.data('thread-id');
            const $card = $checkbox.closest('.p9-conversation-card');
            
            if ($checkbox.is(':checked')) {
                this.selectedConversations.add(String(threadId));
                $card.addClass('is-selected');
            } else {
                this.selectedConversations.delete(String(threadId));
                $card.removeClass('is-selected');
            }
            
            this.updateBulkUI();
        },
        
        handleSelectAll: function(selectAll) {
            const self = this;
            
            $('.p9-conv-checkbox:visible').each(function() {
                const $checkbox = $(this);
                const threadId = $checkbox.data('thread-id');
                const $card = $checkbox.closest('.p9-conversation-card');
                
                $checkbox.prop('checked', selectAll);
                
                if (selectAll) {
                    self.selectedConversations.add(String(threadId));
                    $card.addClass('is-selected');
                } else {
                    self.selectedConversations.delete(String(threadId));
                    $card.removeClass('is-selected');
                }
            });
            
            this.updateBulkUI();
        },
        
        handleDeleteSelected: function() {
            if (this.selectedConversations.size === 0) {
                this.showToast('No conversations selected', 'error');
                return;
            }
            
            if (!confirm(`Delete ${this.selectedConversations.size} conversation(s)?`)) {
                return;
            }
            
            this.deleteMultipleThreads(Array.from(this.selectedConversations));
        },
        
        cancelBulkSelection: function() {
            this.selectedConversations.clear();
            $('.p9-conv-checkbox').prop('checked', false);
            $('.p9-conversation-card').removeClass('is-selected');
            $('#p9-select-all').prop('checked', false);
            this.updateBulkUI();
        },
        
        updateBulkUI: function() {
            const count = this.selectedConversations.size;
            
            if (count > 0) {
                $('#p9-bulk-actions').show();
                $('#p9-bulk-count').text(count + ' selected');
            } else {
                $('#p9-bulk-actions').hide();
            }
        },
        
        refreshInbox: function() {
            const self = this;
            this.showLoading();
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portalcloud9_get_inbox_data',
                    nonce: portalcloud9_ajax.nonce
                },
                success: function(response) {
                    self.hideLoading();
                    if (response.success) {
                        location.reload();
                    }
                },
                error: function() {
                    self.hideLoading();
                    location.reload();
                }
            });
        },
        
        markThreadAsRead: function(senderId) {
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_mark_thread_read',
                    nonce: portalcloud9_ajax.nonce,
                    sender_id: senderId
                },
                success: function() {
                    const $card = $(`.p9-conversation-card[data-sender-id="${senderId}"]`);
                    $card.removeClass('is-unread');
                    $card.find('.p9-online-dot, .p9-new-badge').remove();
                }
            });
            
            this.updateUnreadCount();
        },
        
        markAllAsRead: function() {
            const self = this;
            this.showLoading();
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_mark_all_read',
                    nonce: portalcloud9_ajax.nonce
                },
                success: function(response) {
                    self.hideLoading();
                    
                    if (response.success) {
                        $('.p9-conversation-card').removeClass('is-unread');
                        $('.p9-online-dot, .p9-new-badge').remove();
                        $('.p9-unread-pill').remove();
                        
                        self.updateUnreadCount();
                        self.showToast('All messages marked as read', 'success');
                    } else {
                        self.showToast('Failed to mark messages as read', 'error');
                    }
                },
                error: function() {
                    self.hideLoading();
                    self.showToast('An error occurred', 'error');
                }
            });
        },
        
        deleteThread: function(senderId) {
            if (!confirm('Are you sure you want to delete this conversation?')) {
                return;
            }
            
            this.deleteMultipleThreads([senderId]);
        },
        
        deleteMultipleThreads: function(threadIds) {
            const self = this;
            this.showLoading();
            
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_delete_conversations',
                    nonce: portalcloud9_ajax.nonce,
                    thread_ids: threadIds
                },
                success: function(response) {
                    self.hideLoading();
                    
                    if (response.success) {
                        threadIds.forEach(function(tid) {
                            $(`.p9-conversation-card[data-sender-id="${tid}"]`)
                                .fadeOut(300, function() {
                                    $(this).remove();
                                });
                        });
                        
                        self.selectedConversations.clear();
                        self.updateBulkUI();
                        
                        const remaining = $('.p9-conversation-card').length - threadIds.length;
                        $('.p9-thread-count').text(
                            remaining + ' conversation' + (remaining !== 1 ? 's' : '')
                        );
                        
                        if (threadIds.includes(String(self.currentSenderId))) {
                            if (self.$mobileModal.hasClass('is-active')) {
                                self.closeMobileModal();
                            } else {
                                self.$threadView.hide();
                                self.$placeholder.show();
                            }
                            self.currentSenderId = null;
                        }
                        
                        if (remaining <= 0) {
                            setTimeout(function() {
                                location.reload();
                            }, 500);
                        }
                        
                        self.showToast('Conversation(s) deleted', 'success');
                    } else {
                        self.showToast(response.data || 'Failed to delete', 'error');
                    }
                },
                error: function() {
                    self.hideLoading();
                    self.showToast('An error occurred', 'error');
                }
            });
        },
        
        updateUnreadCount: function() {
            if (typeof window.updatePortalCloud9NotificationCounts === 'function') {
                window.updatePortalCloud9NotificationCounts();
            }
            
            const unreadCount = $('.p9-conversation-card.is-unread').length;
            this.updateUnreadBadge(unreadCount);
        },
        
        handleResize: function() {
            const wasMobile = this.isMobile;
            this.isMobile = window.innerWidth <= 768;
            
            console.log('P9Inbox: Resize detected, isMobile:', this.isMobile, 'wasMobile:', wasMobile);
            
            // Update wrapper height based on screen size
            this.setWrapperHeight();
            
            if (wasMobile && !this.isMobile) {
                // Switched from mobile to desktop
                this.closeMobileModal();
                this.$sidebar.show();
                if (this.currentSenderId) {
                    this.loadThread(this.currentSenderId);
                }
            } else if (!wasMobile && this.isMobile && this.currentSenderId) {
                // Switched from desktop to mobile with active thread
                this.$main.hide();
                this.$placeholder.hide();
                this.openMobileModal(this.currentSenderId, $('#p9-thread-name').text());
            }
            
            // Update UI based on screen size
            if (this.isMobile) {
                this.$main.hide();
                this.$placeholder.hide();
                this.$sidebar.show();
                
                // Adjust mobile modal if open
                if (this.$mobileModal.hasClass('is-active')) {
                    this.$mobileModal.css({
                        'height': 'calc(100vh - 64px)',
                        'height': 'calc(100dvh - 64px)',
                        'height': '-webkit-fill-available'
                    });
                }
            } else {
                this.$main.show();
                if (!this.currentSenderId) {
                    this.$placeholder.show();
                }
                this.$sidebar.show();
            }
            
            // Force recalculation of container heights
            setTimeout(() => {
                this.$conversations.css('height', '');
                $('.p9-messages-wrapper').css('height', '');
                if (this.isMobile && this.$mobileModal.hasClass('is-active')) {
                    $('.p9-mobile-messages').css('height', '');
                }
            }, 100);
        },
        
        scrollToBottom: function(containerSelector) {
            const $container = $(containerSelector);
            if ($container.length) {
                // Wait for DOM updates
                setTimeout(function() {
                    $container.scrollTop($container[0].scrollHeight);
                }, 50);
            }
        },
        
        showLoading: function() {
            this.$loading.fadeIn(200);
        },
        
        hideLoading: function() {
            this.$loading.fadeOut(200);
        },
        
        showToast: function(message, type = 'success') {
            const $toast = $(
                `<div class="p9-toast ${type}">
                    ${this.escapeHtml(message)}
                </div>`
            );
            
            this.$toastContainer.append($toast);
            
            setTimeout(function() {
                $toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        },
        
        startHeartbeat: function() {
            const self = this;
            
            // Update immediately
            this.updateUserHeartbeat();
            
            // Then update every 30 seconds
            this.heartbeatInterval = setInterval(function() {
                self.updateUserHeartbeat();
            }, 30000);
            
            console.log('P9Inbox: Heartbeat started - will update every 30 seconds');
        },
        
        stopHeartbeat: function() {
            if (this.heartbeatInterval) {
                clearInterval(this.heartbeatInterval);
                this.heartbeatInterval = null;
                console.log('P9Inbox: Heartbeat stopped');
            }
        },
        
        updateUserHeartbeat: function() {
            $.ajax({
                url: portalcloud9_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'portcld9_update_heartbeat',
                    nonce: portalcloud9_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        console.log('P9Inbox: ❤️ Heartbeat updated - user marked as online');
                    }
                },
                error: function() {
                    console.error('P9Inbox: Heartbeat update failed');
                }
            });
        },
        
        escapeHtml: function(text) {
            if (!text) return '';
            
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return String(text).replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        }
    };
    
    // Add debounce if not available
    if (!$.debounce) {
        $.debounce = function(wait, func) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        };
    }
    
    // Initialize on document ready
    $(document).ready(function() {
        console.log('P9Inbox: Document ready');
        
        if ($('.p9-inbox-wrapper').length) {
            console.log('P9Inbox: Wrapper found, checking dependencies...');
            
            if (typeof portalcloud9_ajax === 'undefined') {
                console.error('P9Inbox: portalcloud9_ajax is not defined!');
                return;
            }
            
            P9Inbox.init();
        } else {
            console.log('P9Inbox: Wrapper not found, skipping initialization');
        }
    });
})(jQuery);
/* ============================================
   INBOX INIT
   ============================================ */
document.addEventListener('DOMContentLoaded', function() {
    console.log('P9Inbox: DOM loaded, initializing...');
    
    // Add mobile body class if needed
    if (window.innerWidth <= 768) {
        document.body.classList.add('p9-is-mobile');
    }
});

/* ── Desktop fullscreen height enforcer ─────────────────────────────────
   CSS alone cannot win here because the wrapper has its own height:100vh
   and multiple layers of !important rules conflict.
   This function is called AFTER the thread view is visible so that
   offsetHeight measurements are accurate.
──────────────────────────────────────────────────────────────────────── */
window.p9FixInboxHeight = function() {
    if (!document.body.classList.contains('p9-inbox-fullscreen')) return;

    var threadView = document.getElementById('p9-thread-view');
    var wrapper    = document.getElementById('p9-messages-wrapper');
    var reply      = document.getElementById('p9-reply-section');

    if (!threadView || !wrapper || !reply) return;

    // threadView must be visible for measurements to work
    if (threadView.style.display === 'none' || threadView.offsetParent === null) return;

    var threadViewH = threadView.offsetHeight;        // total height of thread panel
    var replyH      = reply.offsetHeight;             // reply section (includes product card + quick replies + textarea)

    // messages wrapper gets whatever is left inside thread-view
    var available = threadViewH - replyH;

    // Also subtract thread header
    var header = document.getElementById('p9-thread-header');
    if (header) available -= header.offsetHeight;

    // Subtract product context if visible
    var product = document.getElementById('p9-product-context');
    if (product && product.style.display !== 'none') available -= product.offsetHeight;

    if (available < 100) available = 100; // safety floor

    wrapper.style.cssText += '; height:' + available + 'px !important; max-height:' + available + 'px !important; min-height:0 !important; overflow-y:auto !important; flex:none !important;';
};

window.addEventListener('resize', function() { setTimeout(window.p9FixInboxHeight, 50); });
