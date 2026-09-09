<?php defined('ABSPATH') || exit;

class PortalCloud9_Favourites {

    private $table = '';
    private $yith = false;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'portalcloud9_favourites';
        $this->yith = class_exists('YITH_WCWL');
        $this->hooks();

        if (!$this->yith && get_option('portalcloud9_fav_db_ver') !== '3.1') {
            $this->create_table();
        }
    }

    private function hooks() {
        add_shortcode('portalcloud9_favourite_button', [$this, 'btn_shortcode']);
        add_shortcode('portalcloud9_favourites_count', [$this, 'count_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_base_styles'], 5);
        add_action('wp_enqueue_scripts', [$this, 'assets']);

        // DO NOT register AJAX actions here - they're registered in portal-cloud-9.php
        $ajax = [
            'portalcloud9_toggle_favourite',
            'portalcloud9_remove_favourite',
            'portalcloud9_clear_all_favourites',
            'portalcloud9_get_favourite_status',
            'portalcloud9_bulk_remove_favourites'
        ];

        foreach ($ajax as $act) {
            add_action('wp_ajax_' . $act, [$this, 'ajax_router']);
            add_action('wp_ajax_nopriv_' . $act, [$this, 'ajax_router']);
        }

        add_filter('body_class', function($classes) {
            if (get_query_var('portalcloud9_tab') === 'favourites') {
                if ($this->is_admin_or_manager()) {
                    wp_die(
                        'Favourites are not available for administrators and shop managers.',
                        'Access Denied',
                        ['response' => 403]
                    );
                }
                return array_merge($classes, ['portalcloud9-fav-page', 'p9-glass-theme']);
            }
            return $classes;
        });

        add_action('template_redirect', [$this, 'check_access']);
        add_action('template_redirect', [$this, 'handle_post_login_favourite']);
    }

    private function is_admin_or_manager() {
        if (!is_user_logged_in()) return false;
        $user = wp_get_current_user();
        return in_array('administrator', $user->roles) || in_array('shop_manager', $user->roles);
    }

    public function check_access() {
        if (get_query_var('portalcloud9_tab') === 'favourites' && $this->is_admin_or_manager()) {
            wp_die('<h1>Access Denied</h1><p>Favourites are available only for customers, sellers, and buyers.</p>', 'Access Denied', ['response' => 403]);
        }
    }

    /**
     * Handle adding product to favourites after login
     */
    public function handle_post_login_favourite() {
        // No nonce check here: the nonce was created in the guest session and cannot
        // be verified after login (different user context). WordPress login itself is
        // the authentication gate. absint() + wc_get_product() prevent any abuse.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( is_user_logged_in() && isset( $_GET['portcld9_add_favourite'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $product_id = absint( $_GET['portcld9_add_favourite'] );
            
            // Validate product exists
            if ($product_id && function_exists('wc_get_product') && wc_get_product($product_id)) {
                // Add to favourites
                $this->add($product_id);
                
                // Redirect to favourites tab with success parameter
                $portal_url = home_url('/user-portal/favourites/');
                $portal_url = add_query_arg('fav_added', $product_id, $portal_url);
                
                wp_safe_redirect($portal_url);
                exit;
            }
        }
    }

    private function create_table() {
        global $wpdb;
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta("CREATE TABLE {$this->table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL DEFAULT 0,
            product_id bigint(20) NOT NULL,
            session_id varchar(255) DEFAULT '',
            added_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_prod (user_id,product_id),
            KEY product_id (product_id),
            KEY added_date (added_date),
            KEY session_id (session_id)
        ) {$wpdb->get_charset_collate()};");

        update_option('portalcloud9_fav_db_ver', '3.1');
    }

    public function ajax_router() {
        $act = str_replace(['wp_ajax_', 'wp_ajax_nopriv_'], '', current_action());

        // Special handling for toggle action when not logged in
        if ($act === 'portalcloud9_toggle_favourite' && !is_user_logged_in()) {
            // Skip nonce check for non-logged-in users on toggle
            // They'll be redirected to login page anyway
            $this->ajax_toggle();
            return;
        }

        // For logged-in users and other actions, verify nonce
        if (!check_ajax_referer('portalcloud9_fav_nonce', 'nonce', false)) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
            wp_send_json_error(['message' => 'Security check failed', 'type' => 'error'], 403);
            exit;
        }

        if ($this->is_admin_or_manager()) {
            wp_send_json_error(['message' => 'Access denied', 'type' => 'error'], 403);
            exit;
        }

        switch ($act) {
            case 'portalcloud9_toggle_favourite': $this->ajax_toggle(); break;
            case 'portalcloud9_remove_favourite': $this->ajax_remove(); break;
            case 'portalcloud9_clear_all_favourites': $this->ajax_clear_all(); break;
            case 'portalcloud9_get_favourite_status': $this->ajax_status(); break;
            case 'portalcloud9_bulk_remove_favourites': $this->ajax_bulk_remove(); break;
        }
    }

    public function is_favourite($product_id, $user_id = null) {
        $user_id = $user_id ?: get_current_user_id();

        if ($this->yith) {
            return yith_wcwl_is_product_in_wishlist($product_id);
        }

        global $wpdb;

        if ($user_id) {
            return (bool) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
                "SELECT id FROM {$this->table} WHERE user_id = %d AND product_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + hardcoded string; no user input.
                $user_id, $product_id
            ));
        }

        $sid = $this->session_id();
        if (!$sid) return false;

        return (bool) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT id FROM {$this->table} WHERE session_id = %s AND product_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + hardcoded string; no user input.
            $sid, $product_id
        ));
    }

    public function add($product_id, $user_id = null) {
        $user_id = $user_id ?: get_current_user_id();

        if ($this->yith) {
            return yith_wcwl_add_to_wishlist($product_id);
        }

        global $wpdb;

        $data = [
            'product_id' => absint($product_id),
            'added_date' => current_time('mysql'),
            'user_id' => $user_id ? absint($user_id) : 0,
            'session_id' => $user_id ? '' : $this->session_id(true)
        ];

        if ($this->is_favourite($product_id, $user_id)) return true;

        $result = $wpdb->insert($this->table, $data, ['%d', '%s', '%d', '%s']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.

        wp_cache_delete('portcld9_fav_count_' . $user_id, 'portalcloud9');

        return $result !== false;
    }

    public function remove($product_id, $user_id = null) {
        $user_id = $user_id ?: get_current_user_id();

        if ($this->yith) {
            return yith_wcwl_remove_product_from_wishlist($product_id);
        }

        global $wpdb;

        if ($user_id) {
            $result = $wpdb->delete($this->table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
                'user_id' => absint($user_id),
                'product_id' => absint($product_id)
            ], ['%d', '%d']);
        } else {
            $sid = $this->session_id();
            if (!$sid) return false;

            $result = $wpdb->delete($this->table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
                'session_id' => $sid,
                'product_id' => absint($product_id)
            ], ['%s', '%d']);
        }

        wp_cache_delete('portcld9_fav_count_' . $user_id, 'portalcloud9');

        return $result !== false;
    }

    public function get_user_favourites($user_id = null, $args = []) {
        $user_id = $user_id ?: get_current_user_id();

        $args = wp_parse_args($args, [
            'limit' => -1,
            'return' => 'objects'
        ]);

        if ($this->yith) {
            $items = YITH_WCWL()->get_products(['user_id' => $user_id, 'wishlist_id' => 'all']);
            $ids = wp_list_pluck($items, 'prod_id');
        } else {
            global $wpdb;

            if ($user_id) {
                $ids = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom favourites table.
                    "SELECT product_id FROM {$this->table} WHERE user_id = %d ORDER BY added_date DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + hardcoded string; no user input.
                    absint($user_id)
                ));
            } else {
                $sid = $this->session_id();
                if (!$sid) return [];
                $ids = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom favourites table.
                    "SELECT product_id FROM {$this->table} WHERE session_id = %s ORDER BY added_date DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + hardcoded string; no user input.
                    $sid
                ));
            }
        }

        if ($args['return'] === 'objects') {
            return array_filter(array_map('wc_get_product', $ids));
        }

        return $ids;
    }

    public function count($user_id = null) {
        $user_id = $user_id ?: get_current_user_id();
        $cache_key = 'portcld9_fav_count_' . $user_id;

        $cached = wp_cache_get($cache_key, 'portalcloud9');
        if ($cached !== false) return absint($cached);

        $count = count($this->get_user_favourites($user_id, ['return' => 'ids']));
        wp_cache_set($cache_key, $count, 'portalcloud9', 300);

        return $count;
    }

    private function ajax_toggle() {
        // Verify nonce
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
        
        $product_id = absint($_POST['product_id'] ?? 0);

        if (!$product_id || !wc_get_product($product_id)) {
            wp_send_json_error(['message' => 'Invalid product', 'type' => 'error'], 400);
            exit;
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            // Return a clear response that will be caught in the success callback
            wp_send_json([
                'success' => false,
                'data' => [
                    'message' => 'Please login to add to favourites',
                    'type' => 'login_required',
                    'login_required' => true
                ]
            ]);
            exit;
        }

        $is_fav = $this->is_favourite($product_id);
        $done = $is_fav ? $this->remove($product_id) : $this->add($product_id);

        if ($done) {
            wp_send_json_success([
                'is_favourite' => !$is_fav,
                'count' => $this->count(),
                'message' => !$is_fav ? 'Added to favourites' : 'Removed from favourites',
                'action' => !$is_fav ? 'added' : 'removed'
            ]);
        }

        wp_send_json_error(['message' => 'Update failed', 'type' => 'error'], 500);
    }

    private function ajax_remove() {
        $product_id = absint($_POST['product_id'] ?? 0);

        if (!$product_id) {
            wp_send_json_error(['message' => 'Invalid product ID', 'type' => 'error'], 400);
            exit;
        }

        $removed = $this->remove($product_id);

        if ($removed) {
            wp_send_json_success([
                'removed' => true,
                'count' => $this->count(),
                'product_id' => $product_id
            ]);
        }

        wp_send_json_error(['message' => 'Remove failed', 'type' => 'error'], 500);
    }

    private function ajax_bulk_remove() {
        $product_ids = isset($_POST['product_ids']) ? array_map('absint', (array) $_POST['product_ids']) : [];
        $product_ids = array_filter($product_ids);

        if (empty($product_ids)) {
            wp_send_json_error(['message' => 'No products selected', 'type' => 'error'], 400);
            exit;
        }

        $removed = 0;

        foreach ($product_ids as $id) {
            if ($this->remove($id)) $removed++;
        }

        if ($removed > 0) {
            wp_send_json_success([
                'removed' => $removed,
                'count' => $this->count(),
                'message' => sprintf('%d item%s removed', $removed, $removed !== 1 ? 's' : '')
            ]);
        }

        wp_send_json_error(['message' => 'Bulk remove failed', 'type' => 'error'], 500);
    }

    private function ajax_clear_all() {
        $user_id = get_current_user_id();

        if ($this->yith) {
            $items = YITH_WCWL()->get_products(['user_id' => $user_id, 'wishlist_id' => 'all']);
            foreach ($items as $item) {
                yith_wcwl_remove_product_from_wishlist($item->prod_id);
            }
            wp_send_json_success(['cleared' => true, 'count' => 0]);
            exit;
        }

        global $wpdb;

        $deleted = false;

        if ($user_id) {
            $deleted = $wpdb->delete($this->table, ['user_id' => absint($user_id)], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
        } else {
            $sid = $this->session_id();
            if ($sid) {
                $deleted = $wpdb->delete($this->table, ['session_id' => $sid], ['%s']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            }
        }

        wp_cache_delete('portcld9_fav_count_' . $user_id, 'portalcloud9');

        if ($deleted !== false) {
            wp_send_json_success(['cleared' => true, 'count' => 0]);
        }

        wp_send_json_error(['message' => 'Clear failed', 'type' => 'error'], 500);
    }

    private function ajax_status() {
        $ids = isset($_POST['product_ids'])
            ? array_map('absint', (array) $_POST['product_ids'])
            : [];

        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_send_json_success([]);
            exit;
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->is_favourite($id);
        }

        wp_send_json_success($out);
    }

    /*  
    ============================================
    NEW HEART UI BLOCK
    ============================================
    */
    public function btn_shortcode($atts) {

        $atts = shortcode_atts([
            'product_id' => 0,
            'style'      => 'glass', // 'glass' (default) or 'small'
            'show_count' => 'false',
            'class'      => '',
            'hooked'     => 'false', // internal: 'true' when auto-injected by WC loop hook
        ], $atts);

        // ── Resolve product ID ─────────────────────────────────────────────
        // 1. Explicit product_id attribute
        // 2. get_the_ID() when inside the WC loop or on a single product page
        // 3. global $product — set by WooCommerce on every loop iteration
        $pid = absint( $atts['product_id'] );

        if ( ! $pid ) {
            $the_id = absint( get_the_ID() );
            if ( $the_id && wc_get_product( $the_id ) ) {
                $pid = $the_id;
            } else {
                global $product;
                if ( $product instanceof WC_Product ) {
                    $pid = $product->get_id();
                }
            }
        }

        if ( ! $pid || ! wc_get_product( $pid ) ) return '';
        if ( $this->is_admin_or_manager() ) return '';

        $is_fav = $this->is_favourite( $pid );
        $count  = $atts['show_count'] === 'true' ? $this->product_count( $pid ) : 0;

        // ── CSS classes ────────────────────────────────────────────────────
        $size_class   = ( $atts['style'] === 'small' ) ? ' p9-heart-btn--small' : '';
        // hooked=true  → auto-injected by WC loop action → absolute overlay on card.
        // hooked=false → manually placed shortcode → flows in natural document position.
        $hooked_class = ( $atts['hooked'] === 'true' ) ? ' p9-favourite-container--hooked' : '';

        // ── Late-enqueue JS so AJAX works on ANY page type ─────────────────
        // wp_enqueue_script called during template rendering queues the script
        // for output at wp_footer — it is not too late at this point.
        if ( ! wp_script_is( 'portalcloud9-fav', 'enqueued' ) ) {
            $ver = defined( 'PORTALCLOUD9_VERSION' ) ? PORTALCLOUD9_VERSION : '3.1';
            wp_enqueue_script(
                'portalcloud9-fav',
                PORTALCLOUD9_PLUGIN_URL . 'assets/js/favourites.js',
                [ 'jquery' ],
                $ver,
                true
            );
            wp_localize_script( 'portalcloud9-fav', 'portalcloud9_favourites', [
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'portalcloud9_fav_nonce' ),
                'is_logged_in' => is_user_logged_in(),
                'login_url'    => wp_login_url(),
                'fav_nonce'    => wp_create_nonce( 'portcld9_add_fav' ),
                'yith_active'  => $this->yith,
                'shop_url'     => get_permalink( wc_get_page_id( 'shop' ) ),
                'texts'        => [
                    'add'            => __( 'Added to favourites', 'portal-cloud-9' ),
                    'remove'         => __( 'Removed from favourites', 'portal-cloud-9' ),
                    'error'          => __( 'Error – please reload', 'portal-cloud-9' ),
                    'confirm_remove' => __( 'Remove this item?', 'portal-cloud-9' ),
                    'confirm_clear'  => __( 'Clear all favourites?', 'portal-cloud-9' ),
                    'bulk_confirm'   => __( 'Remove selected items?', 'portal-cloud-9' ),
                    'bulk_success'   => __( 'Items removed', 'portal-cloud-9' ),
                    'login_required' => __( 'Please login to add to favourites', 'portal-cloud-9' ),
                ],
            ] );
        }

        // ── SVG gradient defs — output once per page ───────────────────────
        static $p9_defs_printed = false;
        $defs_html = '';
        if ( ! $p9_defs_printed ) {
            $p9_defs_printed = true;
            $defs_html = '<svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden;"><defs><linearGradient id="p9-heart-stroke-default" x1="0" x2="1" y1="0" y2="1"><stop offset="0%" stop-color="#708090"/><stop offset="100%" stop-color="#ff0000"/></linearGradient><linearGradient id="p9-heart-stroke-active" x1="0" x2="1" y1="0" y2="1"><stop offset="0%" stop-color="#000000"/><stop offset="100%" stop-color="#708090"/></linearGradient></defs></svg>';
        }

        // ── Inline styles — guaranteed on any page type ────────────────────
        // favourites.css handles known WooCommerce pages; this inline block is
        // the reliable fallback for any other page the shortcode may appear on.
        // Deduplicated with a static flag — printed once per page request.
        ob_start();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG defs only, no user data
        echo $defs_html;
        ?>

<div class="p9-favourite-container<?php echo esc_attr( ( $size_class ? ' p9-favourite-container--small' : '' ) . $hooked_class ); ?> <?php echo esc_attr( $atts['class'] ); ?>"
     data-product-id="<?php echo absint( $pid ); ?>">

    <button class="p9-favourite-btn p9-heart-btn<?php echo esc_attr( $size_class ); ?> <?php echo esc_attr( $is_fav ? 'is-active' : '' ); ?>"
            data-product-id="<?php echo absint( $pid ); ?>"
            data-is-favourite="<?php echo esc_attr( $is_fav ? 'true' : 'false' ); ?>"
            data-user-logged-in="<?php echo esc_attr( is_user_logged_in() ? 'true' : 'false' ); ?>"
            aria-label="<?php echo esc_attr( $is_fav ? __( 'Remove from favourites', 'portal-cloud-9' ) : __( 'Add to favourites', 'portal-cloud-9' ) ); ?>"
            aria-pressed="<?php echo esc_attr( $is_fav ? 'true' : 'false' ); ?>">

        <svg class="p9-heart-icon-svg" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
        </svg>
    </button>

    <?php if ( $count > 0 ) : ?>
        <span class="p9-fav-counter"><?php echo absint( $count ); ?></span>
    <?php endif; ?>
</div>

        <?php return ob_get_clean();
    }
    /* END HEART UI BLOCK */

    public function count_shortcode($atts) {
        if ($this->is_admin_or_manager()) return '';

        $atts  = shortcode_atts(['user_id' => 0, 'format' => 'badge'], $atts);
        $count = $this->count(absint($atts['user_id']) ?: get_current_user_id());

        // ── Guest handling ──────────────────────────────────────────────────
        // If the visitor is not logged in, link them to the login page with a
        // redirect back to the Favourites tab. JS will intercept and can show
        // an inline prompt instead of a hard redirect if desired.
        if ( ! is_user_logged_in() ) {
            $redirect_url = home_url('/user-portal/favourites/');
            $url          = wp_login_url($redirect_url);
            $label        = __('Login to view your favourites', 'portal-cloud-9');
        } else {
            $url   = home_url('/user-portal/favourites/');
            $label = sprintf(
                // translators: %d is the number of items in favourites.
                _n('%d item in your favourites', '%d items in your favourites', $count, 'portal-cloud-9'),
                $count
            );
        }

        // ── Inline styles — output once per page, works on any page type ──
        // .p9-fav-counter-link uses the padding-box/border-box background-clip
        // trick for a genuine CSS gradient border. These rules are duplicated here
        // from favourites.css so the badge renders correctly even when placed in a
        // nav menu, header widget, or any page where favourites.css does not load.
        ob_start();
        ?>
        <a href="<?php echo esc_url($url); ?>"
           class="p9-fav-counter-link<?php echo esc_attr( ! is_user_logged_in() ? ' p9-fav-counter-link--guest' : '' ); ?>"
           data-count="<?php echo absint($count); ?>"
           data-logged-in="<?php echo esc_attr( is_user_logged_in() ? 'true' : 'false' ); ?>"
           aria-label="<?php echo esc_attr($label); ?>">
            <span class="p9-fav-counter-icon" aria-hidden="true">❤️</span>
            <span class="p9-fav-count-value"><?php echo absint($count); ?></span>
        </a>
        <?php return ob_get_clean();
    }

    /**
     * Always register the base style handle on every frontend page (priority 5,
     * before wp_head fires). This guarantees heart-button and notification CSS is
     * in <head> even when the shortcode appears in post content, widgets, or nav
     * menus — places where wp_add_inline_style() called inside the shortcode
     * itself would fire too late (after wp_head has already run).
     */
    public function register_base_styles() {
        wp_register_style( 'portalcloud9-fav-base', false, [], PORTALCLOUD9_VERSION );
        wp_add_inline_style( 'portalcloud9-fav-base', wp_strip_all_tags( self::get_heart_css() . self::get_badge_css() ) );
        wp_enqueue_style( 'portalcloud9-fav-base' );
    }

    public function assets() {
        // Only enqueue on pages where the favourites feature is actually rendered:
        // - The user-portal dashboard (favourites tab, and overview counter)
        // - Single product pages (favourites button in product summary)
        // - Shop listing, category, and tag archive pages (favourites button on loop items)
        $on_dashboard = (bool) get_query_var('portalcloud9_dashboard');
        $on_product   = function_exists('is_product')          && is_product();
        $on_shop      = function_exists('is_shop')             && is_shop();
        $on_category  = function_exists('is_product_category') && is_product_category();
        $on_tag       = function_exists('is_product_tag')      && is_product_tag();
        // is_woocommerce() covers any WooCommerce-aware page not caught above
        $on_woo       = function_exists('is_woocommerce')      && is_woocommerce();

        if ( ! ( $on_dashboard || $on_product || $on_shop || $on_category || $on_tag || $on_woo ) ) {
            return;
        }

        $ver = defined('PORTALCLOUD9_VERSION') ? PORTALCLOUD9_VERSION : '3.1';

        wp_enqueue_style(
            'portalcloud9-fav',
            PORTALCLOUD9_PLUGIN_URL . 'assets/css/favourites.css',
            ['portalcloud9-fontawesome', 'portalcloud9-fav-base'],
            $ver
        );

        wp_enqueue_script(
            'portalcloud9-fav',
            PORTALCLOUD9_PLUGIN_URL . 'assets/js/favourites.js',
            ['jquery'],
            $ver,
            true
        );

        wp_localize_script('portalcloud9-fav', 'portalcloud9_favourites', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('portalcloud9_fav_nonce'),
            'is_logged_in' => is_user_logged_in(),
            'login_url'  => wp_login_url(),
            'fav_nonce'  => wp_create_nonce( 'portcld9_add_fav' ),
            'yith_active' => $this->yith,
            'shop_url' => get_permalink(wc_get_page_id('shop')),
            'texts' => [
                'add' => __('Added to favourites', 'portal-cloud-9'),
                'remove' => __('Removed from favourites', 'portal-cloud-9'),
                'error' => __('Error – please reload', 'portal-cloud-9'),
                'confirm_remove' => __('Remove this item?', 'portal-cloud-9'),
                'confirm_clear' => __('Clear all favourites?', 'portal-cloud-9'),
                'bulk_confirm' => __('Remove selected items?', 'portal-cloud-9'),
                'bulk_success' => __('Items removed', 'portal-cloud-9'),
                'login_required' => __('Please login to add to favourites', 'portal-cloud-9'),
            ],
        ]);
    }

    /**
     * Heart-button and notification CSS — returned as a plain string for wp_add_inline_style().
     */
    public static function get_heart_css() {
        return '.p9-favourite-container{display:inline-flex;align-items:center;gap:4px;position:relative;z-index:10}'
             . '.p9-favourite-btn,.p9-heart-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;background:none!important;border:none!important;box-shadow:none!important;border-radius:0!important;padding:0!important;width:36px;height:36px;cursor:pointer;transition:transform .2s ease;outline:none}'
             . '.p9-heart-btn--small{width:26px!important;height:26px!important}'
             . '.p9-heart-btn:hover{transform:scale(1.15)}'
             . '.p9-heart-icon-svg{width:22px;height:22px;fill:white;stroke-width:2px;stroke:url(#p9-heart-stroke-default);transition:fill .25s ease,stroke .25s ease;overflow:visible}'
             . '.p9-heart-btn--small .p9-heart-icon-svg{width:15px!important;height:15px!important}'
             . '.p9-heart-btn.is-active .p9-heart-icon-svg{fill:#ff0000;stroke:url(#p9-heart-stroke-active)}'
             . '.woocommerce ul.products li.product{position:relative}'
             . '.woocommerce ul.products li.product .p9-favourite-container--hooked{position:absolute;top:10px;right:10px;z-index:10}'
             . '.single-product .p9-favourite-container{margin:16px 0}'
             . '.p9-top-notification{position:fixed;top:80px;left:50%;transform:translateX(-50%) translateY(-20px);background:linear-gradient(135deg,#ff0000 0%,#000000 100%) padding-box,linear-gradient(135deg,#ffffff 0%,#000000 100%) border-box;border:2px solid transparent;border-radius:12px;color:#fff!important;padding:12px 24px;font-size:14px;font-weight:600;opacity:0;visibility:hidden;transition:all .3s cubic-bezier(.4,0,.2,1);z-index:999999;text-align:center;white-space:nowrap;box-shadow:0 8px 32px rgba(0,0,0,.5);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);pointer-events:none}'
             . '.p9-top-notification::before{display:none}'
             . '.p9-top-notification.show{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0)}';
    }

    /**
     * Favourites badge/counter CSS — returned as a plain string for wp_add_inline_style().
     */
    public static function get_badge_css() {
        return '.p9-fav-counter-link{display:inline-flex;align-items:center;gap:8px;position:relative;background:linear-gradient(#ffffff,#ffffff) padding-box,linear-gradient(135deg,#000000,#cc0000) border-box;border:1.5px solid transparent;border-radius:999px;padding:6px 14px 6px 10px;text-decoration:none!important;box-shadow:0 2px 8px rgba(0,0,0,.12);transition:all .3s ease;color:#222!important}'
             . '.p9-fav-counter-link:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,0,0,.18);background:linear-gradient(#f8f8f8,#f8f8f8) padding-box,linear-gradient(135deg,#000000,#cc0000) border-box}'
             . '.p9-fav-counter-link--guest{opacity:.8;border-style:dashed}'
             . '.p9-fav-counter-link--guest:hover{opacity:1}'
             . '.p9-fav-counter-icon{font-size:16px;line-height:1}'
             . '.p9-fav-count-value{background:#ff0000;color:#fff!important;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;min-width:22px;text-align:center;line-height:1.5}';
    }

    private function session_id($create = false) {
        $sid = isset( $_COOKIE['portalcloud9_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['portalcloud9_session'] ) ) : '';

        if (!$sid && $create) {
            $sid = wp_generate_password(32, false);
            setcookie('portalcloud9_session', $sid, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }

        return $sid;
    }

    private function product_count($product_id) {
        if ($this->yith) return 0;

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT COUNT(DISTINCT user_id) FROM {$this->table} WHERE product_id = %d AND user_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + hardcoded string; no user input.
            absint($product_id)
        ));
    }
}

// Note: Class is instantiated in portal-cloud-9.php at priority 10
