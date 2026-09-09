<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
defined('ABSPATH') || exit;

$user         = wp_get_current_user();
$capabilities = PortalCloud9_Config::get_user_capabilities($user);
$is_seller    = !empty($capabilities['can_manage_products']);
$is_admin     = current_user_can('manage_options');
$unread       = (new PortalCloud9_Messaging_Integration())->get_unread_count();

/* ---- WooCommerce order data ---- */
$recent_orders = [];
$total_sales   = 0;
if (class_exists('WooCommerce')) {
    $args = [
        'limit'   => 6,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
        'type'    => 'shop_order',
    ];
    if (!$is_seller) {
        $args['customer'] = get_current_user_id();
    }
    $recent_orders = wc_get_orders($args);
    foreach ($recent_orders as $o) {
        $total_sales += $o->get_total();
    }
}

/* ---- 7-day sparkline ---- */
$spark        = [];
$spark_details = [];
for ($i = 6; $i >= 0; $i--) {
    $target_date = gmdate('Y-m-d', strtotime("-$i days"));
    $spark_args = [
        'limit'      => -1,
        'return'     => 'ids',
        'type'       => 'shop_order',
        'date_query' => [[
            'after'     => $target_date . ' 00:00:00',
            'before'    => $target_date . ' 23:59:59',
            'inclusive' => true,
        ]],
    ];
    if (!$is_seller) { $spark_args['customer'] = get_current_user_id(); }
    $ids   = wc_get_orders($spark_args);
    $count = is_array($ids) ? count($ids) : 0;
    $spark[]       = $count;
    $spark_details[] = [
        'date'  => gmdate('M j', strtotime("-$i days")),
        'day'   => gmdate('D', strtotime("-$i days")),
        'count' => $count,
    ];
}
$spark_max = max(1, max($spark));

/* ---- Admin: visitor analytics data ---- */
if ($is_admin) {
    global $wpdb;
    $va_pres  = $wpdb->prefix . 'portcld9_presence';

    $va_online = 0;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
    $pres_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $va_pres ) );
    if ($pres_exists) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $va_online = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM `' . esc_sql( $va_pres ) . '` WHERE last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 SECOND)' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name sanitized via esc_sql(); no user input.
        );
    }

    $today_orders = wc_get_orders([
        'limit'  => -1, 'return' => 'ids', 'type' => 'shop_order',
        'date_query' => [[
            'after'     => gmdate('Y-m-d') . ' 00:00:00',
            'before'    => gmdate('Y-m-d') . ' 23:59:59',
            'inclusive' => true,
        ]],
    ]);
    $today_order_count = is_array($today_orders) ? count($today_orders) : 0;

    $pending_orders = wc_get_orders([
        'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'status' => 'wc-pending',
    ]);
    $pending_count = is_array($pending_orders) ? count($pending_orders) : 0;

    $revenue_30 = 0;
    $rev_orders = wc_get_orders([
        'limit' => -1, 'return' => 'objects', 'type' => 'shop_order',
        'date_query' => [[
            'after'     => gmdate('Y-m-d', strtotime('-30 days')) . ' 00:00:00',
            'inclusive' => true,
        ]],
    ]);
    foreach ($rev_orders as $ro) { $revenue_30 += $ro->get_total(); }

    $total_products = wp_count_posts('product');
    $total_products_count = isset($total_products->publish) ? (int) $total_products->publish : 0;
}

/**
 * Format large numbers compactly for small card display.
 */
function p9_compact_price( float $amount ): string {
    $sym = get_woocommerce_currency_symbol();
    if ( $amount >= 1_000_000 ) {
        $compact = $sym . ' ' . rtrim( rtrim( number_format( $amount / 1_000_000, 2 ), '0' ), '.' ) . 'M';
    } elseif ( $amount >= 1_000 ) {
        $compact = $sym . ' ' . rtrim( rtrim( number_format( $amount / 1_000, 1 ), '0' ), '.' ) . 'k';
    } else {
        $compact = $sym . ' ' . number_format( $amount, 0 );
    }
    return esc_html( $compact );
}
function p9_full_price( float $amount ): string {
    return esc_html( get_woocommerce_currency_symbol() . ' ' . number_format( $amount, 2 ) );
}
?>


<div class="p9-overview-wrap">

<?php if ($is_admin): ?>
<!-- ================================================================
     ADMIN OVERVIEW
     ================================================================ -->

    <!-- Hero banner -->
    <div class="p9-admin-hero">
        <div class="p9-hero-left">
            <h2 class="p9-hero-greeting">
                👋 Welcome, <span><?php echo esc_html($user->display_name); ?></span>
            </h2>
            <p class="p9-hero-sub">Site Administrator — <?php echo esc_html( get_bloginfo('name') ); ?></p>
            <div class="p9-hero-actions">
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('products') ); ?>" class="p9-btn p9-btn-primary">🛍️ Products</a>
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('orders') ); ?>" class="p9-btn p9-btn-primary">🧾 Orders</a>
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('visitor-analytics') ); ?>" class="p9-btn p9-btn-primary">📈 Analytics</a>
            </div>
        </div>
        <div class="p9-hero-right">
            <div class="p9-hero-clock" id="p9-hero-clock">--:--:-- --</div>
            <div class="p9-hero-date" id="p9-hero-date"><?php echo esc_html( gmdate('l, F j, Y') ); ?></div>
        </div>
    </div>

    <!-- Visitor analytics strip -->
    <div class="p9-va-strip">
        <div class="p9-va-strip-header">
            <div class="p9-va-strip-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="#1E90FF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Website Visitors
            </div>
            <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('visitor-analytics') ); ?>"
               class="p9-va-full-link">
                Full Analytics
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"/>
                    <polyline points="12 5 19 12 12 19"/>
                </svg>
            </a>
        </div>

        <div class="p9-va-strip-grid">
            <!-- Online now -->
            <div class="p9-va-metric is-online">
                <div class="p9-va-metric-label">Online Now</div>
                <div class="p9-va-metric-val" id="p9ov-online"><?php echo absint( $va_online ); ?></div>
                <div class="p9-online-pill">
                    <span class="p9-online-dot-sm"></span>
                    Live
                </div>
            </div>
        </div><!-- /.p9-va-strip-grid -->
    </div><!-- /.p9-va-strip -->

    <!-- Store performance -->
    <div>
        <div class="p9-section-label">Store Performance</div>
        <div class="p9-store-grid">

            <div class="p9-store-card">
                <div class="p9-store-icon p9-icon-blue">📦</div>
                <div>
                    <div class="p9-store-info-val"><?php echo absint( $today_order_count ); ?></div>
                    <div class="p9-store-info-lbl">Orders Today</div>
                </div>
            </div>

            <div class="p9-store-card">
                <div class="p9-store-icon p9-icon-green">💰</div>
                <div>
                    <div class="p9-store-info-val p9-val-green"><?php echo wp_kses_post( p9_compact_price($revenue_30) ); ?></div>
                    <div class="p9-store-info-lbl">Revenue (30 days)</div>
                    <div class="p9-store-info-sub"><?php echo wp_kses_post( p9_full_price($revenue_30) ); ?></div>
                </div>
            </div>

            <div class="p9-store-card">
                <div class="p9-store-icon p9-icon-purple">🛍️</div>
                <div>
                    <div class="p9-store-info-val"><?php echo number_format($total_products_count); ?></div>
                    <div class="p9-store-info-lbl">Published Products</div>
                </div>
            </div>

            <div class="p9-store-card">
                <div class="p9-store-icon p9-icon-orange">⏳</div>
                <div>
                    <div class="p9-store-info-val<?php echo $pending_count > 0 ? ' p9-val-orange' : ''; ?>"><?php echo absint( $pending_count ); ?></div>
                    <div class="p9-store-info-lbl">Pending Orders</div>
                </div>
            </div>

            <div class="p9-store-card">
                <div class="p9-store-icon p9-icon-pink">💬</div>
                <div>
                    <div class="p9-store-info-val<?php echo $unread > 0 ? ' p9-val-pink' : ''; ?>"><?php echo absint( $unread ); ?></div>
                    <div class="p9-store-info-lbl">Unread Messages</div>
                </div>
            </div>

            <div class="p9-store-card">
                <div class="p9-store-icon p9-icon-sky">🏷️</div>
                <div>
                    <div class="p9-store-info-val"><?php echo number_format(array_sum($spark)); ?></div>
                    <div class="p9-store-info-lbl">Orders (7 days)</div>
                </div>
            </div>

        </div>
    </div>

    <!-- Bottom row: recent orders + quick actions -->
    <div class="p9-grid-2">

        <!-- Recent orders -->
        <div class="p9-glass-card">
            <div class="p9-glass-header">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <path d="M9 14l2 2 4-4"/>
                </svg>
                Recent Orders
            </div>
            <?php if (!$recent_orders): ?>
                <p class="p9-empty-msg">No orders yet.</p>
            <?php else: ?>
                <div class="p9-order-list">
                    <?php foreach ($recent_orders as $order): ?>
                        <a class="p9-recent-order-row"
                           href="<?php echo esc_url(PortalCloud9_Config::get_dashboard_tab_url('orders')); ?>"
                           data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <span><strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                                — <?php echo esc_html($order->get_formatted_billing_full_name() ?: 'Guest'); ?></span>
                            <span><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="p9-card-footer-right">
                    <a class="p9-btn p9-btn-primary" href="<?php echo esc_url(PortalCloud9_Config::get_dashboard_tab_url('orders')); ?>">View all orders</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick admin actions -->
        <div class="p9-glass-card">
            <div class="p9-glass-header">⚡ Quick Actions</div>
            <div class="p9-grid-2 p9-gap-md">
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('products') ); ?>" class="p9-btn p9-btn-primary">🛍️ Products</a>
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('add-product') ); ?>" class="p9-btn p9-btn-primary">➕ Add Product</a>
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('phone-contacts') ); ?>" class="p9-btn p9-btn-primary">📞 Phone Contacts</a>
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('visitor-analytics') ); ?>" class="p9-btn p9-btn-primary">📈 Visitor Analytics</a>
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('inbox') ); ?>" class="p9-btn p9-btn-primary">
                    📨 Inbox<?php if ($unread): ?><span class="p9-badge-white"><?php echo absint( $unread ); ?></span><?php endif; ?>
                </a>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="p9-btn p9-btn-primary" target="_blank">🌐 View Site</a>
            </div>
        </div>

    </div><!-- /.p9-grid-2 -->

<?php else: ?>
<!-- ================================================================
     SELLER / CUSTOMER OVERVIEW
     ================================================================ -->

    <!-- Welcome card -->
    <div class="p9-glass-card p9-flex-sb">
        <div>
            <h2 class="p9-welcome-heading">
                👋 Welcome back, <?php echo esc_html($user->display_name); ?>!
            </h2>
            <p class="p9-welcome-sub">
                Here's what's happening with your <?php echo esc_html( $is_seller ? 'store' : 'account' ); ?> today.
            </p>
        </div>
        <div class="p9-welcome-actions">
            <?php if ($is_seller && $capabilities['can_add_products']): ?>
                <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('add-product') ); ?>" class="p9-btn p9-btn-primary">➕ Add Product</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('account') ); ?>" class="p9-btn p9-btn-primary">👤 Edit Profile</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="p9-grid-2">
        <?php if ($is_seller): ?>
            <div class="p9-glass-card">
                <div class="p9-glass-header">📊 Seller Snapshot</div>
                <div class="p9-grid-2 p9-gap-md">
                    <div><div class="p9-stat-num"><?php echo esc_html( number_format_i18n( count_user_posts( $user->ID, 'product' ) ) ); ?></div><div class="p9-stat-lbl">Total Products</div></div>
                    <div><div class="p9-stat-num"><?php echo wp_kses_post( wc_price($total_sales) ); ?></div><div class="p9-stat-lbl">Sales (last 6)</div></div>
                    <div><div class="p9-stat-num"><?php echo absint($unread); ?></div><div class="p9-stat-lbl">Unread Messages</div></div>
                    <div><div class="p9-stat-num"><?php echo absint( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?></div><div class="p9-stat-lbl">Cart Items</div></div>
                </div>
            </div>
        <?php else: ?>
            <div class="p9-glass-card">
                <div class="p9-glass-header">🛍️ Your Activity</div>
                <div class="p9-grid-2 p9-gap-md">
                    <div><div class="p9-stat-num"><?php echo absint( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?></div><div class="p9-stat-lbl">Cart Items</div></div>
                    <div><div class="p9-stat-num"><?php $fav = $GLOBALS['portalcloud9_favourites'] ?? new PortalCloud9_Favourites(); echo absint($fav->count($user->ID)); ?></div><div class="p9-stat-lbl">Favourites</div></div>
                    <div><div class="p9-stat-num"><?php echo absint($unread); ?></div><div class="p9-stat-lbl">Unread Messages</div></div>
                    <div><div class="p9-stat-num"><?php echo absint(wc_get_customer_order_count($user->ID)); ?></div><div class="p9-stat-lbl">Orders Placed</div></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($is_seller): ?>
            <div class="p9-glass-card">
                <div class="p9-glass-header">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    7-Day Sales
                </div>
                <div class="p9-spark-header">
                    <div>
                        <div class="p9-spark-total"><?php echo absint( array_sum($spark) ); ?></div>
                        <div class="p9-spark-lbl">Total Orders</div>
                    </div>
                    <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('orders') ); ?>" class="p9-spark-link">View Orders →</a>
                </div>
                <div class="p9-spark-bars">
                    <?php foreach ($spark_details as $detail):
                        $ht = $detail['count'] > 0 ? max(5, (50 + (50 * ($detail['count'] / $spark_max)))) : 5; ?>
                        <div class="p9-spark-col">
                            <div class="p9-spark-bar" style="height:<?php echo absint( $ht ); ?>%;">
                            </div>
                            <div class="p9-spark-meta">
                                <span class="p9-spark-count"><?php echo absint( $detail['count'] ); ?></span>
                                <span class="p9-spark-date"><?php echo esc_html( $detail['date'] ); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="p9-glass-card">
                <div class="p9-glass-header">🎯 Shopping Journey</div>
                <div class="p9-journey-list">
                    <div class="p9-journey-item p9-journey-blue">
                        <div class="p9-journey-icon p9-journey-icon-blue">🛒</div>
                        <div><div class="p9-journey-val"><?php echo absint( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?></div><div class="p9-journey-lbl">Items in Cart</div></div>
                    </div>
                    <div class="p9-journey-item p9-journey-pink">
                        <div class="p9-journey-icon p9-journey-icon-pink">❤️</div>
                        <div><?php $fav2 = $GLOBALS['portalcloud9_favourites'] ?? new PortalCloud9_Favourites(); ?><div class="p9-journey-val p9-journey-val-pink"><?php echo absint($fav2->count($user->ID)); ?></div><div class="p9-journey-lbl">Favourites</div></div>
                    </div>
                    <div class="p9-journey-item p9-journey-green">
                        <div class="p9-journey-icon p9-journey-icon-green">📦</div>
                        <div><div class="p9-journey-val p9-journey-val-green"><?php echo absint(wc_get_customer_order_count($user->ID)); ?></div><div class="p9-journey-lbl">Total Orders</div></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bottom row -->
    <div class="p9-grid-2">
        <div class="p9-glass-card">
            <div class="p9-glass-header">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><path d="M9 14l2 2 4-4"/></svg>
                Recent Orders
            </div>
            <?php if (!$recent_orders): ?>
                <p class="p9-empty-msg">No orders yet.</p>
            <?php else: ?>
                <div class="p9-order-list">
                    <?php foreach ($recent_orders as $order): ?>
                        <a class="p9-recent-order-row"
                           href="<?php echo esc_url(PortalCloud9_Config::get_dashboard_tab_url('orders')); ?>"
                           data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <span><strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                                — <?php echo $is_seller ? esc_html($order->get_formatted_billing_full_name() ?: 'Guest') : esc_html(wc_get_order_status_name($order->get_status())); ?></span>
                            <span><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="p9-card-footer-right">
                    <a class="p9-btn p9-btn-primary" href="<?php echo esc_url(PortalCloud9_Config::get_dashboard_tab_url('orders')); ?>">View all orders</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="p9-glass-card">
            <div class="p9-glass-header">🚀 Quick <?php echo esc_html( $is_seller ? 'Seller' : 'Buyer' ); ?> Actions</div>
            <div class="p9-grid-2 p9-gap-md">
                <?php if ($is_seller): ?>
                    <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('products') ); ?>" class="p9-btn p9-btn-primary">🛍️ Manage Products</a>
                    <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('add-product') ); ?>" class="p9-btn p9-btn-primary">➕ Add Product</a>
                    <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('inbox') ); ?>" class="p9-btn p9-btn-primary">📨 Inbox<?php if ($unread): ?><span class="p9-badge-white"><?php echo absint( $unread ); ?></span><?php endif; ?></a>
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="p9-btn p9-btn-primary" target="_blank">🛒 Browse Shop</a>
                <?php else: ?>
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="p9-btn p9-btn-primary">🛒 Start Shopping</a>
                    <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('favourites') ); ?>" class="p9-btn p9-btn-primary">❤️ My Favourites</a>
                    <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('cart') ); ?>" class="p9-btn p9-btn-primary">🛍️ Cart</a>
                    <a href="<?php echo esc_url( PortalCloud9_Config::get_dashboard_tab_url('inbox') ); ?>" class="p9-btn p9-btn-primary">📨 Inbox<?php if ($unread): ?><span class="p9-badge-white"><?php echo absint( $unread ); ?></span><?php endif; ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php endif; ?>

</div><!-- /.p9-overview-wrap -->
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>
