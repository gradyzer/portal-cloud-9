<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are function-scoped, not global.
/**
 * Portal Cloud 9 - Inbox Template
 * @filepath templates/tabs/inbox.php
 * @status FULL CODE - FIXED MOBILE MODAL
 */
defined('ABSPATH') || exit;

$p9_messaging = new PortalCloud9_Messaging_Integration();

// Update current user's last seen time when loading inbox
$p9_user_id = get_current_user_id();
if ($p9_user_id) {
    update_user_meta($p9_user_id, 'p9_last_seen', current_time('timestamp'));
}

$p9_inbox = $p9_messaging->get_inbox_data();
$current_user = wp_get_current_user();
$thread_count = count($p9_inbox['threads']);
$unread_count = $p9_inbox['unread_count'] ?? 0;

// Build wrapper classes
$wrapper_classes = ['p9-inbox-wrapper', 'p9-light'];

// Add mobile detection class
if (wp_is_mobile()) {
    $wrapper_classes[] = 'p9-is-mobile';
}
?>

<div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>" data-user-id="<?php echo esc_attr($p9_user_id); ?>" data-is-mobile="<?php echo wp_is_mobile() ? 'true' : 'false'; ?>">
    
    <?php if (empty($p9_inbox['threads'])): ?>
    <!-- Empty State -->
    <div class="p9-inbox-empty-state">
        <div class="p9-empty-glass-card">
            <div class="p9-empty-icon-wrapper">
                <svg class="p9-empty-icon" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <linearGradient id="emptyGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" style="stop-color:#1e90ff;stop-opacity:1" />
                            <stop offset="100%" style="stop-color:#00d4ff;stop-opacity:1" />
                        </linearGradient>
                    </defs>
                    <rect x="8" y="16" width="64" height="48" rx="6" stroke="url(#emptyGradient)" stroke-width="3" fill="none"/>
                    <path d="M8 26L40 46L72 26" stroke="url(#emptyGradient)" stroke-width="3" stroke-linecap="round" fill="none"/>
                    <circle cx="60" cy="20" r="12" fill="url(#emptyGradient)" opacity="0.2"/>
                    <path d="M56 20L59 23L65 17" stroke="url(#emptyGradient)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h2 class="p9-empty-title">Your Inbox is Empty</h2>
            <p class="p9-empty-subtitle">When customers send you messages about products, they'll appear here.</p>
            <div class="p9-empty-actions">
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="p9-btn p9-btn-primary">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    <span>Browse Products</span>
                </a>
                <button type="button" class="p9-btn p9-btn-glass" id="p9-refresh-empty">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"/>
                        <polyline points="1 20 1 14 7 14"/>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                    </svg>
                    <span>Refresh</span>
                </button>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Main Inbox Layout -->
    <div class="p9-inbox-layout">
        
        <!-- Left Panel: Conversation List -->
        <aside class="p9-inbox-sidebar" id="p9-inbox-sidebar">
            <!-- Sidebar Header -->
            <header class="p9-sidebar-header">
                <div class="p9-sidebar-title-row">
                    <div class="p9-sidebar-title-group">
                        <h1 class="p9-sidebar-title" id="p9-sidebar-title">Messages</h1>
                        <?php if ($unread_count > 0): ?>
                        <span class="p9-unread-pill"><?php echo absint( $unread_count ); ?> new</span>
                        <?php endif; ?>
                    </div>
                    <div class="p9-sidebar-actions">
                        <button type="button" class="p9-action-btn" id="p9-mark-all-read" title="Mark all as read" aria-label="Mark all as read">
                            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 11 12 14 22 4"/>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                            </svg>
                        </button>
                        <button type="button" class="p9-action-btn" id="p9-refresh-inbox" title="Refresh inbox" aria-label="Refresh inbox">
                            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="23 4 23 10 17 10"/>
                                <polyline points="1 20 1 14 7 14"/>
                                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <!-- Search Bar -->
                <div class="p9-search-wrapper">
                    <input type="text" class="p9-search-input" id="p9-search-conversations" placeholder="Search conversations..." aria-label="Search conversations">
                    <svg class="p9-search-icon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <button type="button" class="p9-search-clear" id="p9-search-clear" aria-label="Clear search">
                        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </header>
            
            <!-- Bulk Actions Bar -->
            <div class="p9-bulk-actions" id="p9-bulk-actions" style="display:none;">
                <div class="p9-bulk-left">
                    <label class="p9-checkbox-label">
                        <input type="checkbox" id="p9-select-all" class="p9-checkbox">
                        <span class="p9-checkbox-box"></span>
                    </label>
                    <span class="p9-bulk-count" id="p9-bulk-count">0 selected</span>
                </div>
                <div class="p9-bulk-right">
                    <button type="button" class="p9-bulk-delete" id="p9-delete-selected" title="Delete selected">
                        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                        <span>Delete</span>
                    </button>
                    <button type="button" class="p9-bulk-cancel" id="p9-cancel-bulk">Cancel</button>
                </div>
            </div>
            
            <!-- Conversations List -->
            <div class="p9-conversations-container" id="p9-conversations-container">
                <?php foreach ($p9_inbox['threads'] as $thread): 
                    $sender_id = $thread['sender_id'] ?? '';
                    $is_unread = !empty($thread['is_unread']);
                    $is_guest = !empty($thread['is_guest']);
                ?>
                <article class="p9-conversation-card <?php echo esc_attr( $is_unread ? 'is-unread' : '' ); ?>" 
                         data-sender-id="<?php echo esc_attr($sender_id); ?>"
                         data-thread-id="<?php echo esc_attr($sender_id); ?>"
                         data-message-id="<?php echo esc_attr($thread['latest_message_id'] ?? ''); ?>"
                         data-sender-name="<?php echo esc_attr($thread['sender_name'] ?? ''); ?>"
                         data-online="<?php echo esc_attr( !empty($thread['is_online']) ? 'true' : 'false' ); ?>"
                         data-other-party-id="<?php echo esc_attr($thread['other_party_id'] ?? '0'); ?>"
                         tabindex="0"
                         role="button"
                         aria-label="Conversation with <?php echo esc_attr($thread['sender_name'] ?? 'Unknown'); ?>"
                         data-mobile-clickable="true">
                    
                    <!-- Checkbox for bulk selection -->
                    <div class="p9-conv-select" onclick="event.stopPropagation();">
                        <label class="p9-checkbox-label">
                            <input type="checkbox" class="p9-checkbox p9-conv-checkbox" data-thread-id="<?php echo esc_attr($sender_id); ?>">
                            <span class="p9-checkbox-box"></span>
                        </label>
                    </div>
                    
                    <!-- Avatar -->
                    <div class="p9-conv-avatar">
                        <img src="<?php echo esc_url($thread['sender_avatar'] ?? ''); ?>" 
                             alt="<?php echo esc_attr($thread['sender_name'] ?? ''); ?>"
                             loading="lazy"
                             width="48"
                             height="48">
                        <?php if (!empty($thread['is_online']) && !$is_guest): ?>
                        <span class="p9-online-indicator" title="Online" aria-label="User is online"></span>
                        <?php endif; ?>
                        <?php if ($is_unread): ?>
                        <span class="p9-online-dot" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php if ($is_guest): ?>
                        <span class="p9-guest-badge" title="Guest User">G</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Content -->
                    <div class="p9-conv-content">
                        <div class="p9-conv-header">
                            <h3 class="p9-conv-name"><?php echo esc_html($thread['sender_name'] ?? 'Unknown'); ?></h3>
                            <time class="p9-conv-time" datetime="<?php echo esc_attr($thread['latest_date'] ?? ''); ?>">
                                <?php echo esc_html($thread['latest_date_formatted'] ?? ''); ?>
                            </time>
                        </div>
                        
                        <?php if (!empty($thread['product'])): ?>
                        <div class="p9-conv-product">
                            <svg viewBox="0 0 24 24" width="14" height="14">
                                <path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/>
                            </svg>
                            <span><?php echo esc_html(wp_trim_words($thread['product']['title'] ?? '', 4)); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <p class="p9-conv-preview"><?php echo esc_html($thread['latest_message'] ?? ''); ?></p>
                    </div>
                    
                    <!-- Unread indicator -->
                    <?php if ($is_unread): ?>
                    <div class="p9-conv-badge">
                        <span class="p9-new-badge">New</span>
                    </div>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            
            <!-- Sidebar Footer -->
            <footer class="p9-sidebar-footer">
                <span class="p9-thread-count"><?php echo absint( $thread_count ); ?> conversation<?php echo esc_html( $thread_count !== 1 ? 's' : '' ); ?></span>
            </footer>
        </aside>
        
        <!-- Right Panel: Thread View (Desktop) -->
        <main class="p9-inbox-main" id="p9-inbox-main">
            <!-- Placeholder State -->
            <div class="p9-thread-placeholder" id="p9-thread-placeholder">
                <div class="p9-placeholder-content">
                    <div class="p9-placeholder-icon">
                        <svg width="80" height="80" viewBox="0 0 80 80" fill="none">
                            <defs>
                                <linearGradient id="placeholderGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#1e90ff;stop-opacity:0.3" />
                                    <stop offset="100%" style="stop-color:#00d4ff;stop-opacity:0.1" />
                                </linearGradient>
                            </defs>
                            <circle cx="40" cy="40" r="36" fill="url(#placeholderGrad)" stroke="#1e90ff" stroke-width="2" opacity="0.5"/>
                            <path d="M28 32h24M28 40h18M28 48h22" stroke="#1e90ff" stroke-width="3" stroke-linecap="round" opacity="0.6"/>
                        </svg>
                    </div>
                    <h2 class="p9-placeholder-title">Select a Conversation</h2>
                    <p class="p9-placeholder-text">Choose a message from the list to view the full conversation</p>
                </div>
            </div>
            
            <!-- Active Thread Content -->
            <div class="p9-thread-view" id="p9-thread-view" style="display:none;">
                <!-- Thread Header -->
                <header class="p9-thread-header" id="p9-thread-header">
                    <div class="p9-thread-user">
                        <div class="p9-thread-avatar-wrapper">
                            <img src="" alt="" class="p9-thread-avatar" id="p9-thread-avatar" width="44" height="44">
                            <span class="p9-desktop-status-dot" id="p9-desktop-status-dot"></span>
                        </div>
                        <div class="p9-thread-info">
                            <h2 class="p9-thread-name" id="p9-thread-name">Loading...</h2>
                            <span class="p9-thread-status" id="p9-thread-status">Checking...</span>
                        </div>
                    </div>
                    <div class="p9-thread-actions">
                        <button type="button" class="p9-thread-action-btn" id="p9-thread-delete" title="Delete conversation">
                            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                        </button>
                    </div>
                </header>
                
                <!-- Messages Container -->
                <div class="p9-messages-wrapper" id="p9-messages-wrapper">
                    <div class="p9-messages-list" id="p9-messages-list">
                        <!-- Messages will be injected here -->
                    </div>
                </div>
                
                <!-- Product Card -->
                <div class="p9-product-context" id="p9-product-context" style="display:none;">
                    <div class="p9-product-card-mini">
                        <img src="" alt="" class="p9-product-thumb" id="p9-product-thumb" width="48" height="48">
                        <div class="p9-product-details">
                            <span class="p9-product-label">Discussing</span>
                            <h4 class="p9-product-name" id="p9-product-name"></h4>
                            <div class="p9-product-meta">
                                <span class="p9-product-price" id="p9-product-price"></span>
                                <span class="p9-product-stock" id="p9-product-stock"></span>
                            </div>
                        </div>
                        <a href="#" class="p9-product-link" id="p9-product-link" target="_blank" rel="noopener" aria-label="View product">
                            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                        </a>
                    </div>
                </div>
                
                <!-- Reply Box -->
                <div class="p9-reply-section" id="p9-reply-section">
                    <!-- Quick Replies + char counter on same row -->
                    <div class="p9-quick-replies">
                        <button type="button" class="p9-quick-btn" data-message="Thank you for your interest! How can I help you?">
                            <span class="p9-quick-emoji">👋</span>
                            <span>Thank you</span>
                        </button>
                        <button type="button" class="p9-quick-btn" data-message="The product is currently available and ready to ship.">
                            <span class="p9-quick-emoji">✅</span>
                            <span>Available</span>
                        </button>
                        <button type="button" class="p9-quick-btn" data-message="I'll get back to you shortly with more information.">
                            <span class="p9-quick-emoji">⏰</span>
                            <span>Will respond</span>
                        </button>
                        <!-- Char counter pushed to right end of the row -->
                        <span class="p9-char-counter p9-counter-desktop p9-counter-layout">
                            <span id="p9-char-count">0</span>/1000
                        </span>
                    </div>
                    
                    <!-- Reply Form -->
                    <div class="p9-compose-wrap">
                        <form class="p9-reply-form" id="p9-reply-form">
                            <textarea 
                                class="p9-reply-textarea" 
                                id="p9-reply-textarea"
                                placeholder="Type your message..."
                                maxlength="1000"
                                rows="1"
                                required
                                aria-label="Message input"></textarea>
                            <button type="submit" class="p9-send-btn" id="p9-send-btn" aria-label="Send message">
                                <svg viewBox="0 0 24 24" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none">
                                    <line x1="22" y1="2" x2="11" y2="13" stroke="#ffffff" fill="none"/>
                                    <polygon points="22 2 15 22 11 13 2 9 22 2" stroke="#ffffff" fill="none"/>
                                </svg>
                                <span>Send</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <?php endif; ?>
    
    <!-- Loading Overlay -->
    <div class="p9-loading-overlay" id="p9-loading-overlay" style="display:none;">
        <div class="p9-loader">
            <div class="p9-loader-spinner"></div>
            <span>Loading...</span>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="p9-toast-container" id="p9-toast-container" aria-live="polite"></div>
</div>

<!-- Mobile Thread Modal - MUST BE OUTSIDE WRAPPER -->
<?php if (!empty($p9_inbox['threads'])): ?>
<!-- Mobile Modal Overlay -->
<div class="p9-mobile-modal-overlay" id="p9-mobile-modal-overlay" aria-hidden="true"></div>

<!-- Mobile Thread Modal -->
<aside class="p9-mobile-thread-modal" id="p9-mobile-thread-modal" role="dialog" aria-modal="true" aria-labelledby="p9-mobile-thread-title" style="display:none;">
    
    <!-- Modal Header with Gradient Background -->
    <header class="p9-mobile-modal-header">
        <div class="p9-mobile-header-bg"></div>
        <div class="p9-mobile-header-content">
            <button type="button" class="p9-mobile-back-btn" id="p9-mobile-back-btn" aria-label="Close conversation">
                <svg viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
            <div class="p9-mobile-user-info">
                <div class="p9-mobile-avatar-wrapper">
                    <img src="" alt="" class="p9-mobile-user-avatar" id="p9-mobile-user-avatar" width="44" height="44">
                    <span class="p9-mobile-avatar-ring"></span>
                    <span class="p9-mobile-status-dot"></span>
                </div>
                <div class="p9-mobile-user-details">
                    <h2 class="p9-mobile-user-name" id="p9-mobile-thread-title">Loading...</h2>
                    <span class="p9-mobile-user-status" id="p9-mobile-user-status">
                        <span class="p9-status-indicator"></span>
                        <span class="p9-status-text">Active now</span>
                    </span>
                </div>
            </div>
            <button type="button" class="p9-mobile-menu-btn" id="p9-mobile-menu-btn" aria-label="More options">
                <svg viewBox="0 0 24 24">
                    <circle cx="12" cy="5" r="2"/>
                    <circle cx="12" cy="12" r="2"/>
                    <circle cx="12" cy="19" r="2"/>
                </svg>
            </button>
        </div>
    </header>
    
    <!-- Mobile Menu Dropdown -->
    <div class="p9-mobile-menu-dropdown" id="p9-mobile-menu-dropdown" style="display:none;">
        <div class="p9-mobile-menu-inner">
            <button type="button" class="p9-mobile-menu-item" id="p9-mobile-delete-thread">
                <div class="p9-menu-item-icon p9-menu-icon-danger">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                </div>
                <span>Delete Conversation</span>
            </button>
            <button type="button" class="p9-mobile-menu-item" id="p9-mobile-mark-read">
                <div class="p9-menu-item-icon p9-menu-icon-success">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 11 12 14 22 4"/>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                    </svg>
                </div>
                <span>Mark as Read</span>
            </button>
        </div>
    </div>
    
    <!-- Product Context Card (Enhanced Design) -->
    <div class="p9-mobile-product-card" id="p9-mobile-product-card" style="display:none;">
        <div class="p9-mobile-product-wrapper">
            <div class="p9-mobile-product-inner">
                <div class="p9-mobile-product-image">
                    <img src="" alt="" id="p9-mobile-product-img" width="56" height="56">
                    <div class="p9-product-image-overlay"></div>
                </div>
                <div class="p9-mobile-product-info">
                    <span class="p9-mobile-product-label">
                        <svg viewBox="0 0 24 24" width="12" height="12">
                            <path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/>
                        </svg>
                        Product Discussion
                    </span>
                    <h4 class="p9-mobile-product-title" id="p9-mobile-product-title"></h4>
                    <div class="p9-mobile-product-meta">
                        <span class="p9-mobile-product-price" id="p9-mobile-product-price"></span>
                        <span class="p9-mobile-product-divider">•</span>
                        <span class="p9-mobile-product-stock" id="p9-mobile-product-stock"></span>
                    </div>
                </div>
                <a href="#" class="p9-mobile-product-link" id="p9-mobile-product-link" target="_blank" rel="noopener" aria-label="View product">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Messages Container -->
    <div class="p9-mobile-messages-container" id="p9-mobile-messages-container">
        <div class="p9-mobile-messages-list" id="p9-mobile-messages-list">
            <!-- Messages will be injected here -->
        </div>
        
        <!-- Scroll to Bottom Button (Redesigned) -->
        <button type="button" class="p9-scroll-bottom-btn" id="p9-scroll-bottom-btn" style="display:none;" aria-label="Scroll to bottom">
            <span class="p9-scroll-pulse"></span>
            <svg viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>
    </div>
    
    <!-- Reply Section (Enhanced) -->
    <div class="p9-mobile-reply-section" id="p9-mobile-reply-section">
        <!-- Quick Replies (Redesigned with Icons) -->
        <div class="p9-mobile-quick-replies" id="p9-mobile-quick-replies">
            <button type="button" class="p9-mobile-quick-btn" data-message="Thank you for your interest! How can I help you?">
                <span class="p9-quick-emoji">👋</span>
                <span class="p9-quick-label">Thanks</span>
            </button>
            <button type="button" class="p9-mobile-quick-btn" data-message="The product is currently available and ready to ship.">
                <span class="p9-quick-emoji">✅</span>
                <span class="p9-quick-label">Available</span>
            </button>
            <button type="button" class="p9-mobile-quick-btn" data-message="I'll get back to you shortly with more information.">
                <span class="p9-quick-emoji">⏰</span>
                <span class="p9-quick-label">Soon</span>
            </button>
            <button type="button" class="p9-mobile-quick-btn" data-message="Could you please provide more details about what you're looking for?">
                <span class="p9-quick-emoji">❓</span>
                <span class="p9-quick-label">Details</span>
            </button>
        </div>
        
        <!-- Reply Form (Enhanced) -->
        <form class="p9-mobile-reply-form" id="p9-mobile-reply-form">
            <div class="p9-mobile-input-container">
                <div class="p9-mobile-input-wrapper">
                    <textarea 
                        class="p9-mobile-textarea" 
                        id="p9-mobile-textarea"
                        placeholder="Type a message..."
                        maxlength="1000"
                        rows="1"
                        required
                        aria-label="Message input"></textarea>
                    <span class="p9-mobile-char-count" id="p9-mobile-char-count">0/1000</span>
                </div>
                <button type="submit" class="p9-mobile-send-btn" id="p9-mobile-send-btn" aria-label="Send message">
                    <span class="p9-send-btn-bg"></span>
                    <svg viewBox="0 0 24 24" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none">
                        <line x1="22" y1="2" x2="11" y2="13" stroke="#ffffff" fill="none"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2" stroke="#ffffff" fill="none"/>
                    </svg>
                </button>
            </div>
        </form>
    </div>
    
    <!-- Loading State (Enhanced) -->
    <div class="p9-mobile-loading" id="p9-mobile-loading" style="display:none;">
        <div class="p9-mobile-loader-wrapper">
            <div class="p9-mobile-loader">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <span class="p9-loading-text">Loading conversation...</span>
        </div>
    </div>
</aside>

<!-- Backdrop for Mobile Modal -->
<div class="p9-mobile-modal-backdrop" id="p9-mobile-modal-backdrop" style="display:none;"></div>
<?php endif; ?>

<!-- Initialize Inbox Script -->
