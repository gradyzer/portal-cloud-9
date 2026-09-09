<?php
/**
 * Portal Cloud 9 - Cart AJAX Handler
 * Handles all cart-related AJAX operations
 * Version: 2.0.0
 */

defined('ABSPATH') || exit;

class PortalCloud9_Cart_Ajax {

    /**
     * Constructor - Register AJAX hooks
     */
    public function __construct() {
        // Update quantity
        add_action('wp_ajax_portcld9_update_cart_quantity', [$this, 'update_cart_quantity']);
        add_action('wp_ajax_nopriv_portcld9_update_cart_quantity', [$this, 'update_cart_quantity']);

        // Remove item
        add_action('wp_ajax_portcld9_remove_cart_item', [$this, 'remove_cart_item']);
        add_action('wp_ajax_nopriv_portcld9_remove_cart_item', [$this, 'remove_cart_item']);

        // Apply coupon
        add_action('wp_ajax_portcld9_apply_coupon', [$this, 'apply_coupon']);
        add_action('wp_ajax_nopriv_portcld9_apply_coupon', [$this, 'apply_coupon']);

        // Remove coupon
        add_action('wp_ajax_portcld9_remove_coupon', [$this, 'remove_coupon']);
        add_action('wp_ajax_nopriv_portcld9_remove_coupon', [$this, 'remove_coupon']);

        // Get cart data (for refreshing)
        add_action('wp_ajax_portcld9_get_cart_data', [$this, 'get_cart_data']);
        add_action('wp_ajax_nopriv_portcld9_get_cart_data', [$this, 'get_cart_data']);

        // Enqueue cart assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_cart_assets']);
    }

    /**
     * Enqueue cart-specific assets
     */
    public function enqueue_cart_assets() {
        // Only load on dashboard with cart tab
        if (!get_query_var('portalcloud9_dashboard')) {
            return;
        }

        $current_tab = get_query_var('portalcloud9_tab', 'overview');
        if ($current_tab !== 'cart') {
            return;
        }

        $version = defined('PORTCLD9_VERSION') ? PORTCLD9_VERSION : '2.0.0';
        $plugin_url = defined('PORTCLD9_PLUGIN_URL') ? PORTCLD9_PLUGIN_URL : plugin_dir_url(dirname(__FILE__));

        // Enqueue CSS
        wp_enqueue_style(
            'portalcloud9-cart',
            $plugin_url . 'assets/css/cart.css',
            ['portalcloud9-fontawesome'],
            $version
        );

        // Enqueue JS
        wp_enqueue_script(
            'portalcloud9-cart',
            $plugin_url . 'assets/js/cart.js',
            ['jquery'],
            $version,
            true
        );

        // Localize script
        // Use current domain to support any WordPress site
        
        
        wp_localize_script('portalcloud9-cart', 'portcld9_cart_params', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce('portcld9_cart_nonce'),
            'shop_url'    => get_permalink(wc_get_page_id('shop')),
            'cart_url'    => wc_get_cart_url(),
            'checkout_url'=> wc_get_checkout_url(),
            'currency'    => get_woocommerce_currency_symbol(),
            'i18n'        => [
                'removing'     => __('Removing...', 'portal-cloud-9'),
                'updating'     => __('Updating...', 'portal-cloud-9'),
                'error'        => __('An error occurred', 'portal-cloud-9'),
                'removed'      => __('Item removed', 'portal-cloud-9'),
                'updated'      => __('Cart updated', 'portal-cloud-9'),
            ],
        ]);
    }

    /**
     * Verify AJAX nonce
     */
    private function verify_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'portcld9_cart_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'portal-cloud-9')]);
        }
    }

    /**
     * Get standardized cart data response
     */
    private function get_cart_response_data() {
        WC()->cart->calculate_totals();
        
        return [
            'cart_count'    => WC()->cart->get_cart_contents_count(),
            'cart_subtotal' => WC()->cart->get_cart_subtotal(),
            'cart_total'    => WC()->cart->get_cart_total(),
            'cart_shipping' => WC()->cart->needs_shipping() ? WC()->cart->get_cart_shipping_total() : '',
            'cart_discount' => WC()->cart->get_discount_total() > 0 ? wc_price(WC()->cart->get_discount_total()) : '',
            'applied_coupons' => WC()->cart->get_applied_coupons(),
        ];
    }

    /**
     * Update cart item quantity
     */
    public function update_cart_quantity() {
        $this->verify_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_nonce() above.

        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;

        if (empty($cart_item_key)) {
            wp_send_json_error(['message' => __('Invalid cart item', 'portal-cloud-9')]);
        }

        // Get cart item
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        if (!$cart_item) {
            wp_send_json_error(['message' => __('Cart item not found', 'portal-cloud-9')]);
        }

        // Validate quantity against stock
        $_product = $cart_item['data'];
        if ($_product->managing_stock()) {
            $stock_quantity = $_product->get_stock_quantity();
            if ($quantity > $stock_quantity) {
                wp_send_json_error([
                    // translators: %d: Number of items available in stock
                    'message' => sprintf(__('Only %d available in stock', 'portal-cloud-9'), $stock_quantity)
                ]);
            }
        }

        // Update quantity
        $result = WC()->cart->set_quantity($cart_item_key, $quantity, true);

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to update quantity', 'portal-cloud-9')]);
        }

        // Get updated item subtotal
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        $item_subtotal = '';
        if ($cart_item) {
            $_product = $cart_item['data'];
            $item_subtotal = WC()->cart->get_product_subtotal($_product, $cart_item['quantity']);
        }

        // Response
        $response = $this->get_cart_response_data();
        $response['item_subtotal'] = $item_subtotal;

        wp_send_json_success($response);
    }

    /**
     * Remove cart item
     */
    public function remove_cart_item() {
        $this->verify_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_nonce() above.

        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';

        if (empty($cart_item_key)) {
            wp_send_json_error(['message' => __('Invalid cart item', 'portal-cloud-9')]);
        }

        // Remove item
        $result = WC()->cart->remove_cart_item($cart_item_key);

        if (!$result) {
            wp_send_json_error(['message' => __('Failed to remove item', 'portal-cloud-9')]);
        }

        wp_send_json_success($this->get_cart_response_data());
    }

    /**
     * Apply coupon
     */
    public function apply_coupon() {
        $this->verify_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_nonce() above.

        $coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';

        if (empty($coupon_code)) {
            wp_send_json_error(['message' => __('Please enter a coupon code', 'portal-cloud-9')]);
        }

        // Check if coupon is already applied
        if (WC()->cart->has_discount($coupon_code)) {
            wp_send_json_error(['message' => __('Coupon already applied', 'portal-cloud-9')]);
        }

        // Validate coupon
        $coupon = new WC_Coupon($coupon_code);
        $discounts = new WC_Discounts(WC()->cart);
        $valid = $discounts->is_coupon_valid($coupon);

        if (is_wp_error($valid)) {
            wp_send_json_error(['message' => $valid->get_error_message()]);
        }

        // Apply coupon
        $result = WC()->cart->apply_coupon($coupon_code);

        if (!$result) {
            $errors = wc_get_notices('error');
            $message = !empty($errors) ? wp_strip_all_tags($errors[0]['notice']) : __('Invalid coupon', 'portal-cloud-9');
            wc_clear_notices();
            wp_send_json_error(['message' => $message]);
        }

        wc_clear_notices();

        $response = $this->get_cart_response_data();
        // translators: %s: Coupon code that was applied
        $response['message'] = sprintf(__('Coupon "%s" applied successfully!', 'portal-cloud-9'), strtoupper($coupon_code));

        wp_send_json_success($response);
    }

    /**
     * Remove coupon
     */
    public function remove_coupon() {
        $this->verify_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_nonce() above.

        $coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '';

        if (empty($coupon_code)) {
            wp_send_json_error(['message' => __('Invalid coupon', 'portal-cloud-9')]);
        }

        WC()->cart->remove_coupon($coupon_code);
        wc_clear_notices();

        wp_send_json_success($this->get_cart_response_data());
    }

    /**
     * Get cart data (for manual refresh)
     */
    public function get_cart_data() {
        $this->verify_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_nonce() above.

        // Return full cart data including items
        $cart_items = [];
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $_product = $cart_item['data'];
            $cart_items[] = [
                'key'       => $cart_item_key,
                'product_id'=> $cart_item['product_id'],
                'name'      => $_product->get_name(),
                'quantity'  => $cart_item['quantity'],
                'price'     => WC()->cart->get_product_price($_product),
                'subtotal'  => WC()->cart->get_product_subtotal($_product, $cart_item['quantity']),
                'image'     => wp_get_attachment_image_url($_product->get_image_id(), 'thumbnail'),
                'permalink' => $_product->get_permalink(),
                'stock_qty' => $_product->get_stock_quantity(),
            ];
        }

        $response = $this->get_cart_response_data();
        $response['items'] = $cart_items;

        wp_send_json_success($response);
    }
}

// Initialize the class
new PortalCloud9_Cart_Ajax();
