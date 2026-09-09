<?php
/**
 * Portal Cloud 9 - Orders AJAX Handler
 * Handles all order-related AJAX operations
 * Version: 2.0.0
 */

defined('ABSPATH') || exit;

class PortalCloud9_Orders_Ajax {

    /**
     * Constructor - Register AJAX hooks
     */
    public function __construct() {
        // Get orders list
        add_action('wp_ajax_portcld9_get_orders', [$this, 'get_orders']);
        add_action('wp_ajax_nopriv_portcld9_get_orders', [$this, 'get_orders_guest']);

        // Get single order details
        add_action('wp_ajax_portcld9_get_order_details', [$this, 'get_order_details']);
        add_action('wp_ajax_portcld9_get_order', [$this, 'get_order_details']); // Alias for JavaScript compatibility

        // Get order details for customer (My Orders modal)
        add_action('wp_ajax_portcld9_get_my_order_details', [$this, 'get_my_order_details']);

        // Reorder items
        add_action('wp_ajax_portcld9_reorder_items', [$this, 'reorder_items']);

        // Update order
        add_action('wp_ajax_portcld9_update_order', [$this, 'update_order']);

        // Delete order
        add_action('wp_ajax_portcld9_delete_order', [$this, 'delete_order']);

        // Order actions (email, refund, delete, etc.)
        add_action('wp_ajax_portcld9_order_action', [$this, 'handle_order_action']);

        // Add order note
        add_action('wp_ajax_portcld9_add_order_note', [$this, 'add_order_note']);
        
        // Delete order note
        add_action('wp_ajax_portcld9_delete_order_note', [$this, 'delete_order_note']);


        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue orders assets
     */
    public function enqueue_assets() {
        if (!get_query_var('portalcloud9_dashboard')) {
            return;
        }

        $current_tab = get_query_var('portalcloud9_tab', 'overview');
        if ($current_tab !== 'orders') {
            return;
        }

        $version = defined('PORTCLD9_VERSION') ? PORTCLD9_VERSION : '2.0.0';
        $plugin_url = defined('PORTCLD9_PLUGIN_URL') ? PORTCLD9_PLUGIN_URL : plugin_dir_url(dirname(__FILE__));

        // Enqueue CSS
        wp_enqueue_style(
            'portalcloud9-orders',
            $plugin_url . 'assets/css/orders.css',
            ['portalcloud9-fontawesome'],
            $version
        );

        // Enqueue JS
        wp_enqueue_script(
            'portalcloud9-orders',
            $plugin_url . 'assets/js/orders.js',
            ['jquery'],
            $version,
            true
        );

        // Localize script
        $p9_options = get_option('portalcloud9_options', []);
        $orders_per_page_manager = isset($p9_options['orders_per_page_manager']) ? absint($p9_options['orders_per_page_manager']) : 15;
        $orders_per_page_customer = isset($p9_options['orders_per_page_customer']) ? absint($p9_options['orders_per_page_customer']) : 10;
        
        // Use current domain to support any WordPress site
        
        
        wp_localize_script('portalcloud9-orders', 'portcld9_orders_params', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce('portcld9_orders_nonce'),
            'per_page'   => current_user_can('edit_shop_orders') ? $orders_per_page_manager : $orders_per_page_customer,
            'per_page_manager' => $orders_per_page_manager,
            'per_page_customer' => $orders_per_page_customer,
            'can_manage' => current_user_can('edit_shop_orders'),
            'i18n'       => [
                'loading'      => __('Loading...', 'portal-cloud-9'),
                'error'        => __('An error occurred', 'portal-cloud-9'),
                'confirm_delete' => __('Are you sure you want to delete this order?', 'portal-cloud-9'),
                'confirm_refund' => __('Are you sure you want to refund this order?', 'portal-cloud-9'),
            ],
        ]);

        // Pass modal-specific URL data so my-orders-modal.php no longer needs inline scripts
        wp_localize_script('portalcloud9-orders', 'portcld9_orders_modal_data', [
            'inbox_url' => class_exists('PortalCloud9_Config')
                           ? PortalCloud9_Config::get_dashboard_tab_url('inbox')
                           : home_url('/user-portal/inbox/'),
            'cart_url'  => function_exists('wc_get_cart_url') ? esc_url( wc_get_cart_url() ) : '',
        ]);
    }

    /**
     * Verify AJAX nonce
     */
    private function verify_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portcld9_orders_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'portal-cloud-9')]);
        }
    }

    /**
     * Check if user can manage orders
     */
    private function can_manage_orders() {
        return current_user_can('edit_shop_orders');
    }

    /**
     * Get orders list (for authenticated users)
     */
    public function get_orders() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        $search   = isset($_POST['search']) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        $status   = isset($_POST['status']) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        $source   = isset($_POST['source']) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        $page     = isset($_POST['page']) ? absint($_POST['page']) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        // DEBUG: Log search request
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($search)) {
        }
        
        // Get per_page from settings based on user role
        $p9_options = get_option('portalcloud9_options', []);
        $default_per_page = $this->can_manage_orders() 
            ? (isset($p9_options['orders_per_page_manager']) ? absint($p9_options['orders_per_page_manager']) : 15)
            : (isset($p9_options['orders_per_page_customer']) ? absint($p9_options['orders_per_page_customer']) : 10);
        
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : $default_per_page; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.

        $args = [
            'limit'    => $per_page,
            'page'     => $page,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
            'type'     => 'shop_order', // Exclude refunds - they don't have get_order_number()
        ];

        // Filter by status
        if (!empty($status)) {
            $args['status'] = $status;
        }

        // If not admin, only show user's orders
        if (!$this->can_manage_orders()) {
            $args['customer'] = get_current_user_id();
        }

        // Search
        if (!empty($search)) {
            // WooCommerce doesn't have built-in search, so we need custom query
            $args = $this->add_search_to_args($args, $search);
        }

        // Filter by source (custom meta)
        if (!empty($source)) {
            $args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for custom order source filtering.
                [
                    'key'     => '_p9_order_source',
                    'value'   => $source,
                    'compare' => '=',
                ],
            ];
        }

        $orders_query = wc_get_orders($args);
        $orders = [];

        foreach ($orders_query->orders as $order) {
            $orders[] = $this->format_order_for_response($order);
        }

        // Get stats
        $stats = portalcloud9_get_order_stats($this->can_manage_orders() ? null : get_current_user_id());

        wp_send_json_success([
            'orders'     => $orders,
            'pagination' => [
                'current_page' => $page,
                'total_pages'  => $orders_query->max_num_pages,
                'total_orders' => $orders_query->total,
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Get orders for guest users (redirect to login)
     */
    public function get_orders_guest() {
        wp_send_json_error(['message' => __('Please log in to view orders', 'portal-cloud-9')]);
    }

    /**
     * Add comprehensive search functionality to order query with role-based filtering
     * Searches: Order ID, Product ID, SKU, Product Name, Customer Name, Email, Status, Date
     * Supports: "ID: 123", "id:123", "#123", "123" formats
     * Access Control:
     * - Administrator: All orders
     * - Shop Manager/Editor/Author: Orders with their products
     * - Customer/Subscriber: Only their own orders
     */
    private function add_search_to_args($args, $search) {
        global $wpdb;
        
        $search_term = '%' . $wpdb->esc_like($search) . '%';
        $order_ids = [];
        $current_user_id = get_current_user_id();
        
        // Clean search term - remove "ID:", "id:", "#" prefixes
        $clean_search = preg_replace('/^(id:|ID:|#)\s*/', '', trim($search));
        $clean_search_term = '%' . $wpdb->esc_like($clean_search) . '%';
        
        // 1. Search by Order ID (exact or partial match) - supports multiple formats
        if (is_numeric($clean_search) || preg_match('/^(id:|ID:|#)?\s*\d+$/', $search)) {
            $id_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type IN ('shop_order', 'shop_order_placehold')
                AND (ID = %d OR ID LIKE %s)",
                intval($clean_search),
                $clean_search_term
            ));
            $order_ids = array_merge($order_ids, $id_results);
        }
        
        // 2. Search by Product Name, Product ID, or SKU (in order items)
        $product_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
            "SELECT DISTINCT order_items.order_id 
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as itemmeta 
                ON order_items.order_item_id = itemmeta.order_item_id
            LEFT JOIN {$wpdb->posts} as products 
                ON itemmeta.meta_value = products.ID AND itemmeta.meta_key = '_product_id'
            LEFT JOIN {$wpdb->postmeta} as product_meta 
                ON products.ID = product_meta.post_id AND product_meta.meta_key = '_sku'
            WHERE order_items.order_item_type = 'line_item'
            AND (
                order_items.order_item_name LIKE %s
                OR product_meta.meta_value LIKE %s
                OR (itemmeta.meta_key = '_product_id' AND itemmeta.meta_value LIKE %s)
                OR (itemmeta.meta_key = '_product_id' AND itemmeta.meta_value = %s)
            )",
            $search_term,
            $search_term,
            $search_term,
            $clean_search
        ));
        $order_ids = array_merge($order_ids, $product_results);
        
        // 3. Search by Customer Name (billing info) - HPOS Compatible
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            // HPOS enabled - search in wc_orders_meta
            $customer_name_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT DISTINCT order_id 
                FROM {$wpdb->prefix}wc_orders_meta
                WHERE meta_key IN ('_billing_first_name', '_billing_last_name', '_billing_company')
                AND meta_value LIKE %s",
                $search_term
            ));
        } else {
            // Legacy - search in postmeta
            $customer_name_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT DISTINCT post_id 
                FROM {$wpdb->postmeta}
                WHERE meta_key IN ('_billing_first_name', '_billing_last_name', '_billing_company')
                AND meta_value LIKE %s",
                $search_term
            ));
        }
        $order_ids = array_merge($order_ids, $customer_name_results);
        
        // 4. Search by Customer Email - HPOS Compatible
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $customer_email_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT DISTINCT order_id 
                FROM {$wpdb->prefix}wc_orders_meta
                WHERE meta_key = '_billing_email'
                AND meta_value LIKE %s",
                $search_term
            ));
        } else {
            $customer_email_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT DISTINCT post_id 
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_billing_email'
                AND meta_value LIKE %s",
                $search_term
            ));
        }
        $order_ids = array_merge($order_ids, $customer_email_results);
        
        // 5. Search by Customer Phone - HPOS Compatible
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $customer_phone_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT DISTINCT order_id 
                FROM {$wpdb->prefix}wc_orders_meta
                WHERE meta_key = '_billing_phone'
                AND meta_value LIKE %s",
                $search_term
            ));
        } else {
            $customer_phone_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT DISTINCT post_id 
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_billing_phone'
                AND meta_value LIKE %s",
                $search_term
            ));
        }
        $order_ids = array_merge($order_ids, $customer_phone_results);
        
        // 6. Search by Order Status (partial match)
        $status_map = wc_get_order_statuses();
        foreach ($status_map as $status_key => $status_label) {
            if (stripos($status_label, $search) !== false || stripos($status_key, $search) !== false) {
                $status_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                    "SELECT ID FROM {$wpdb->posts}
                    WHERE post_type IN ('shop_order', 'shop_order_placehold')
                    AND post_status = %s",
                    $status_key
                ));
                $order_ids = array_merge($order_ids, $status_results);
            }
        }
        
        // 7. Search by Date (if search looks like a date)
        if (preg_match('/\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $search) || preg_match('/\d{1,2}[-\/]\d{1,2}[-\/]\d{4}/', $search)) {
            $date_results = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ('shop_order', 'shop_order_placehold')
                AND post_date LIKE %s",
                $search_term
            ));
            $order_ids = array_merge($order_ids, $date_results);
        }
        
        // Remove duplicates
        $order_ids = array_unique($order_ids);
        
        // DEBUG: Log search results
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($search)) {
        }
        
        // Apply role-based filtering
        if (!empty($order_ids)) {
            $order_ids = $this->filter_orders_by_role($order_ids, $current_user_id);
            
            if (defined('WP_DEBUG') && WP_DEBUG && !empty($search)) {
            }
        }
        
        if (!empty($order_ids)) {
            $args['post__in'] = $order_ids;
        } else {
            // No results
            $args['post__in'] = [0];
        }

        return $args;
    }
    
    /**
     * Filter order IDs based on user role permissions
     * 
     * @param array $order_ids Array of order IDs to filter
     * @param int $user_id Current user ID
     * @return array Filtered order IDs based on user permissions
     */
    private function filter_orders_by_role($order_ids, $user_id) {
        global $wpdb;
        
        $user = wp_get_current_user();
        
        // Administrator can see all orders
        if (in_array('administrator', $user->roles)) {
            return $order_ids;
        }
        
        // Shop Manager, Editor, Author - see orders containing their products
        if (array_intersect(['shop_manager', 'editor', 'author'], $user->roles)) {
            // Get products authored by this user
            $user_products = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; no suitable WP API available.
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'product'
                AND post_author = %d
                AND post_status = 'publish'",
                $user_id
            ));
            
            if (empty($user_products)) {
                return []; // No products, no orders
            }
            
            // Filter orders that contain at least one of their products
            $filtered_order_ids = [];
            foreach ($order_ids as $order_id) {
                // Sanitize product IDs
                $user_products_safe = array_map('intval', $user_products);
                
                // Create placeholders for wpdb::prepare
                $placeholders = implode(', ', array_fill(0, count($user_products_safe), '%d'));
                
                // Prepare values array - order_id first, then all product IDs
                $prepare_values = array_merge(array($order_id), $user_products_safe);
                
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $has_user_product = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
                    "SELECT COUNT(*) 
                    FROM {$wpdb->prefix}woocommerce_order_items as order_items
                    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as itemmeta 
                        ON order_items.order_item_id = itemmeta.order_item_id
                    WHERE order_items.order_id = %d
                    AND order_items.order_item_type = 'line_item'
                    AND itemmeta.meta_key = '_product_id'
                    AND itemmeta.meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders array built with %s substitution.
                    ...$prepare_values
                ));
                
                if ($has_user_product > 0) {
                    $filtered_order_ids[] = $order_id;
                }
            }
            
            return $filtered_order_ids;
        }
        
        // Customer, Subscriber - only their own orders
        if (array_intersect(['customer', 'subscriber'], $user->roles) || empty($user->roles)) {
            $filtered_order_ids = [];
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if ($order && $order->get_customer_id() == $user_id) {
                    $filtered_order_ids[] = $order_id;
                }
            }
            
            return $filtered_order_ids;
        }
        
        // Default: no access
        return [];
    }

    /**
     * Format order data for JSON response
     */
    private function format_order_for_response($order) {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $image_url = '';
            
            if ($product) {
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
            }

            $items[] = [
                'id'         => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'total'      => wc_price($item->get_total()),
                'sku'        => $product ? $product->get_sku() : '',
                'image'      => $image_url ?: wc_placeholder_img_src('thumbnail'),
            ];
        }

        $source = portalcloud9_get_order_source($order);
        $customer_name = $order->get_formatted_billing_full_name() ?: __('Guest', 'portal-cloud-9');

        return [
            'id'           => $order->get_id(),
            'number'       => $order->get_order_number(),
            'status'       => 'wc-' . $order->get_status(), // Include wc- prefix for consistency
            'status_label' => wc_get_order_status_name($order->get_status()),
            'date_created' => $order->get_date_created()->date_i18n(get_option('date_format')),
            'date'         => $order->get_date_created()->date_i18n(get_option('date_format')), // Flattened for list view
            'total'        => $order->get_formatted_order_total(),
            'customer_name'=> $customer_name, // Flattened for list view
            'item_count'   => count($items), // Flattened for list view
            'customer'     => [
                'id'     => $order->get_customer_id(),
                'name'   => $customer_name,
                'email'  => $order->get_billing_email(),
                'avatar' => get_avatar_url($order->get_billing_email(), ['size' => 72]),
            ],
            'items'        => $items,
            'source'       => $source,
            'source_label' => portalcloud9_get_source_label($source),
            'admin_url'    => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
        ];
    }

    /**
     * Get single order details
     */
    public function get_order_details() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!$this->can_manage_orders()) {
            wp_send_json_error(['message' => __('Permission denied', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        // Get detailed order data
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $image_url = '';
            
            if ($product) {
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
            }

            $items[] = [
                'id'         => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'subtotal'   => wc_price($item->get_subtotal()),
                'total'      => wc_price($item->get_total()),
                'sku'        => $product ? $product->get_sku() : '',
                'image'      => $image_url ?: wc_placeholder_img_src('thumbnail'),
            ];
        }

        $data = [
            'id'              => $order->get_id(),
            'number'          => $order->get_order_number(),
            'status'          => 'wc-' . $order->get_status(),
            'date_created'    => $order->get_date_created()->date_i18n('Y-m-d H:i'),
            'items'           => $items,
            'subtotal'        => wc_price($order->get_subtotal()),
            'shipping_total'  => wc_price($order->get_shipping_total()),
            'discount_total'  => wc_price($order->get_discount_total()),
            'total'           => $order->get_formatted_order_total(),
            'payment_method'  => $order->get_payment_method_title(),
            'customer'        => [
                'id'    => $order->get_customer_id(),
                'name'  => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
            ],
            'billing'         => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'state'      => $order->get_billing_state(),
            ],
            'shipping'        => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
                'state'      => $order->get_shipping_state(),
            ],
            'customer_note'   => $order->get_customer_note(),
            'notes_html'      => $this->get_order_notes_html($order),
        ];

        // Wrap in 'order' key to match JavaScript expectations
        wp_send_json_success(['order' => $data]);
    }

    /**
     * Get order details for customer (My Orders modal)
     */
    public function get_my_order_details() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Please log in to view order details', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        // Verify the order belongs to current user (unless admin)
        if (!current_user_can('edit_shop_orders') && $order->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not have permission to view this order', 'portal-cloud-9')]);
        }

        // Build items array
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $image_url = '';
            
            if ($product) {
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
            }

            $items[] = [
                'id'         => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'subtotal'   => wc_price($item->get_subtotal()),
                'total'      => wc_price($item->get_total()),
                'sku'        => $product ? $product->get_sku() : '',
                'image'      => $image_url ?: wc_placeholder_img_src('thumbnail'),
            ];
        }

        // Get customer notes (notes visible to customer)
        $notes = [];
        $order_notes = wc_get_order_notes([
            'order_id' => $order->get_id(),
            'type'     => 'customer',
        ]);
        
        foreach ($order_notes as $note) {
            $notes[] = [
                'date'    => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($note->date_created)),
                'content' => wp_strip_all_tags($note->content),
            ];
        }

        // Get shipping method
        $shipping_methods = $order->get_shipping_methods();
        $shipping_method = '';
        if (!empty($shipping_methods)) {
            $first_method = reset($shipping_methods);
            $shipping_method = $first_method->get_method_title();
        }

        $data = [
            'id'              => $order->get_id(),
            'number'          => $order->get_order_number(),
            'status'          => $order->get_status(),
            'status_label'    => wc_get_order_status_name($order->get_status()),
            'date_created'    => $order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'items'           => $items,
            'subtotal'        => wc_price($order->get_subtotal()),
            'shipping_total'  => wc_price($order->get_shipping_total()),
            'discount_total'  => wc_price($order->get_discount_total()),
            'total'           => $order->get_formatted_order_total(),
            'payment_method'  => $order->get_payment_method_title(),
            'shipping_method' => $shipping_method,
            'billing'         => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => WC()->countries->countries[$order->get_billing_country()] ?? $order->get_billing_country(),
                'state'      => $order->get_billing_state(),
            ],
            'shipping'        => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => WC()->countries->countries[$order->get_shipping_country()] ?? $order->get_shipping_country(),
                'state'      => $order->get_shipping_state(),
            ],
            'notes'           => $notes,
        ];

        wp_send_json_success($data);
    }

    /**
     * Reorder items - add items from a previous order to cart
     */
    public function reorder_items() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Please log in to reorder', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        // Verify the order belongs to current user
        if ($order->get_customer_id() !== get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not have permission to reorder this order', 'portal-cloud-9')]);
        }

        $added_count = 0;
        $failed_items = [];

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity = $item->get_quantity();
            
            $product = wc_get_product($variation_id ? $variation_id : $product_id);
            
            if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                $failed_items[] = $item->get_name();
                continue;
            }

            $cart_item_key = WC()->cart->add_to_cart(
                $product_id,
                $quantity,
                $variation_id,
                $variation_id ? wc_get_product_variation_attributes($variation_id) : []
            );

            if ($cart_item_key) {
                $added_count++;
            } else {
                $failed_items[] = $item->get_name();
            }
        }

        if ($added_count === 0) {
            wp_send_json_error(['message' => __('Could not add any items to cart. They may be out of stock.', 'portal-cloud-9')]);
        }

        // translators: %d: Number of items added to cart
        $message = sprintf(_n('%d item added to cart', '%d items added to cart', $added_count, 'portal-cloud-9'), $added_count);
        
        if (!empty($failed_items)) {
            // translators: %s: Comma-separated list of product names that could not be added
            $message .= '. ' . sprintf(__('Some items could not be added: %s', 'portal-cloud-9'), implode(', ', $failed_items));
        }

        wp_send_json_success([
            'message'  => $message,
            'cart_url' => wc_get_cart_url(),
            'added'    => $added_count,
            'failed'   => $failed_items,
        ]);
    }

    /**
     * Update order
     */
    public function update_order() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!$this->can_manage_orders()) {
            wp_send_json_error(['message' => __('Permission denied', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        // Update status
        if (isset($_POST['status'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
            $new_status = sanitize_text_field( wp_unslash( $_POST['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
            $order->update_status($new_status, __('Status updated via Portal Cloud 9', 'portal-cloud-9'));
        }

        // Update billing details
        $billing_fields = [
            'billing_first_name' => 'set_billing_first_name',
            'billing_last_name'  => 'set_billing_last_name',
            'billing_email'      => 'set_billing_email',
            'billing_phone'      => 'set_billing_phone',
            'billing_address'    => 'set_billing_address_1',
            'billing_city'       => 'set_billing_city',
            'billing_postcode'   => 'set_billing_postcode',
            'billing_country'    => 'set_billing_country',
        ];

        foreach ($billing_fields as $post_key => $method) {
            if (isset($_POST[$post_key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
                $value = sanitize_text_field( wp_unslash( $_POST[$post_key] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
                if (method_exists($order, $method)) {
                    $order->$method($value);
                }
            }
        }

        // Add order note
        if (!empty($_POST['order_note'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
            $note = sanitize_textarea_field( wp_unslash( $_POST['order_note'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
            $order->add_order_note($note, false, false);
        }

        $order->save();

        wp_send_json_success([
            'message' => __('Order updated successfully', 'portal-cloud-9'),
            'order'   => $this->format_order_for_response($order),
        ]);
    }

    /**
     * Delete order
     * Permanently deletes an order from WooCommerce
     */
    public function delete_order() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!$this->can_manage_orders()) {
            wp_send_json_error(['message' => __('Permission denied', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        // Permanently delete the order
        $deleted = $order->delete(true);

        if ($deleted) {
            wp_send_json_success([
                'message' => __('Order deleted successfully', 'portal-cloud-9'),
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to delete order', 'portal-cloud-9'),
            ]);
        }
    }

    /**
     * Handle order actions
     */
    public function handle_order_action() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!$this->can_manage_orders()) {
            wp_send_json_error(['message' => __('Permission denied', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        $action   = isset($_POST['order_action']) ? sanitize_text_field( wp_unslash( $_POST['order_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        if (!$order_id || !$action) {
            wp_send_json_error(['message' => __('Invalid request', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        switch ($action) {
            case 'send_order_details':
                // Send order details to customer (customer invoice email)
                WC()->mailer()->customer_invoice($order);
                wp_send_json_success(['message' => __('Order details sent to customer', 'portal-cloud-9')]);
                break;

            case 'send_order_details_admin':
                // Resend new order notification to admin
                WC()->mailer()->emails['WC_Email_New_Order']->trigger($order_id, $order);
                wp_send_json_success(['message' => __('New order notification resent', 'portal-cloud-9')]);
                break;

            case 'regenerate_download_permissions':
                // Regenerate download permissions
                $data_store = WC_Data_Store::load('customer-download');
                $data_store->delete_by_order_id($order_id);
                wc_downloadable_product_permissions($order_id, true);
                wp_send_json_success(['message' => __('Download permissions regenerated', 'portal-cloud-9')]);
                break;

            // Legacy actions for backward compatibility
            case 'email_invoice':
                WC()->mailer()->customer_invoice($order);
                wp_send_json_success(['message' => __('Invoice email sent', 'portal-cloud-9')]);
                break;

            case 'regenerate_download':
                $data_store = WC_Data_Store::load('customer-download');
                $data_store->delete_by_order_id($order_id);
                wc_downloadable_product_permissions($order_id, true);
                wp_send_json_success(['message' => __('Download permissions regenerated', 'portal-cloud-9')]);
                break;

            case 'refund':
                $order->update_status('refunded', __('Order refunded via Portal Cloud 9', 'portal-cloud-9'));
                wp_send_json_success(['message' => __('Order marked as refunded', 'portal-cloud-9')]);
                break;

            case 'delete':
                $order->delete();
                wp_send_json_success(['message' => __('Order deleted', 'portal-cloud-9')]);
                break;

            default:
                wp_send_json_error(['message' => __('Unknown action', 'portal-cloud-9')]);
        }
    }

    /**
     * Add order note via AJAX
     */
    public function add_order_note() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!$this->can_manage_orders()) {
            wp_send_json_error(['message' => __('Permission denied', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        $note     = isset($_POST['note']) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        $note_type = isset($_POST['note_type']) ? sanitize_text_field( wp_unslash( $_POST['note_type'] ) ) : 'private'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        // Determine if customer note based on note_type
        $is_customer_note = ($note_type === 'customer') ? 1 : 0;
        
        if (!$order_id || empty($note)) {
            wp_send_json_error(['message' => __('Invalid request', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        // Add the note
        $comment_id = $order->add_order_note($note, $is_customer_note, true);

        if (!$comment_id) {
            wp_send_json_error(['message' => __('Failed to add note', 'portal-cloud-9')]);
        }

        // Get updated notes HTML
        $notes_html = $this->get_order_notes_html($order);

        wp_send_json_success([
            'message'    => __('Note added successfully', 'portal-cloud-9'),
            'notes_html' => $notes_html,
        ]);
    }


    /**
     * Delete order note
     */
    public function delete_order_note() {
        check_ajax_referer( 'portcld9_orders_nonce', 'nonce' );

        if (!$this->can_manage_orders()) {
            wp_send_json_error(['message' => __('Permission denied', 'portal-cloud-9')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        $note_id  = isset($_POST['note_id']) ? absint($_POST['note_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() earlier in this method.
        
        if (!$order_id || !$note_id) {
            wp_send_json_error(['message' => __('Invalid request', 'portal-cloud-9')]);
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'portal-cloud-9')]);
        }

        // Delete the note (WooCommerce order notes are WordPress comments)
        $deleted = wp_delete_comment($note_id, true);

        if (!$deleted) {
            wp_send_json_error(['message' => __('Failed to delete note', 'portal-cloud-9')]);
        }

        // Get updated notes HTML
        $notes_html = $this->get_order_notes_html($order);

        wp_send_json_success([
            'message'    => __('Note deleted successfully', 'portal-cloud-9'),
            'notes_html' => $notes_html,
        ]);
    }

    /**
     * Get order notes as HTML
     */
    private function get_order_notes_html($order) {
        $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
        
        if (empty($notes)) {
            return '<div class="p9-order-notes-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <p>' . esc_html__('No order notes yet.', 'portal-cloud-9') . '</p>
            </div>';
        }

        $html = '';
        foreach ($notes as $note) {
            $note_type_class = '';
            $note_type_label = '';
            $note_type_badge = '';

            if ($note->customer_note) {
                $note_type_class = 'p9-note-customer';
                $note_type_label = __('Note to customer', 'portal-cloud-9');
                $note_type_badge = 'p9-note-type-customer';
            } elseif ($note->added_by === 'system') {
                $note_type_class = 'p9-note-system';
                $note_type_label = __('System', 'portal-cloud-9');
                $note_type_badge = 'p9-note-type-system';
            } else {
                $note_type_label = __('Private note', 'portal-cloud-9');
                $note_type_badge = 'p9-note-type-private';
            }

            $html .= '<div class="p9-order-note-item ' . esc_attr($note_type_class) . '">
                <div class="p9-order-note-meta">
                    <span class="p9-order-note-type ' . esc_attr($note_type_badge) . '">' . esc_html($note_type_label) . '</span>
                    <span class="p9-order-note-date">' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($note->date_created))) . '</span>
                    <div class="p9-order-note-actions">
                        <button type="button" class="p9-delete-note-btn" data-note-id="' . esc_attr($note->id) . '" title="' . esc_attr__('Delete note', 'portal-cloud-9') . '">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                <line x1="10" y1="11" x2="10" y2="17"/>
                                <line x1="14" y1="11" x2="14" y2="17"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="p9-order-note-content">' . wp_kses_post(wpautop(wptexturize($note->content))) . '</div>
            </div>';
        }

        return $html;
    }
}

// Initialize
new PortalCloud9_Orders_Ajax();

/* ============================================
   Helper Functions for Orders
   ============================================ */

if (!function_exists('portalcloud9_get_order_stats')) {
    /**
     * Get order statistics
     */
    function portalcloud9_get_order_stats($customer_id = null) {
        $args = ['limit' => -1, 'return' => 'ids', 'type' => 'shop_order'];
        
        if ($customer_id) {
            $args['customer'] = $customer_id;
        }

        // Total orders
        $total = count(wc_get_orders($args));

        // Pending
        $args['status'] = 'pending';
        $pending = count(wc_get_orders($args));

        // Processing
        $args['status'] = 'processing';
        $processing = count(wc_get_orders($args));

        // Completed
        $args['status'] = 'completed';
        $completed = count(wc_get_orders($args));

        // Revenue (from completed orders)
        $revenue = 0;
        $revenue_args = [
            'status' => ['completed', 'processing'],
            'limit'  => -1,
            'type'   => 'shop_order', // Exclude refunds
        ];
        if ($customer_id) {
            $revenue_args['customer'] = $customer_id;
        }
        
        $revenue_orders = wc_get_orders($revenue_args);
        foreach ($revenue_orders as $order) {
            $revenue += $order->get_total();
        }

        return [
            'total'      => $total,
            'pending'    => $pending,
            'processing' => $processing,
            'completed'  => $completed,
            'revenue'    => $revenue,
        ];
    }
}

if (!function_exists('portalcloud9_get_order_source')) {
    /**
     * Get order source/origin
     */
    function portalcloud9_get_order_source($order) {
        // Check for custom meta
        $source = $order->get_meta('_p9_order_source');
        if ($source) {
            return $source;
        }

        // Check for UTM parameters or referrer stored in order meta
        $utm_source = $order->get_meta('_wc_order_attribution_utm_source');
        if ($utm_source) {
            $source_map = [
                'tiktok'    => 'tiktok',
                'instagram' => 'instagram',
                'facebook'  => 'facebook',
                'fb'        => 'facebook',
                'google'    => 'google',
                'email'     => 'email',
                'newsletter'=> 'email',
            ];
            
            foreach ($source_map as $key => $value) {
                if (stripos($utm_source, $key) !== false) {
                    return $value;
                }
            }
            
            return 'organic';
        }

        // Check referrer
        $referrer = $order->get_meta('_wc_order_attribution_referrer');
        if ($referrer) {
            if (strpos($referrer, 'tiktok') !== false) return 'tiktok';
            if (strpos($referrer, 'instagram') !== false) return 'instagram';
            if (strpos($referrer, 'facebook') !== false) return 'facebook';
            if (strpos($referrer, 'google') !== false) return 'organic';
        }

        // Default
        return 'direct';
    }
}

if (!function_exists('portalcloud9_get_source_label')) {
    /**
     * Get source label
     */
    function portalcloud9_get_source_label($source) {
        $labels = [
            'tiktok'    => __('TikTok', 'portal-cloud-9'),
            'instagram' => __('Instagram', 'portal-cloud-9'),
            'facebook'  => __('Facebook', 'portal-cloud-9'),
            'google'    => __('Google', 'portal-cloud-9'),
            'organic'   => __('Organic', 'portal-cloud-9'),
            'direct'    => __('Direct', 'portal-cloud-9'),
            'email'     => __('Email', 'portal-cloud-9'),
            'affiliate' => __('Affiliate', 'portal-cloud-9'),
        ];
        
        return $labels[$source] ?? __('Direct', 'portal-cloud-9');
    }
}

if (!function_exists('portalcloud9_get_source_icon')) {
    /**
     * Get source icon SVG
     */
    function portalcloud9_get_source_icon($source) {
        $icons = [
            'tiktok'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/></svg>',
            'instagram' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
            'facebook'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'google'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>',
            'organic'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
            'direct'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            'email'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            'affiliate' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        ];
        
        return $icons[$source] ?? $icons['direct'];
    }
}

if (!function_exists('portalcloud9_format_order_data')) {
    /**
     * Format order data for template use
     */
    function portalcloud9_format_order_data($order) {
        return [
            'id'           => $order->get_id(),
            'number'       => $order->get_order_number(),
            'status'       => $order->get_status(),
            'status_label' => wc_get_order_status_name($order->get_status()),
            'total'        => $order->get_formatted_order_total(),
            'date'         => $order->get_date_created()->date_i18n(get_option('date_format')),
            'customer'     => $order->get_formatted_billing_full_name() ?: __('Guest', 'portal-cloud-9'),
            'email'        => $order->get_billing_email(),
        ];
    }
}
