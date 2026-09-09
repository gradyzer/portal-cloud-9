<?php
/**
 * Portal Cloud 9 - Mobile Threads Modal
@filepath templates/tabs/mobile-threads.php
 * @status FULL CODE - MOBILE UI REDESIGN
 */
defined('ABSPATH') || exit;
?>

<!-- Mobile Thread Modal Overlay -->
<div class="p9-mobile-modal-overlay" id="p9-mobile-modal-overlay" aria-hidden="true"></div>

<!-- Mobile Thread Modal -->
<aside class="p9-mobile-thread-modal" id="p9-mobile-thread-modal" role="dialog" aria-modal="true" aria-labelledby="p9-mobile-thread-title">
    
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
                    <img src="" alt="" class="p9-mobile-user-avatar" id="p9-mobile-user-avatar">
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
                <span>Delete</span>
            </button>
            <button type="button" class="p9-mobile-menu-item" id="p9-mobile-mark-read">
                <div class="p9-menu-item-icon p9-menu-icon-success">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 11 12 14 22 4"/>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                    </svg>
                </div>
                <span>Mark Read</span>
            </button>
        </div>
    </div>
    
    <!-- Product Context Card (Enhanced Design) -->
    <div class="p9-mobile-product-card" id="p9-mobile-product-card" style="display:none;">
        <div class="p9-mobile-product-wrapper">
            <div class="p9-mobile-product-inner">
                <div class="p9-mobile-product-image">
                    <img src="" alt="" id="p9-mobile-product-img">
                    <div class="p9-product-image-overlay"></div>
                </div>
                <div class="p9-mobile-product-info">
                    <span class="p9-mobile-product-label">
                        <svg viewBox="0 0 24 24">
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
                    <svg viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
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
