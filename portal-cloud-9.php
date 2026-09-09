<?php
/**
 * Plugin Name: Portal Cloud 9
 * Plugin URI:  https://gradyzer.com
 * Description: Revolutionize your WooCommerce store with the ultimate mobile-friendly dashboard featuring powerful multi-vendor marketplace capabilities, seller/buyer portals, real-time messaging, and complete product management, all in one stunning interface.
 * Version: 8.7.2
 * Author:      Brian Agoi (Gradyzer)
 * Author URI:  https://gradyzer.com/brian-agoi/
 * Text Domain: portal-cloud-9
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WC tested up to: 9.4
 */

defined('ABSPATH') || exit;

/* ------------------------------------------------------------------
 * PORTAL CLOUD 9 PRO COMPATIBILITY
 * If Portal Cloud 9 Pro is active, this free plugin defers entirely
 * to Pro and does nothing. Pro hooks into this plugin's architecture
 * and takes over all functionality.
 * ------------------------------------------------------------------ */

/**
 * Returns true when Portal Cloud 9 Pro is active.
 * Pro defines portal_cloud9_pro_active() on its own plugins_loaded hook
 * (priority 1) before this plugin bootstraps (priority 5).
 */
function portcld9_pro_is_active(): bool {
    return function_exists( 'portal_cloud9_pro_active' );
}


/**
 * Show a clean admin notice when Pro is active so the site owner
 * knows the free plugin is intentionally dormant.
 */
/*
 * The "Pro is active" notice is owned by Portal Cloud 9 Pro, which shows
 * it exactly once after activation. The free plugin intentionally does not
 * broadcast any notice about Pro.
 */

/* ------------------------------------------------------------------
 * WOOCOMMERCE DEPENDENCY CHECK
 * Portal Cloud 9 requires WooCommerce to function
 * ------------------------------------------------------------------ */

/**
 * Check if WooCommerce is active
 */
function portcld9_is_woocommerce_active() {
    return class_exists('WooCommerce') || in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
}

/**
 * Show admin notice if WooCommerce is not active
 */
function portcld9_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p style="color: #475569; margin: 0.5em 0;">
            <strong style="color: #dc2626;">Portal Cloud 9 requires WooCommerce!</strong><br>
            <span style="color: #64748b;">Portal Cloud 9 has been deactivated because WooCommerce is not installed or activated. Please install and activate WooCommerce first.</span>
        </p>
        <p style="margin: 0.5em 0;">
            <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="button button-primary">
                Install WooCommerce
            </a>
            <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button">
                Go to Plugins
            </a>
        </p>
    </div>
    <?php
}

/**
 * Deactivate Portal Cloud 9 if WooCommerce is not active
 */
function portcld9_deactivate_self() {
    if (!portcld9_is_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        
        // Show notice on next page load
        add_action('admin_notices', 'portcld9_woocommerce_missing_notice');
        
        // Prevent "Plugin activated" message
        if (isset($_GET['activate'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Activation flag, no state mutation.
            unset($_GET['activate']);
        }
    }
}

// Check on plugin activation
register_activation_hook(__FILE__, 'portcld9_deactivate_self');

// Check continuously (in case WooCommerce is deactivated later)
add_action('admin_init', function() {
    if (!portcld9_is_woocommerce_active()) {
        add_action('admin_notices', 'portcld9_woocommerce_missing_notice');
        deactivate_plugins(plugin_basename(__FILE__));
    }
});

/* ------------------------------------------------------------------
 * 1.  Early constants – MUST exist before any other file is loaded
 * ------------------------------------------------------------------ */
define('PORTCLD9_VERSION', '8.7.0');
define('PORTCLD9_PLUGIN_FILE', __FILE__);
define('PORTCLD9_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PORTCLD9_PLUGIN_URL', plugin_dir_url(__FILE__));

/* Aliases for templates & AJAX — guarded so Pro can define them first */
defined('PORTALCLOUD9_PLUGIN_PATH')|| define('PORTALCLOUD9_PLUGIN_PATH', PORTCLD9_PLUGIN_DIR);
defined('PORTALCLOUD9_PLUGIN_URL') || define('PORTALCLOUD9_PLUGIN_URL',  PORTCLD9_PLUGIN_URL);
defined('PORTALCLOUD9_VERSION')    || define('PORTALCLOUD9_VERSION',     PORTCLD9_VERSION);
defined('PORTALCLOUD9_PLUGIN_DIR') || define('PORTALCLOUD9_PLUGIN_DIR',  PORTCLD9_PLUGIN_DIR);
defined('PORTALCLOUD9_PLUGIN_FILE')|| define('PORTALCLOUD9_PLUGIN_FILE', PORTCLD9_PLUGIN_FILE);

/* ------------------------------------------------------------------
 * 2.  DECLARE WOOCOMMERCE COMPATIBILITY (Safe - doesn't output anything)
 * ------------------------------------------------------------------ */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/* ------------------------------------------------------------------
 * 3.  REGISTER QUERY VARS (Safe - just adds query vars)
 * ------------------------------------------------------------------ */
add_filter('query_vars', function ($vars) {
    $vars[] = 'portalcloud9_dashboard';
    $vars[] = 'portalcloud9_tab';
    $vars[] = 'portcld9_edit_id';
    return $vars;
});

/* ------------------------------------------------------------------
 * 4.  Helper function to check if we're on the dashboard
 * ------------------------------------------------------------------ */
function portalcloud9_is_dashboard_request() {
    // Check URL path for early detection (before query vars are parsed)
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    if (strpos($request_uri, '/user-portal') !== false) {
        return true;
    }
    // Also check query var if available
    if (function_exists('get_query_var') && get_query_var('portalcloud9_dashboard')) {
        return true;
    }
    return false;
}

/* ------------------------------------------------------------------
 * 4b. INBOX FULLSCREEN — intercept at template_redirect and own the
 *     entire page output. Priority 1 fires before any theme hook so
 *     nothing else can inject a header or footer.
 *     dashboard.php also handles this case as a safety-net fallback.
 * ------------------------------------------------------------------ */
add_action( 'template_redirect', function () {
    if ( portcld9_pro_is_active() ) {
        return;
    }

    // Fast URL-based check first (works even before query vars are parsed)
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    $on_inbox_url = ( strpos( $uri, '/user-portal/inbox' ) !== false );

    // Also check query vars (authoritative once parsed)
    $on_inbox_qv = (
        (bool) get_query_var( 'portalcloud9_dashboard' ) &&
        get_query_var( 'portalcloud9_tab', '' ) === 'inbox'
    );

    if ( ! $on_inbox_url && ! $on_inbox_qv ) {
        return;
    }

    // ── Guard: if messaging is disabled, redirect to Overview with notice ──
    if ( is_user_logged_in() && ! PortalCloud9_Config::is_messaging_enabled() ) {
        set_transient(
            'portalcloud9_tab_notice_' . get_current_user_id(),
            '"Inbox" is currently disabled or not available for your account. You have been redirected to the Overview.',
            60
        );
        wp_safe_redirect( home_url( '/user-portal/' ) );
        exit;
    }

    if ( wp_is_mobile() ) {
        return;
    }

    // Kill admin bar so it cannot inject a top-margin
    add_filter( 'show_admin_bar', '__return_false' );

    // Add body class before wp_head() outputs the <body> tag
    add_filter( 'body_class', function ( $classes ) {
        $classes[] = 'p9-inbox-fullscreen';
        return $classes;
    } );

    // Clear any output buffers a caching/theme plugin may have opened
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    // ── Output the entire HTML document ourselves ────────────────────
    // get_header() and get_footer() are NEVER called here.
    // wp_head() + wp_footer() ensure all enqueued assets still load.
    // exit after output so nothing else in WordPress can run.
    // ─────────────────────────────────────────────────────────────────
    defined( 'PORTALCLOUD9_PLUGIN_PATH' ) || define( 'PORTALCLOUD9_PLUGIN_PATH', PORTCLD9_PLUGIN_DIR );
    defined( 'PORTALCLOUD9_PLUGIN_URL' )  || define( 'PORTALCLOUD9_PLUGIN_URL',  PORTCLD9_PLUGIN_URL );
    defined( 'PORTALCLOUD9_VERSION' )     || define( 'PORTALCLOUD9_VERSION',     PORTCLD9_VERSION );

    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php wp_title( '|', true, 'right' ); ?><?php bloginfo( 'name' ); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php if ( function_exists( 'wp_body_open' ) ) wp_body_open(); ?>
<div class="portalcloud9-dashboard-wrapper">
<?php include PORTCLD9_PLUGIN_DIR . 'templates/desktop-dashboard.php'; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php
    exit;

}, 1 ); // priority 1 = before any theme template_redirect hooks

/* ------------------------------------------------------------------
 * 5.  Bootstrap the plugin - ONLY load files when needed
 * ------------------------------------------------------------------ */
add_action('plugins_loaded', 'portcld9_bootstrap', 5);
function portcld9_bootstrap()
{
    // Yield entirely to Portal Cloud 9 Pro if it is active.
    if ( portcld9_pro_is_active() ) {
        return;
    }

    // Don't load if WooCommerce is not active
    if (!portcld9_is_woocommerce_active()) {
        return;
    }
    
    // Note: load_plugin_textdomain() is not needed for WordPress.org plugins
    // WordPress automatically loads translations for plugins hosted on WordPress.org
    
    // Always load config (needed for menu items, capabilities check)
    $always_load = [
        'includes/class-config.php',
        'includes/functions.php',
        'includes/class-phone-contacts.php',
        'includes/class-visitor-analytics.php',
        'includes/class-rewards.php',
    ];

    foreach ($always_load as $file) {
        $path = PORTCLD9_PLUGIN_DIR . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }

    // Admin files - only in admin
    if (is_admin()) {
        $admin_files = [
            'admin/class-settings.php',
        ];
        foreach ($admin_files as $file) {
            $path = PORTCLD9_PLUGIN_DIR . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    // Initialize the main controller
    PortalCloud9Portal::get_instance();
    // Initialize phone contacts
    if (class_exists('PortalCld9_Phone_Contacts')) {
        PortalCld9_Phone_Contacts::get_instance();
    }
    // Initialize rewards system
    if (class_exists('PortalCloud9_Rewards')) {
        new PortalCloud9_Rewards();
    }
}

/* ------------------------------------------------------------------
 * 6.  Load dashboard-specific files only when on dashboard
 * ------------------------------------------------------------------ */
add_action('wp', 'portcld9_load_dashboard_files', 5);
function portcld9_load_dashboard_files() {
    if ( portcld9_pro_is_active() ) {
        return;
    }

    if (!get_query_var('portalcloud9_dashboard')) {
        return;
    }

    // These files are only needed on the dashboard (non-AJAX)
    $dashboard_files = [
        'includes/class-dashboard.php',
        'includes/class-products.php',
    ];

    foreach ($dashboard_files as $file) {
        $path = PORTCLD9_PLUGIN_DIR . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }

    // Initialize dashboard-specific classes
    $classes = [
        'PortalCloud9_Dashboard',
        'PortalCloud9_Products',
    ];

    foreach ($classes as $class) {
        if (class_exists($class)) {
            new $class();
        }
    }
}

/* ------------------------------------------------------------------
 * 7.  AJAX handlers - MUST be registered early for ALL requests
 *     These classes register their own wp_ajax_ hooks in constructors
 * ------------------------------------------------------------------ */
add_action('plugins_loaded', 'portcld9_register_ajax_handlers', 10);
function portcld9_register_ajax_handlers() {
    if ( portcld9_pro_is_active() ) {
        return;
    }

    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    // ALWAYS load these files - they contain AJAX handlers that must be
    // registered before any AJAX request comes in. The classes register
    // their own wp_ajax_ hooks in their constructors.
    $ajax_files = [
        'includes/class-messaging-integration.php',
        'includes/class-orders-ajax.php',
        'includes/class-products-ajax.php',
        'includes/class-account-ajax.php',
        'includes/class-cart-ajax.php',
        'includes/class-import-export-ajax.php',
        'includes/class-rewards.php',
    ];

    foreach ($ajax_files as $file) {
        $path = PORTCLD9_PLUGIN_DIR . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
    
    // Initialize messaging class - it registers AJAX hooks in constructor
    if (class_exists('PortalCloud9_Messaging_Integration')) {
        new PortalCloud9_Messaging_Integration();
    }
    
    // Initialize orders AJAX handler
    if (class_exists('PortalCloud9_Orders_Ajax')) {
        new PortalCloud9_Orders_Ajax();
    }
    
    // Initialize products AJAX handler
    if (class_exists('PortalCloud9_Products_Ajax')) {
        new PortalCloud9_Products_Ajax();
    }
    
    // Initialize account AJAX if not already done
    if (class_exists('PortalCloud9_Account_Ajax')) {
        new PortalCloud9_Account_Ajax();
    }
    
    // Initialize cart AJAX handler
    if (class_exists('PortalCloud9_Cart_Ajax')) {
        new PortalCloud9_Cart_Ajax();
    }

    // Initialize rewards AJAX handler — guard prevents double-instantiation
    // if portcld9_bootstrap already ran it at plugins_loaded priority 5
    if ( class_exists('PortalCloud9_Rewards') && ! has_action('wp_ajax_portcld9_rewards_get_data') ) {
        new PortalCloud9_Rewards();
    }
}

/* ------------------------------------------------------------------
 * 8.  Dashboard-specific classes initialization (non-AJAX display)
 *     Note: Messaging is already loaded in section 7
 * ------------------------------------------------------------------ */

/* ------------------------------------------------------------------
 * 9.  Favourites & Shortcodes - Load conditionally
 * ------------------------------------------------------------------ */
add_action('plugins_loaded', function () {
    if ( portcld9_pro_is_active() ) {
        return;
    }

    // Load files
    $path_fav = PORTCLD9_PLUGIN_DIR . 'includes/class-favourites.php';
    $path_sc = PORTCLD9_PLUGIN_DIR . 'includes/class-shortcodes.php';
    
    if (file_exists($path_fav)) {
        require_once $path_fav;
    }
    if (file_exists($path_sc)) {
        require_once $path_sc;
    }
    
    // Initialize classes
    if (class_exists('PortalCloud9_Shortcodes')) {
        new PortalCloud9_Shortcodes();
    }
    if (class_exists('PortalCloud9_Favourites')) {
        $GLOBALS['portalcloud9_favourites'] = new PortalCloud9_Favourites();
    }
}, 5);

/* ------------------------------------------------------------------
 * 9B. DIRECT AJAX HANDLERS - Register directly to avoid class timing issues
 * ------------------------------------------------------------------ */

// --- Login redirect: send everyone to the portal dashboard ----------------
// Exceptions: explicit redirect_to params (e.g. favourites action) are kept.
add_filter( 'login_redirect', function ( $redirect_to, $requested_redirect_to, $user ) {
    if ( portcld9_pro_is_active() ) {
        return $redirect_to;
    }

    // Honour explicit favourite-action redirects
    if ( $requested_redirect_to && strpos( $requested_redirect_to, 'portcld9_add_favourite' ) !== false ) {
        return $requested_redirect_to;
    }

    // Send everyone else — including administrators — to the portal
    return home_url( '/user-portal/' );

}, 999, 3 );

// --- wp-admin access: only administrators may enter -------------------------
// All other roles are redirected to the portal the moment they hit any
// wp-admin page.  AJAX requests are always allowed (plugins need them).
add_action( 'admin_init', function () {

    // Never block AJAX — the portal itself fires admin-ajax.php calls
    if ( wp_doing_ajax() ) {
        return;
    }

    // Never block WP-Cron
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        return;
    }

    // Allow true administrators through
    if ( current_user_can( 'manage_options' ) ) {
        return;
    }

    // Everyone else: redirect to the portal
    wp_safe_redirect( home_url( '/user-portal/' ) );
    exit;

} );

// Toggle favourite - for logged-out users
add_action('wp_ajax_nopriv_portalcloud9_toggle_favourite', function() {
    // User not logged in - return login required
    wp_send_json([
        'success' => false,
        'data' => [
            'message' => 'Please login to add to favourites',
            'type' => 'login_required',
            'login_required' => true
        ]
    ]);
});

// Toggle favourite - for logged-in users
add_action('wp_ajax_portalcloud9_toggle_favourite', function() {
    // Get the favourites instance
    if (!isset($GLOBALS['portalcloud9_favourites'])) {
        wp_send_json_error(['message' => 'Favourites not initialized'], 500);
    }
    
    $fav = $GLOBALS['portalcloud9_favourites'];
    
    // Verify nonce
    if (!check_ajax_referer('portalcloud9_fav_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed'], 403);
    }
    
    $product_id = absint($_POST['product_id'] ?? 0);
    
    if (!$product_id || !wc_get_product($product_id)) {
        wp_send_json_error(['message' => 'Invalid product'], 400);
    }
    
    $is_fav = $fav->is_favourite($product_id);
    $done = $is_fav ? $fav->remove($product_id) : $fav->add($product_id);
    
    if ($done) {
        wp_send_json_success([
            'is_favourite' => !$is_fav,
            'count' => $fav->count(),
            'message' => !$is_fav ? 'Added to favourites' : 'Removed from favourites',
            'action' => !$is_fav ? 'added' : 'removed'
        ]);
    }
    
    wp_send_json_error(['message' => 'Update failed'], 500);
});

// Other AJAX actions
add_action('wp_ajax_portalcloud9_remove_favourite', function() {
    if (isset($GLOBALS['portalcloud9_favourites'])) {
        $GLOBALS['portalcloud9_favourites']->ajax_router();
    }
});

add_action('wp_ajax_portalcloud9_clear_all_favourites', function() {
    if (isset($GLOBALS['portalcloud9_favourites'])) {
        $GLOBALS['portalcloud9_favourites']->ajax_router();
    }
});

add_action('wp_ajax_portalcloud9_get_favourite_status', function() {
    if (isset($GLOBALS['portalcloud9_favourites'])) {
        $GLOBALS['portalcloud9_favourites']->ajax_router();
    }
});

add_action('wp_ajax_portalcloud9_bulk_remove_favourites', function() {
    if (isset($GLOBALS['portalcloud9_favourites'])) {
        $GLOBALS['portalcloud9_favourites']->ajax_router();
    }
});

/* ------------------------------------------------------------------
 * 10.  Favourites button on WooCommerce pages ONLY
 * ------------------------------------------------------------------ */
add_action('wp', function() {
    // Auto-inject the favourite button on single product pages only.
    // On shop/category/tag loop pages the user places [portalcloud9_favourite_button]
    // manually via shortcode — the auto-hook is intentionally NOT added there to
    // avoid rendering a duplicate button alongside the manually-placed one.
    if (function_exists('is_product') && is_product()) {
        add_action('woocommerce_single_product_summary', 'portcld9_render_favourite_button', 35);
    }
});

function portcld9_render_favourite_button() {
    if (isset($GLOBALS['portalcloud9_favourites']) && method_exists($GLOBALS['portalcloud9_favourites'], 'btn_shortcode')) {
        echo wp_kses_post( $GLOBALS['portalcloud9_favourites']->btn_shortcode( [
            'product_id' => absint(get_the_ID()),
            'hooked' => 'true',
        ] ) );
    }
}

/* ------------------------------------------------------------------
 * 11.  Enqueue Font Awesome Locally (conditional — only where needed)
 * ------------------------------------------------------------------ */
add_action('wp_enqueue_scripts', function() {
    // Only enqueue on pages that actually use Font Awesome icons:
    // - The user-portal dashboard (all tabs)
    // - Single product pages (inquiry button, favourites button)
    // - Shop and product category/tag pages (favourites button on listings)
    $on_dashboard = (bool) get_query_var('portalcloud9_dashboard');
    $on_product   = function_exists('is_product')          && is_product();
    $on_shop      = function_exists('is_shop')             && is_shop();
    $on_category  = function_exists('is_product_category') && is_product_category();
    $on_tag       = function_exists('is_product_tag')      && is_product_tag();

    if ( ! ( $on_dashboard || $on_product || $on_shop || $on_category || $on_tag ) ) {
        return;
    }

    wp_enqueue_style(
        'portalcloud9-fontawesome',
        PORTALCLOUD9_PLUGIN_URL . 'assets/font-awesome/css/all.css',
        [],
        '6.7.2'
    );
}, 5); // Priority 5 ensures FA registers before dependent plugin styles (priority 10)

/* ------------------------------------------------------------------
 * 11. Product Inquiry Box - ONLY on single product pages
 * ------------------------------------------------------------------ */
add_action('wp', function() {
    if ( portcld9_pro_is_active() ) {
        return;
    }
    if (function_exists('is_product') && is_product()) {
        add_action('woocommerce_after_single_product', 'portcld9_render_product_inquiry', 99);
    }
});

function portcld9_render_product_inquiry() {
    echo do_shortcode('[portalcloud9_product_inquiry style="floating" position="bottom-right"]');
}

/* ------------------------------------------------------------------
 * 12. FLUSH REWRITE RULES ON ACTIVATION
 * ------------------------------------------------------------------ */
register_activation_hook(__FILE__, function () {
    // Add rewrite rules
    add_rewrite_rule('^user-portal/?$', 'index.php?portalcloud9_dashboard=1', 'top');
    add_rewrite_rule('^user-portal/([^/]*)/?$', 'index.php?portalcloud9_dashboard=1&portalcloud9_tab=$matches[1]', 'top');
    add_rewrite_rule(
        '^user-portal/edit-product/([0-9]+)/?$',
        'index.php?portalcloud9_dashboard=1&portalcloud9_tab=edit-product&portcld9_edit_id=$matches[1]',
        'top'
    );
    flush_rewrite_rules(false);

    // Create reward points table
    $rewards_path = plugin_dir_path(__FILE__) . 'includes/class-rewards.php';
    if (file_exists($rewards_path)) {
        require_once $rewards_path;
    }
    if (class_exists('PortalCloud9_Rewards')) {
        PortalCloud9_Rewards::create_table();
        PortalCloud9_Rewards::schedule_cron();
    }
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules(false);
    // Clear scheduled cron events
    wp_clear_scheduled_hook('portcld9_visitor_cleanup');
    wp_clear_scheduled_hook('portcld9_license_check_event');
    wp_clear_scheduled_hook('portcld9_rewards_expiry_cron');
});

/* ------------------------------------------------------------------
 * 13. Main controller class
 * ------------------------------------------------------------------ */
final class PortalCloud9Portal
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'add_rewrite_rules'], 10);
        add_action('template_include', [$this, 'dashboard_template']);
        add_action('template_redirect', [$this, 'dashboard_access_control']);
        
        // Only enqueue assets on dashboard
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);
    }

    public function add_rewrite_rules()
    {
        add_rewrite_rule('^user-portal/?$', 'index.php?portalcloud9_dashboard=1', 'top');
        add_rewrite_rule('^user-portal/([^/]*)/?$', 'index.php?portalcloud9_dashboard=1&portalcloud9_tab=$matches[1]', 'top');
        
        // Edit product rule
        add_rewrite_tag('%portcld9_edit_id%', '([0-9]+)');
        add_rewrite_rule(
            '^user-portal/edit-product/([0-9]+)/?$',
            'index.php?portalcloud9_dashboard=1&portalcloud9_tab=edit-product&portcld9_edit_id=$matches[1]',
            'top'
        );
    }

    public function enqueue_assets()
    {
        // Version with timestamp for aggressive cache busting
        $cache_buster = time();
        $ver = PORTCLD9_VERSION . '.' . $cache_buster;
        
        // Enqueue phone tracking on single product pages FIRST (before dashboard check)
        if (function_exists('is_product') && is_product() && is_user_logged_in()) {
            wp_enqueue_style('portalcloud9-phone-display', PORTCLD9_PLUGIN_URL . 'assets/css/phone-contacts.css', [], $ver);
            wp_enqueue_script('portalcloud9-phone-tracking', PORTCLD9_PLUGIN_URL . 'assets/js/phone-contacts.js', ['jquery'], $ver, true);
            
            
            wp_localize_script('portalcloud9-phone-tracking', 'portalcloud9_ajax', [
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce('portalcloud9_nonce'),
            ]);
        }
        
        // Check if we're on dashboard - if not, stop here
        if (!get_query_var('portalcloud9_dashboard')) {
            return;
        }
        
        // Get current tab
        $current_tab = get_query_var('portalcloud9_tab', 'overview');
        
        // Enqueue main dashboard styles/scripts (always).
        // 'portalcloud9-fontawesome' is declared as a dependency so WP guarantees
        // FA loads before any plugin CSS that uses its icon classes.
        wp_enqueue_style('portalcloud9-dashboard', PORTCLD9_PLUGIN_URL . 'assets/css/dashboard.css', ['portalcloud9-fontawesome'], $ver);
        wp_enqueue_script('portalcloud9-dashboard', PORTCLD9_PLUGIN_URL . 'assets/js/dashboard.js', ['jquery'], $ver, true);

        // Enqueue layout-specific stylesheet (replaces hardcoded <link> tags in templates).
        if (wp_is_mobile()) {
            wp_enqueue_style('portalcloud9-mobile-dashboard', PORTCLD9_PLUGIN_URL . 'assets/css/mobile-dashboard.css', ['portalcloud9-dashboard'], $ver);
        } else {
            wp_enqueue_style('portalcloud9-desktop-dashboard', PORTCLD9_PLUGIN_URL . 'assets/css/desktop-dashboard.css', ['portalcloud9-dashboard'], $ver);
        }
        
        // Tab-specific enqueues
        switch ($current_tab) {
            case 'products':
                wp_enqueue_style('portalcloud9-products', PORTCLD9_PLUGIN_URL . 'assets/css/products.css', ['portalcloud9-fontawesome'], $ver);
                wp_enqueue_script('portalcloud9-products', PORTCLD9_PLUGIN_URL . 'assets/js/products.js', ['jquery'], $ver, true);
                wp_enqueue_script('portalcloud9-import-export', PORTCLD9_PLUGIN_URL . 'assets/js/import-export.js', ['jquery'], $ver, true);
                break;
                
            case 'add-product':
                wp_enqueue_style('portalcloud9-add-product', PORTCLD9_PLUGIN_URL . 'assets/css/add-product.css', ['portalcloud9-fontawesome'], $ver);
                wp_enqueue_script('portalcloud9-add-product', PORTCLD9_PLUGIN_URL . 'assets/js/add-product.js', ['jquery'], $ver, true);
                break;
                
            case 'edit-product':
                // edit-product shares add-product.css for layout styles
                wp_enqueue_style('portalcloud9-add-product', PORTCLD9_PLUGIN_URL . 'assets/css/add-product.css', ['portalcloud9-fontawesome'], $ver);
                wp_enqueue_script('portalcloud9-edit-product', PORTCLD9_PLUGIN_URL . 'assets/js/edit-product.js', ['jquery'], $ver, true);
                break;
                
            case 'orders':
                // Orders CSS and JS are enqueued by PortalCloud9_Orders_Ajax class
                // This ensures proper localization with portcld9_orders_params
                break;
                
            case 'favourites':
                // Note: favourites.js is already enqueued globally by class-favourites.php
                // Only enqueue the CSS for the favourites tab
                wp_enqueue_style('portalcloud9-favourites', PORTCLD9_PLUGIN_URL . 'assets/css/favourites.css', ['portalcloud9-fontawesome'], $ver);
                break;
                
            case 'inbox':
                wp_enqueue_style('portalcloud9-inbox', PORTCLD9_PLUGIN_URL . 'assets/css/inbox.css', ['portalcloud9-fontawesome'], $ver);
                wp_enqueue_style('portalcloud9-mobile-modal', PORTCLD9_PLUGIN_URL . 'assets/css/mobile-modal.css', ['portalcloud9-inbox'], $ver);
                wp_enqueue_script('portalcloud9-inbox', PORTCLD9_PLUGIN_URL . 'assets/js/inbox.js', ['jquery'], $ver, true);
                break;
                
            case 'account':
                wp_enqueue_style('portalcloud9-account', PORTCLD9_PLUGIN_URL . 'assets/css/account.css', ['portalcloud9-fontawesome'], $ver);
                wp_enqueue_script('portalcloud9-account', PORTCLD9_PLUGIN_URL . 'assets/js/account.js', ['jquery'], $ver, true);
                break;
                
            case 'phone-contacts':
                if (current_user_can('manage_options') || current_user_can('manage_woocommerce')) {
                    wp_enqueue_style('portalcloud9-phone-contacts', PORTCLD9_PLUGIN_URL . 'assets/css/phone-contacts.css', ['portalcloud9-fontawesome'], $ver);
                    wp_enqueue_script('portalcloud9-phone-contacts', PORTCLD9_PLUGIN_URL . 'assets/js/phone-contacts.js', ['jquery'], $ver, true);
                }
                break;

            case 'visitor-analytics':
                if (current_user_can('manage_options')) {
                    wp_enqueue_style(
                        'portalcloud9-visitor-analytics',
                        PORTCLD9_PLUGIN_URL . 'assets/css/visitor-analytics.css',
                        ['portalcloud9-fontawesome'],
                        $ver
                    );
                    wp_enqueue_script(
                        'portalcloud9-visitor-analytics',
                        PORTCLD9_PLUGIN_URL . 'assets/js/visitor-analytics.js',
                        ['jquery'],
                        $ver,
                        true
                    );
                    wp_localize_script(
                        'portalcloud9-visitor-analytics',
                        'portcld9_va',
                        [
                            'ajax_url' => admin_url('admin-ajax.php'),
                            'nonce'    => wp_create_nonce('portcld9_visitor_nonce'),
                        ]
                    );
                }
                break;

            case 'rewards':
                wp_enqueue_style('portalcloud9-rewards', PORTCLD9_PLUGIN_URL . 'assets/css/rewards.css', ['portalcloud9-dashboard'], $ver);
                wp_enqueue_script('portalcloud9-rewards', PORTCLD9_PLUGIN_URL . 'assets/js/rewards.js', [], $ver, true);
                $p9rw_user    = wp_get_current_user();
                $p9rw_roles_arr = (array) $p9rw_user->roles;
                // WP role arrays are keyed by slug, NOT numerically — roles[0] is always null.
                // Detect role via in_array() with a priority hierarchy.
                if ( in_array( 'administrator', $p9rw_roles_arr, true ) ) {
                    $p9rw_role = 'administrator';
                } elseif ( in_array( 'shop_manager', $p9rw_roles_arr, true ) ) {
                    $p9rw_role = 'shop_manager';
                } else {
                    $p9rw_role = 'customer';
                }
                $p9rw_balance  = class_exists('PortalCloud9_Rewards') ? PortalCloud9_Rewards::get_balance( absint($p9rw_user->ID) ) : 0;
                $p9rw_settings = class_exists('PortalCloud9_Rewards') ? PortalCloud9_Rewards::get_settings() : [];
                wp_localize_script('portalcloud9-rewards', 'portcld9_rewards_data', [
                    'ajax_url'              => admin_url('admin-ajax.php'),
                    'nonce'                 => wp_create_nonce('portcld9_rewards_nonce'),
                    'role'                  => $p9rw_role,
                    'balance'               => $p9rw_balance,
                    'redemption_rate'       => $p9rw_settings['redemption_rate']  ?? 100,
                    'min_redemption'        => $p9rw_settings['min_redemption']   ?? 50,
                    'withdrawal_rate'       => $p9rw_settings['withdrawal_rate']  ?? 100,
                    'min_withdrawal'        => $p9rw_settings['min_withdrawal']   ?? 500,
                ]);
                break;
        }
        
        // Cart styles/script (may be used on multiple tabs)
        wp_enqueue_style('portalcloud9-cart', PORTCLD9_PLUGIN_URL . 'assets/css/cart.css', ['portalcloud9-fontawesome'], $ver);
        wp_enqueue_script('portalcloud9-cart', PORTCLD9_PLUGIN_URL . 'assets/js/cart.js', ['jquery'], $ver, true);

        // Localize scripts for AJAX
        // Use current domain to avoid CORS issues (supports staging/production domains)
        $ajax_url = admin_url( 'admin-ajax.php' );
        
        $ajax_data = [
            'ajax_url'   => $ajax_url,
            'nonce'      => wp_create_nonce('portalcloud9_nonce'),
            'plugin_url' => PORTCLD9_PLUGIN_URL,
            'current_user_id' => get_current_user_id(),
        ];
        
        wp_localize_script('portalcloud9-dashboard', 'portalcloud9_ajax', $ajax_data);
        wp_localize_script('portalcloud9-cart', 'portcld9_cart_params', $ajax_data);
        
        // Localize tab-specific scripts
        if ($current_tab === 'products') {
            // Provide both variable names for compatibility:
            // - portalcloud9_ajax for inline scripts in products template
            // - portalcloud9_products for products.js
            wp_localize_script('portalcloud9-products', 'portalcloud9_ajax', $ajax_data);
            wp_localize_script('portalcloud9-products', 'portalcloud9_products', $ajax_data);
        }
        if ($current_tab === 'add-product') {
            wp_localize_script('portalcloud9-add-product', 'portalcloud9_ajax', $ajax_data);
        }
        if ($current_tab === 'edit-product') {
            // Extend with product-specific data so the template no longer needs an inline <script>
            $edit_product_id = absint( get_query_var('portcld9_edit_id') );
            $edit_image_id   = 0;
            $edit_image_url  = '';
            $edit_gallery    = [];
            if ( $edit_product_id && function_exists('wc_get_product') ) {
                $edit_product = wc_get_product( $edit_product_id );
                if ( $edit_product ) {
                    $edit_image_id  = (int) $edit_product->get_image_id();
                    $edit_image_url = $edit_image_id ? esc_url( wp_get_attachment_url( $edit_image_id ) ) : '';
                    $edit_gallery   = $edit_product->get_gallery_image_ids();
                }
            }
            wp_localize_script('portalcloud9-edit-product', 'portalcloud9_edit_data', array_merge( $ajax_data, [
                'product_id'   => $edit_product_id,
                'products_url' => home_url('/user-portal/products/'),
                'image_id'     => $edit_image_id,
                'image_url'    => $edit_image_url,
                'gallery_ids'  => $edit_gallery,
            ]));
        }
        // Add overview-specific data (visitor nonce for online-user polling)
        if ( $current_tab === 'overview' || $current_tab === '' ) {
            wp_localize_script('portalcloud9-dashboard', 'portcld9_overview_data', [
                'visitor_nonce' => wp_create_nonce('portcld9_visitor_nonce'),
            ]);
        }
        // Orders localization is handled by PortalCloud9_Orders_Ajax class
        if ($current_tab === 'inbox') {
            wp_localize_script('portalcloud9-inbox', 'portalcloud9_inbox', $ajax_data);
            // Debug: Verify localization
        }
        if ($current_tab === 'account') {
            wp_localize_script('portalcloud9-account', 'portalcloud9_ajax', $ajax_data);
        }
        if ($current_tab === 'phone-contacts' && (current_user_can('manage_options') || current_user_can('manage_woocommerce'))) {
            wp_localize_script('portalcloud9-phone-contacts', 'portalcloud9_contacts', $ajax_data);
        }
    }

    public function admin_enqueue_assets($hook)
    {
        // Load shared admin stylesheet + script on the main Settings page.
        // Shortcodes and Getting Started pages enqueue their own CSS via
        // dedicated add_action() calls in class-settings.php::add_admin_menu(),
        // where the hook suffix is captured directly from add_submenu_page().
        if ( $hook !== 'toplevel_page_portalcloud9-settings' ) {
            return;
        }

        $cache_buster = time();
        $ver = PORTCLD9_VERSION . '.' . $cache_buster;
        wp_enqueue_style('portalcloud9-admin', PORTCLD9_PLUGIN_URL . 'assets/css/admin.css', [], $ver);
        wp_enqueue_script('portalcloud9-admin', PORTCLD9_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], $ver, true);
        // Pass nonces required by admin.js (moved from inline scripts in class-settings.php)
        wp_localize_script('portalcloud9-admin', 'portcld9_admin_data', [
            'purge_nonce'       => wp_create_nonce('portcld9_purge_data'),
            'clear_cache_nonce' => wp_create_nonce('portcld9_clear_cache'),
            'toggle_nonce'      => wp_create_nonce('portcld9_toggle_option'),
        ]);
    }

    public function dashboard_template($template)
    {
        if (get_query_var('portalcloud9_dashboard')) {
            $new_template = locate_template(['portalcloud9-dashboard.php']);
            if (!$new_template) {
                $new_template = PORTALCLOUD9_PLUGIN_PATH . 'templates/dashboard.php';
            }
            return $new_template;
        }
        return $template;
    }

    public function dashboard_access_control()
    {
        if ( ! get_query_var( 'portalcloud9_dashboard' ) ) {
            return;
        }

        // Not logged in — send to login page
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( home_url( '/user-portal/' ) ) );
            exit;
        }

        $requested_tab  = get_query_var( 'portalcloud9_tab', 'overview' );
        $overview_url   = home_url( '/user-portal/' );

        // Nothing to check on overview itself
        if ( $requested_tab === 'overview' || $requested_tab === '' ) {
            return;
        }

        // edit-product is a virtual tab accessed via /user-portal/edit-product/{id}/
        // It is not listed in menu_items (no sidebar entry) but IS a valid tab for
        // users who can edit products. Allow it through here; the template itself
        // verifies product ownership.
        if ( $requested_tab === 'edit-product' ) {
            return;
        }

        // Build the list of tabs this user is actually allowed to see
        $allowed_tabs = PortalCloud9_Config::get_user_menu_items();

        if ( ! array_key_exists( $requested_tab, $allowed_tabs ) ) {

            // Decide message: capability issue vs feature disabled
            $all_menu_items = PortalCloud9_Config::get_all_menu_items();
            $item_exists    = isset( $all_menu_items[ $requested_tab ] );

            if ( $item_exists ) {
                $label   = $all_menu_items[ $requested_tab ]['label'] ?? ucfirst( $requested_tab );
                $message = sprintf(
                    '"%s" is currently disabled or not available for your account. You have been redirected to the Overview.',
                    esc_html( $label )
                );
            } else {
                $message = 'That section does not exist. You have been redirected to the Overview.';
            }

            // Store notice in a short-lived transient for this user
            set_transient( 'portalcloud9_tab_notice_' . get_current_user_id(), $message, 60 );

            wp_safe_redirect( $overview_url );
            exit;
        }
    }
}
