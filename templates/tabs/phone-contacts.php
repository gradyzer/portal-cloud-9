<?php
/**
 * Phone Contacts Tab
 * Admins see all contacts. Shop Managers see only contacts on their products.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Template-scoped variables; not in global namespace during plugin execution.

defined('ABSPATH') || exit;

$is_admin   = current_user_can( 'manage_options' );
$is_manager = ! $is_admin && current_user_can( 'manage_woocommerce' );

if ( ! $is_admin && ! $is_manager ) {
    echo '<div class="pc9-error">You do not have permission to access this page.</div>';
    return;
}
?>

<div class="pc9-phone-contacts-wrapper">

    <?php if ( $is_manager && ! $is_admin ) : ?>
    <div style="padding:10px 14px;margin-bottom:16px;border-radius:12px;background:rgba(30,144,255,0.08);border:1px solid rgba(30,144,255,0.18);font-size:13px;color:#94a3b8;display:flex;align-items:center;gap:8px;">
        <span>📞</span>
        <span>Showing phone contacts only from customers who interacted with <strong style="color:#e2e8f0;">your products</strong>.</span>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="pc9-stats-grid">
        <div class="pc9-stat-card" title="Total times your product phone numbers were clicked">
            <div class="pc9-stat-icon pc9-stat-total"><i class="fas fa-phone-volume"></i></div>
            <div class="pc9-stat-content">
                <div class="pc9-stat-label">Total Clicks</div>
                <div class="pc9-stat-value" id="pc9-stat-total">0</div>
            </div>
        </div>
        <div class="pc9-stat-card" title="Phone clicks recorded today">
            <div class="pc9-stat-icon pc9-stat-today"><i class="fas fa-calendar-day"></i></div>
            <div class="pc9-stat-content">
                <div class="pc9-stat-label">Today</div>
                <div class="pc9-stat-value" id="pc9-stat-today">0</div>
            </div>
        </div>
        <div class="pc9-stat-card" title="Phone clicks in the current week">
            <div class="pc9-stat-icon pc9-stat-week"><i class="fas fa-calendar-week"></i></div>
            <div class="pc9-stat-content">
                <div class="pc9-stat-label">This Week</div>
                <div class="pc9-stat-value" id="pc9-stat-week">0</div>
            </div>
        </div>
        <div class="pc9-stat-card" title="Phone clicks this calendar month">
            <div class="pc9-stat-icon pc9-stat-month"><i class="fas fa-calendar-alt"></i></div>
            <div class="pc9-stat-content">
                <div class="pc9-stat-label">This Month</div>
                <div class="pc9-stat-value" id="pc9-stat-month">0</div>
            </div>
        </div>
        <div class="pc9-stat-card" title="Number of distinct customers who clicked your phone numbers">
            <div class="pc9-stat-icon pc9-stat-users"><i class="fas fa-users"></i></div>
            <div class="pc9-stat-content">
                <div class="pc9-stat-label">Unique Users</div>
                <div class="pc9-stat-value" id="pc9-stat-users">0</div>
            </div>
        </div>
        <div class="pc9-stat-card" title="Number of different products that received phone clicks">
            <div class="pc9-stat-icon pc9-stat-products"><i class="fas fa-box"></i></div>
            <div class="pc9-stat-content">
                <div class="pc9-stat-label">Unique Products</div>
                <div class="pc9-stat-value" id="pc9-stat-products">0</div>
            </div>
        </div>
    </div>

    <!-- Top Lists -->
    <div class="pc9-top-lists">
        <div class="pc9-top-list-card">
            <h3 title="Your products ranked by number of phone clicks"><i class="fas fa-trophy"></i> Top Products</h3>
            <div class="pc9-top-list" id="pc9-top-products">
                <div class="pc9-loading">Loading...</div>
            </div>
        </div>
        <?php if ( $is_admin ) : ?>
        <div class="pc9-top-list-card">
            <h3 title="Sellers whose products received the most phone clicks"><i class="fas fa-star"></i> Top Sellers</h3>
            <div class="pc9-top-list" id="pc9-top-sellers">
                <div class="pc9-loading">Loading...</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="pc9-contacts-filters">
        <div class="pc9-filter-group">
            <input type="text" id="pc9-contact-search" class="pc9-search-input"
                   placeholder="Search by user, product, or phone..."
                   title="Filter the table by customer name, product name, or phone number">
        </div>
        <div class="pc9-filter-group">
            <input type="date" id="pc9-date-from" class="pc9-filter-input" placeholder="From Date"
                   title="Show contacts on or after this date">
        </div>
        <div class="pc9-filter-group">
            <input type="date" id="pc9-date-to" class="pc9-filter-input" placeholder="To Date"
                   title="Show contacts on or before this date">
        </div>
        <div class="pc9-filter-group">
            <button type="button" id="pc9-filter-btn" class="pc9-btn pc9-btn-primary"
                    title="Apply current search and date filters to the table">
                <i class="fas fa-filter"></i> Filter
            </button>
            <button type="button" id="pc9-reset-filter-btn" class="pc9-btn pc9-btn-secondary"
                    title="Clear all filters and reload the full contact list">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
    </div>

    <!-- Contacts Table -->
    <div class="pc9-contacts-table-wrapper">
        <table class="pc9-contacts-table">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Customer</th>
                    <?php if ( $is_admin ) : ?><th>Seller</th><?php endif; ?>
                    <th>Product</th>
                    <th>Phone Number</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="pc9-contacts-tbody">
                <tr>
                    <td colspan="<?php echo $is_admin ? 6 : 5; ?>" class="pc9-loading-row">
                        <div class="pc9-loader"></div>
                        <span>Loading contacts...</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pc9-pagination" id="pc9-contacts-pagination"></div>

</div>

<!-- Message Modal -->
<div id="pc9-message-modal" class="pc9-modal">
    <div class="pc9-modal-content">
        <div class="pc9-modal-header">
            <h3><i class="fas fa-comment-dots"></i> Send Message</h3>
            <button type="button" class="pc9-modal-close" title="Close this dialog">&times;</button>
        </div>
        <div class="pc9-modal-body">
            <div class="pc9-message-info">
                <p><strong>Seller:</strong> <span id="pc9-msg-seller-name"></span></p>
                <p><strong>Product:</strong> <span id="pc9-msg-product-name"></span></p>
                <p class="p9-hint-text">
                    <i class="fas fa-info-circle"></i> Send a message about this phone inquiry. It will appear in the recipient's inbox.
                </p>
            </div>
            <div class="pc9-new-message">
                <h4>Your Message</h4>
                <textarea id="pc9-message-text" class="pc9-message-textarea"
                          placeholder="Type your message about this phone inquiry..."
                          rows="6"
                          title="Write the message you want to send about this phone inquiry"></textarea>
            </div>
        </div>
        <div class="pc9-modal-footer">
            <button type="button" class="pc9-btn pc9-btn-secondary pc9-modal-cancel"
                    title="Close without sending">Cancel</button>
            <button type="button" id="pc9-send-message-btn" class="pc9-btn pc9-btn-primary"
                    title="Send this message to the seller's inbox">
                <i class="fas fa-paper-plane"></i> Send Message
            </button>
        </div>
    </div>
</div>

<!-- Contact Details Modal -->
<div id="pc9-details-modal" class="pc9-modal">
    <div class="pc9-modal-content">
        <div class="pc9-modal-header">
            <h3><i class="fas fa-info-circle"></i> Contact Details</h3>
            <button type="button" class="pc9-modal-close" title="Close this panel">&times;</button>
        </div>
        <div class="pc9-modal-body" id="pc9-details-content"></div>
        <div class="pc9-modal-footer">
            <button type="button" class="pc9-btn pc9-btn-secondary pc9-modal-cancel"
                    title="Close the details view">Close</button>
        </div>
    </div>
</div>

<?php
$manager_id = ( $is_manager && ! $is_admin ) ? get_current_user_id() : 0;
?>
<script>
window.pc9PhoneContactsScope = {
    isAdmin:    <?php echo $is_admin ? 'true' : 'false'; ?>,
    isManager:  <?php echo $is_manager ? 'true' : 'false'; ?>,
    managerId:  <?php echo absint( $manager_id ); ?>,
    showSeller: <?php echo $is_admin ? 'true' : 'false'; ?>
};
</script>
