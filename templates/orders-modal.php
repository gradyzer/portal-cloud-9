<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
/**
 * Portal Cloud 9 – Order Edit Modal
 * Manager orders modal with enhanced delete functionality
 * Version: 2.4.0 - Added Order Notes with deletion and note type selector
 */
defined('ABSPATH') || exit;

// Get order statuses for the dropdown
$order_statuses = wc_get_order_statuses();

// WooCommerce order actions
$wc_order_actions = [
    ''                        => __('Choose an action...', 'portal-cloud-9'),
    'send_order_details'      => __('Send order details to customer', 'portal-cloud-9'),
    'send_order_details_admin'=> __('Resend new order notification', 'portal-cloud-9'),
    'regenerate_download_permissions' => __('Regenerate download permissions', 'portal-cloud-9'),
];
?>

<!-- SVG Gradients -->
<svg width="0" height="0" style="position: absolute;">
    <defs>
        <linearGradient id="delete-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#ffffff;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#ef4444;stop-opacity:1" />
        </linearGradient>
    </defs>
</svg>

<!-- OVERLAY - Separate from modal -->
<div class="p9-order-modal-overlay"></div>

<!-- MODAL - Now a sibling, NOT a child of overlay -->
<div class="p9-order-modal">
    <form id="p9-order-edit-form">
        <header class="p9-modal-header">
            <h2 class="p9-modal-title"><?php esc_html_e('Edit Order', 'portal-cloud-9'); ?> <span>#0</span></h2>
            <div class="p9-modal-header-actions">
                <button type="button" class="p9-modal-delete" aria-label="<?php esc_attr_e('Delete Order', 'portal-cloud-9'); ?>" title="<?php esc_attr_e('Delete Order', 'portal-cloud-9'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        <line x1="10" y1="11" x2="10" y2="17"/>
                        <line x1="14" y1="11" x2="14" y2="17"/>
                    </svg>
                </button>
                <button type="button" class="p9-modal-close" aria-label="<?php esc_attr_e('Close', 'portal-cloud-9'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </header>

        <div class="p9-modal-body">
            
            <!-- Order Status -->
            <section class="p9-modal-section">
                <h3 class="p9-modal-section-title"><?php esc_html_e('Order Status', 'portal-cloud-9'); ?></h3>
                <div class="p9-form-group">
                    <label class="p9-form-label" for="p9-order-status"><?php esc_html_e('Status', 'portal-cloud-9'); ?></label>
                    <select class="p9-form-select" id="p9-order-status" name="order_status">
                        <?php foreach ($order_statuses as $status_key => $status_label) : ?>
                            <option value="<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </section>

            <!-- Order Items -->
            <section class="p9-modal-section">
                <h3 class="p9-modal-section-title"><?php esc_html_e('Order Items', 'portal-cloud-9'); ?></h3>
                <div class="p9-modal-order-items">
                    <!-- Populated by JavaScript -->
                </div>
            </section>

            <!-- Billing Details -->
            <section class="p9-modal-section">
                <h3 class="p9-modal-section-title"><?php esc_html_e('Billing Details', 'portal-cloud-9'); ?></h3>
                <div class="p9-modal-form-grid">
                    <div class="p9-form-group">
                        <label class="p9-form-label" for="p9-billing-first-name"><?php esc_html_e('First Name', 'portal-cloud-9'); ?></label>
                        <input type="text" class="p9-form-input" id="p9-billing-first-name" name="billing_first_name">
                    </div>
                    <div class="p9-form-group">
                        <label class="p9-form-label" for="p9-billing-last-name"><?php esc_html_e('Last Name', 'portal-cloud-9'); ?></label>
                        <input type="text" class="p9-form-input" id="p9-billing-last-name" name="billing_last_name">
                    </div>
                </div>
                <div class="p9-form-group">
                    <label class="p9-form-label" for="p9-billing-email"><?php esc_html_e('Email', 'portal-cloud-9'); ?></label>
                    <input type="email" class="p9-form-input" id="p9-billing-email" name="billing_email">
                </div>
                <div class="p9-form-group">
                    <label class="p9-form-label" for="p9-billing-phone"><?php esc_html_e('Phone', 'portal-cloud-9'); ?></label>
                    <input type="tel" class="p9-form-input" id="p9-billing-phone" name="billing_phone">
                </div>
                <div class="p9-form-group">
                    <label class="p9-form-label" for="p9-billing-address"><?php esc_html_e('Address', 'portal-cloud-9'); ?></label>
                    <input type="text" class="p9-form-input" id="p9-billing-address" name="billing_address">
                </div>
                <div class="p9-modal-form-grid">
                    <div class="p9-form-group">
                        <label class="p9-form-label" for="p9-billing-city"><?php esc_html_e('City', 'portal-cloud-9'); ?></label>
                        <input type="text" class="p9-form-input" id="p9-billing-city" name="billing_city">
                    </div>
                    <div class="p9-form-group">
                        <label class="p9-form-label" for="p9-billing-postcode"><?php esc_html_e('Postcode', 'portal-cloud-9'); ?></label>
                        <input type="text" class="p9-form-input" id="p9-billing-postcode" name="billing_postcode">
                    </div>
                </div>
                <div class="p9-form-group">
                    <label class="p9-form-label" for="p9-billing-country"><?php esc_html_e('Country', 'portal-cloud-9'); ?></label>
                    <select class="p9-form-select" id="p9-billing-country" name="billing_country">
                        <?php foreach (WC()->countries->get_countries() as $code => $name) : ?>
                            <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </section>

            <!-- Order Totals -->
            <section class="p9-modal-section">
                <h3 class="p9-modal-section-title"><?php esc_html_e('Order Totals', 'portal-cloud-9'); ?></h3>
                <div class="p9-order-totals">
                    <div class="p9-totals-row">
                        <span class="p9-totals-label"><?php esc_html_e('Subtotal', 'portal-cloud-9'); ?></span>
                        <span class="p9-totals-value" id="p9-modal-subtotal">$0.00</span>
                    </div>
                    <div class="p9-totals-row">
                        <span class="p9-totals-label"><?php esc_html_e('Shipping', 'portal-cloud-9'); ?></span>
                        <span class="p9-totals-value" id="p9-modal-shipping">$0.00</span>
                    </div>
                    <div class="p9-totals-row">
                        <span class="p9-totals-label"><?php esc_html_e('Discount', 'portal-cloud-9'); ?></span>
                        <span class="p9-totals-value" id="p9-modal-discount">-$0.00</span>
                    </div>
                    <div class="p9-totals-row p9-totals-total">
                        <span class="p9-totals-label"><?php esc_html_e('Total', 'portal-cloud-9'); ?></span>
                        <span class="p9-totals-value" id="p9-modal-total">$0.00</span>
                    </div>
                </div>
            </section>

            <!-- Order Actions -->
            <section class="p9-modal-section">
                <h3 class="p9-modal-section-title"><?php esc_html_e('Order Actions', 'portal-cloud-9'); ?></h3>
                <div class="p9-order-actions-grid">
                    <select class="p9-form-select" id="p9-order-action-select" name="order_action">
                        <?php foreach ($wc_order_actions as $action_key => $action_label) : ?>
                            <option value="<?php echo esc_attr($action_key); ?>"><?php echo esc_html($action_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="p9-action-btn p9-action-btn-pill" id="p9-apply-action-btn">
                        <?php esc_html_e('Apply', 'portal-cloud-9'); ?>
                    </button>
                </div>
            </section>

            <!-- Order Notes -->
            <section class="p9-modal-section">
                <h3 class="p9-modal-section-title"><?php esc_html_e('Order Notes', 'portal-cloud-9'); ?></h3>
                
                <!-- Note Type Selector -->
                <div class="p9-note-type-selector">
                    <label class="p9-note-type-option">
                        <input type="radio" name="note_type" value="private" checked>
                        <span class="p9-note-type-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <?php esc_html_e('Private Note', 'portal-cloud-9'); ?>
                        </span>
                    </label>
                    <label class="p9-note-type-option">
                        <input type="radio" name="note_type" value="customer">
                        <span class="p9-note-type-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            <?php esc_html_e('Note to Customer', 'portal-cloud-9'); ?>
                        </span>
                    </label>
                </div>

                <!-- Add Note Form -->
                <div class="p9-form-group">
                    <textarea class="p9-form-textarea" id="p9-order-note" name="order_note" placeholder="<?php esc_attr_e('Add a note...', 'portal-cloud-9'); ?>" rows="3"></textarea>
                </div>
                <button type="button" class="p9-add-note-btn" id="p9-add-note-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <?php esc_html_e('Add Note', 'portal-cloud-9'); ?>
                </button>

                <!-- Notes List -->
                <div class="p9-order-notes-list" id="p9-order-notes-list">
                    <!-- Notes populated by JavaScript -->
                </div>
            </section>

        </div>

        <footer class="p9-modal-footer">
            <button type="button" class="p9-modal-btn p9-modal-btn-cancel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
                <?php esc_html_e('Cancel', 'portal-cloud-9'); ?>
            </button>
            <button type="submit" class="p9-modal-btn p9-modal-btn-save">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <?php esc_html_e('Save Changes', 'portal-cloud-9'); ?>
            </button>
        </footer>
    </form>
</div>

<!-- Delete Confirmation Dialog - Also separated -->
<div class="p9-delete-confirm-overlay" id="p9-delete-confirm-overlay"></div>
<div class="p9-confirm-dialog" id="p9-delete-confirm">
    <div class="p9-confirm-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
    </div>
    <h3 class="p9-confirm-title"><?php esc_html_e('Delete Order?', 'portal-cloud-9'); ?></h3>
    <p class="p9-confirm-message"><?php esc_html_e('Are you sure you want to delete this order? This action cannot be undone.', 'portal-cloud-9'); ?></p>
    <div class="p9-confirm-actions">
        <button type="button" class="p9-confirm-btn p9-confirm-btn-cancel" id="p9-delete-cancel">
            <?php esc_html_e('Cancel', 'portal-cloud-9'); ?>
        </button>
        <button type="button" class="p9-confirm-btn p9-confirm-btn-delete" id="p9-delete-confirm-btn">
            <?php esc_html_e('Delete Order', 'portal-cloud-9'); ?>
        </button>
    </div>
</div>

<!-- Delete Note Confirmation Dialog -->
<div class="p9-delete-note-overlay" id="p9-delete-note-overlay"></div>
<div class="p9-confirm-dialog" id="p9-delete-note-confirm">
    <div class="p9-confirm-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
    </div>
    <h3 class="p9-confirm-title"><?php esc_html_e('Delete Note?', 'portal-cloud-9'); ?></h3>
    <p class="p9-confirm-message"><?php esc_html_e('Are you sure you want to delete this note? This action cannot be undone.', 'portal-cloud-9'); ?></p>
    <div class="p9-confirm-actions">
        <button type="button" class="p9-confirm-btn p9-confirm-btn-cancel" id="p9-delete-note-cancel">
            <?php esc_html_e('Cancel', 'portal-cloud-9'); ?>
        </button>
        <button type="button" class="p9-confirm-btn p9-confirm-btn-delete" id="p9-delete-note-confirm-btn">
            <?php esc_html_e('Delete Note', 'portal-cloud-9'); ?>
        </button>
    </div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>
