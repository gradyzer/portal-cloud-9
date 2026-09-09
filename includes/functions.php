<?php
/**
 * Portal Cloud 9 - Helper Functions
 *
 * @package Portal_Cloud_9
 */

defined('ABSPATH') || exit;


/**
 * Get products per page from settings.
 */
function portalcloud9_get_products_per_page()
{
    $options  = get_option( 'portalcloud9_options', [] );
    $per_page = isset( $options['products_per_page'] ) ? absint( $options['products_per_page'] ) : 15;
    return apply_filters( 'portalcloud9_products_per_page', $per_page );
}

/**
 * Get orders per page from settings.
 */
function portalcloud9_get_orders_per_page( $user_role = 'customer' )
{
    $default    = ( $user_role === 'customer' ) ? 10 : 15;
    $options    = get_option( 'portalcloud9_options', [] );
    $option_key = ( $user_role === 'customer' ) ? 'orders_per_page_customer' : 'orders_per_page_manager';
    $per_page   = isset( $options[ $option_key ] ) ? absint( $options[ $option_key ] ) : $default;
    return apply_filters( 'portalcloud9_orders_per_page', $per_page );
}

/* ------------------------------------------------------------------
 * Custom Avatar Filter - Only runs when getting avatars
 * This is safe as it only modifies avatar data when requested
 * ------------------------------------------------------------------ */
add_filter('pre_get_avatar_data', 'portalcloud9_custom_avatar', 10, 2);
function portalcloud9_custom_avatar($args, $id_or_email)
{
    $user_id = null;

    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif (is_object($id_or_email) && isset($id_or_email->user_id)) {
        $user_id = (int) $id_or_email->user_id;
    } elseif (is_object($id_or_email) && isset($id_or_email->ID)) {
        $user_id = (int) $id_or_email->ID;
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
        if ($user) {
            $user_id = $user->ID;
        }
    }

    if ($user_id) {
        $custom_avatar_id = get_user_meta($user_id, 'portcld9_custom_avatar', true);
        if ($custom_avatar_id) {
            $custom_avatar_url = wp_get_attachment_url($custom_avatar_id);
            if ($custom_avatar_url) {
                $args['url'] = $custom_avatar_url;
                $args['found_avatar'] = true;
            }
        }
    }

    return $args;
}

/* ------------------------------------------------------------------
 * Dashboard-Only Styles - Only output on dashboard pages
 * ------------------------------------------------------------------ */
add_action('wp_head', function () {
    // Strict check - only run on dashboard
    if (!function_exists('get_query_var') || !get_query_var('portalcloud9_dashboard')) {
        return;
    }
?>
<?php
}, 100); // Late priority to ensure query vars are set



/* ------------------------------------------------------------------
 * Favourites Page Renderer - Only called when needed
 * ------------------------------------------------------------------ */
function portalcloud9_render_favourites_page()
{
    $current_user = wp_get_current_user();

    if (in_array('administrator', $current_user->roles) || in_array('shop_manager', $current_user->roles)) {
        return '<div class="p9-error" style="padding:40px;text-align:center;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:12px;color:#ef4444;">
            <h2 style="margin:0 0 10px;">Access Denied</h2>
            <p>Favourites are available only for <strong>customers</strong>, <strong>sellers</strong>, and <strong>buyers</strong>.</p>
            <p>Administrators and shop managers do not have access to this feature.</p>
            <p><a href="' . esc_url(home_url('/user-portal/')) . '" style="color:#1e90ff;">Return to Dashboard</a></p>
        </div>';
    }

    if (!is_user_logged_in()) {
        return '<div class="p9-error">Please log in to view favourites.</div>';
    }

    if (!class_exists('PortalCloud9_Favourites')) {
        return '<div class="p9-error">Favourites feature is not available.</div>';
    }

    $favs = new PortalCloud9_Favourites();
    $products = $favs->get_user_favourites();
    $count = count($products);

    ob_start();
?>
    <div class="p9-favourites-wrapper">
        <div class="p9-favourites-header">
            <div>
                <h1 class="p9-favourites-title">My Favourites</h1>
                <p class="p9-favourites-count"><?php echo absint( $count ); ?> items</p>
            </div>
            <div class="p9-favourites-actions">
                <?php if ($count > 0): ?>
                    <button id="p9-clear-all-favourites" class="p9-clear-all-btn">
                        <span>Clear All</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($count === 0): ?>
            <div class="p9-empty-favourites">
                <div class="p9-empty-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
                    </svg>
                </div>
                <h2 class="p9-empty-title">No favourites yet</h2>
                <p class="p9-empty-message">Start adding products you love to see them here</p>
                <a href="<?php echo esc_url(function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/shop/')); ?>" class="p9-btn p9-btn-add">
                    Browse Products
                </a>
            </div>
        <?php else: ?>
            <div class="p9-favourites-grid">
                <?php foreach ($products as $product):
                    $stock_status = $product->get_stock_status();
                    $stock_class = 'p9-in-stock';
                    $stock_text = 'In Stock';

                    if ($stock_status === 'outofstock') {
                        $stock_class = 'p9-out-stock';
                        $stock_text = 'Out of Stock';
                    } elseif ($stock_status === 'onbackorder') {
                        $stock_class = 'p9-low-stock';
                        $stock_text = 'Low Stock';
                    }
                ?>
                    <div class="p9-favourite-card" data-product-id="<?php echo absint( $product->get_id() ); ?>">
                        <div class="p9-favourite-image">
                            <?php if ($product->is_on_sale()): ?>
                                <span class="p9-sale-badge">Sale</span>
                            <?php endif; ?>
                            <button class="p9-remove-fave" data-product-id="<?php echo absint( $product->get_id() ); ?>" aria-label="Remove from favourites"></button>
                            <?php echo wp_kses_post( $product->get_image('medium') ); ?>
                        </div>
                        <div class="p9-favourite-content">
                            <h3 class="p9-favourite-title">
                                <a href="<?php echo esc_url( $product->get_permalink() ); ?>">
                                    <?php echo esc_html($product->get_name()); ?>
                                </a>
                            </h3>

                            <?php if ($rating = $product->get_average_rating()): ?>
                                <div class="p9-product-rating">
                                    <span class="p9-rating-stars"><?php echo esc_html( str_repeat('★', round($rating)) ); ?></span>
                                    <span class="p9-rating-count">(<?php echo absint( $product->get_review_count() ); ?>)</span>
                                </div>
                            <?php endif; ?>

                            <div class="p9-favourite-price">
                                <?php if ($product->is_on_sale()): ?>
                                    <span class="p9-price-original"><?php echo esc_html( $product->get_regular_price() ); ?></span>
                                <?php endif; ?>
                                <?php echo wp_kses_post( $product->get_price_html() ); ?>
                            </div>

                            <span class="p9-stock-badge <?php echo esc_attr( $stock_class ); ?>">
                                <?php echo esc_html( $stock_text ); ?>
                            </span>

                            <div class="p9-favourite-actions">
                                <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="p9-btn p9-btn-view">
                                    View
                                </a>
                                <?php if ($product->is_in_stock()): ?>
                                    <button class="p9-btn p9-btn-add p9-add-to-cart" data-product-id="<?php echo absint( $product->get_id() ); ?>">
                                        Add to Cart
                                    </button>
                                <?php else: ?>
                                    <button class="p9-btn p9-btn-add" disabled>
                                        Out of Stock
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
}

/* ------------------------------------------------------------------ * reads options directly without using our helper functions
 * ------------------------------------------------------------------ */


/* ------------------------------------------------------------------
 * Phone Contacts - Admin Message to Seller
 * Sends message from admin to seller via the messaging system
 * ------------------------------------------------------------------ */
add_action('wp_ajax_portcld9_send_contact_message', 'portalcloud9_ajax_send_contact_message');
function portalcloud9_ajax_send_contact_message() {
    // Security checks
    check_ajax_referer('portalcloud9_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to send messages.');
    }
    
    // Get and validate inputs
    $seller_id = absint($_POST['seller_id'] ?? 0);
    $product_id = absint($_POST['product_id'] ?? 0);
    $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
    $admin_id = get_current_user_id();
    
    if (!$seller_id || !$product_id || !$message) {
        wp_send_json_error('Missing required information.');
    }
    
    // Verify product exists
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error('Product not found.');
    }
    
    // Verify seller exists
    $seller = get_userdata($seller_id);
    if (!$seller) {
        wp_send_json_error('Seller not found.');
    }
    
    // Get admin info
    $admin = get_userdata($admin_id);
    $admin_name = $admin ? $admin->display_name : 'Administrator';
    
    // Create the message post
    $message_title = sprintf(
        'Admin inquiry about: %s (Phone Contact Follow-up)',
        $product->get_name()
    );
    
    $post_id = wp_insert_post([
        'post_type' => 'portalcloud9_message',
        'post_title' => $message_title,
        'post_content' => $message,
        'post_status' => 'publish',
        'post_author' => $admin_id,
    ], true);
    
    if (is_wp_error($post_id)) {
        wp_send_json_error('Failed to create message: ' . $post_id->get_error_message());
    }
    
    // Add metadata
    update_post_meta($post_id, 'sender_id', $admin_id);
    update_post_meta($post_id, 'receiver_id', $seller_id);
    update_post_meta($post_id, 'product_id', $product_id);
    update_post_meta($post_id, 'is_read', '0');
    update_post_meta($post_id, 'is_guest', '0');
    update_post_meta($post_id, 'message_type', 'phone_contact_followup');
    
    // Send email notification to seller
    $seller_email = $seller->user_email;
    $product_title = $product->get_name();
    $subject = sprintf('[%s] Message from Administrator about: %s', get_bloginfo('name'), $product_title);
    
    $body = sprintf(
        "Hi %s,\n\n" .
        "You have received a message from %s regarding a phone contact inquiry about your product \"%s\".\n\n" .
        "Message:\n%s\n\n" .
        "View and reply in your inbox: %s\n\n" .
        "Thank you!",
        $seller->display_name,
        $admin_name,
        $product_title,
        wp_trim_words($message, 50),
        home_url('/user-portal/inbox/')
    );
    
    wp_mail($seller_email, $subject, $body);
    
    wp_send_json_success([
        'message' => 'Message sent successfully!',
        'message_id' => $post_id
    ]);
}

/* ------------------------------------------------------------------
 * LOGIN REDIRECT & ADMIN ACCESS RESTRICTIONS
 * Redirect users to Portal Cloud 9 dashboard after login
 * Restrict WP Admin access to Administrators only
 * ------------------------------------------------------------------ */

/**
 * Redirect users to Portal Cloud 9 dashboard after login
 * ALL users including administrators go to Portal Cloud 9
 */
add_filter('login_redirect', 'portalcloud9_login_redirect', 10, 3);
function portalcloud9_login_redirect($redirect_to, $request, $user) {
    // If user is not logged in or login failed, return default
    if (!isset($user->roles) || is_wp_error($user)) {
        return $redirect_to;
    }
    
    // ALL users (including administrators) go to Portal Cloud 9 dashboard
    return home_url('/user-portal/');
}

/**
 * Restrict WP Admin access to Administrators only
 * Allows AJAX requests to pass through for all users
 */
add_action('admin_init', 'portalcloud9_restrict_admin_access');
function portalcloud9_restrict_admin_access() {
    // Allow AJAX requests
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }
    
    // Get current user
    $user = wp_get_current_user();
    
    // If not logged in, WordPress will handle redirect to login
    if (!is_user_logged_in()) {
        return;
    }
    
    // Allow administrators full access
    if (in_array('administrator', $user->roles)) {
        return;
    }
    
    // Redirect all non-admin users to Portal Cloud 9 dashboard
    wp_safe_redirect(home_url('/user-portal/'));
    exit;
}

/**
 * Show admin bar for administrators, hide for others
 * Administrators can access WP Admin via the admin bar
 */
add_action('after_setup_theme', 'portalcloud9_hide_admin_bar');
function portalcloud9_hide_admin_bar() {
    // Show admin bar for administrators
    if (current_user_can('administrator')) {
        show_admin_bar(true);
    } else {
        // Hide for all other users
        show_admin_bar(false);
    }
}

/**
 * Customize admin bar for administrators
 * Keep essential WP Admin links in the admin bar
 */
add_action('wp_before_admin_bar_render', 'portalcloud9_customize_admin_bar_links');
function portalcloud9_customize_admin_bar_links() {
    global $wp_admin_bar;
    
    if (!current_user_can('administrator')) {
        // Remove all admin bar items for non-admins (shouldn't show anyway)
        $wp_admin_bar->remove_menu('dashboard');
        $wp_admin_bar->remove_menu('wp-logo');
    }
    // Administrators keep all admin bar functionality
}

/**
 * Safely output a tab icon that may be an emoji (text) or inline SVG (HTML).
 * Uses wp_kses() with an explicit SVG allowlist so both render correctly.
 *
 * @param string $icon Emoji character or inline SVG markup.
 * @return string Sanitized output safe for direct echo.
 */
function portcld9_esc_icon( $icon ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Prefixed.
    return wp_kses( $icon, [
        'svg'    => [ 'width' => [], 'height' => [], 'viewbox' => [], 'xmlns' => [], 'class' => [], 'aria-hidden' => [], 'focusable' => [] ],
        'path'   => [ 'fill' => [], 'd' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [] ],
        'circle' => [ 'cx' => [], 'cy' => [], 'r' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
        'rect'   => [ 'x' => [], 'y' => [], 'width' => [], 'height' => [], 'rx' => [], 'fill' => [] ],
        'g'      => [ 'fill' => [], 'stroke' => [], 'transform' => [] ],
    ] );
}
