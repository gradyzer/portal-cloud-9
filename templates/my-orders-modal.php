<?php
/**
 * Portal Cloud 9 – My Orders Modal (Customer View)
 * Shows order details in a slide-panel for customers
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
?>


<div class="p9-my-order-modal-overlay" id="p9-my-order-modal-overlay">
    <div class="p9-my-order-modal" id="p9-my-order-modal">
        <header class="p9-my-modal-header">
            <h2 class="p9-my-modal-title"><?php esc_html_e('Order Details', 'portal-cloud-9'); ?> <span id="p9-my-modal-order-number">#0</span></h2>
            <button type="button" class="p9-my-modal-close" id="p9-my-modal-close" aria-label="<?php esc_attr_e('Close', 'portal-cloud-9'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </header>

        <div class="p9-my-modal-body" id="p9-my-modal-body">
            <!-- Loading state -->
            <div class="p9-my-modal-loading" id="p9-my-modal-loading">
                <div class="p9-my-modal-spinner"></div>
                <p class="p9-my-modal-loading-text"><?php esc_html_e('Loading order details...', 'portal-cloud-9'); ?></p>
            </div>
            
            <!-- Content (populated by JS) -->
            <div id="p9-my-modal-content" style="display:none;"></div>
        </div>

        <footer class="p9-my-modal-footer">
            <button type="button" class="p9-my-modal-btn p9-my-modal-btn-close" id="p9-my-modal-close-btn">
                <?php esc_html_e('Close', 'portal-cloud-9'); ?>
            </button>
            <a href="#" class="p9-my-modal-btn p9-my-modal-btn-primary" id="p9-my-modal-support-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
                <?php esc_html_e('Need Help?', 'portal-cloud-9'); ?>
            </a>
        </footer>
    </div>
</div>

