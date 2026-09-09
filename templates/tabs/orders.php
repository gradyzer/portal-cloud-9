<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
/**
 * Portal Cloud 9 – Orders / My Orders Tab Template
 * Isolated: runs ONLY inside our dashboard
 */
defined('ABSPATH') || exit;

/* ----------  ISOLATION GUARD  ---------- */
if (!get_query_var('portalcloud9_dashboard')) {   // not our dashboard → bail
    return;
}

/* ---------- ROLE LOGIC ---------- */
$current_user      = wp_get_current_user();
$customer_roles    = ['subscriber', 'contributor', 'customer'];
$manager_roles     = ['administrator', 'editor', 'shop_manager', 'author'];

$is_customer       = count(array_intersect($customer_roles,    $current_user->roles)) > 0;
$is_manager        = count(array_intersect($manager_roles,     $current_user->roles)) > 0;

/* Fallback – if somehow neither, treat as customer */
if (!$is_customer && !$is_manager) {
    $is_customer = true;
}

/* ---------- DATA ---------- */
// Get pagination settings from options
$p9_options = get_option('portalcloud9_options', []);
$orders_per_page_customer = isset($p9_options['orders_per_page_customer']) ? absint($p9_options['orders_per_page_customer']) : 10;
$orders_per_page_manager = isset($p9_options['orders_per_page_manager']) ? absint($p9_options['orders_per_page_manager']) : 15;

// Get current page
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter, absint()-sanitized.
$current_page = isset( $_GET['orders_page'] ) ? max( 1, absint( $_GET['orders_page'] ) ) : 1;

// Determine per page based on role
$per_page = $is_customer ? $orders_per_page_customer : $orders_per_page_manager;

$args = [
    'limit'    => $per_page,
    'page'     => $current_page,
    'orderby'  => 'date',
    'order'    => 'DESC',
    'type'     => 'shop_order', // Exclude refunds - they don't have get_order_number()
    'customer' => $is_customer ? $current_user->ID : null,
    'paginate' => true,
];
$orders_query = wc_get_orders($args);
$orders = $orders_query->orders;
$total_orders = $orders_query->total;
$total_pages = $orders_query->max_num_pages;

/* ---------- INLINE GLASSMORPHIC STYLES ---------- */
?>

<div class="p9-orders-wrapper">

<?php if ($is_customer) : ?>

    <!-- ========== MY ORDERS (Customer View) ========== -->
    <div class="p9-my-orders-header">
        <div class="p9-my-orders-title-wrap">
            <div class="p9-my-orders-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <path d="M9 14l2 2 4-4"/>
                </svg>
            </div>
            <div>
                <h1 class="p9-my-orders-title"><?php esc_html_e('My Orders', 'portal-cloud-9'); ?></h1>
                <p class="p9-my-orders-subtitle"><?php 
                // translators: %d: Total number of orders placed
                echo esc_html(sprintf(__('%d orders placed', 'portal-cloud-9'), absint($total_orders))); 
                ?></p>
            </div>
        </div>
        <div class="p9-my-orders-filters">
            <div class="p9-my-orders-search">
                <span class="p9-my-search-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                </span>
                <input type="text" class="p9-my-search-input" id="p9-my-orders-search" placeholder="<?php esc_attr_e('Search orders...', 'portal-cloud-9'); ?>">
            </div>
            <select class="p9-my-orders-filter" id="p9-my-orders-status-filter">
                <option value=""><?php esc_html_e('All Statuses', 'portal-cloud-9'); ?></option>
                <?php foreach (wc_get_order_statuses() as $status_key => $status_label) : ?>
                    <option value="<?php echo esc_attr(str_replace('wc-', '', $status_key)); ?>"><?php echo esc_html($status_label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (empty($orders)) : ?>
        <div class="p9-orders-empty">
            <div class="p9-empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <path d="M9 14l2 2 4-4"/>
                </svg>
            </div>
            <h2 class="p9-empty-title"><?php esc_html_e('No orders yet', 'portal-cloud-9'); ?></h2>
            <p class="p9-empty-message"><?php esc_html_e('When you place an order it will appear here.', 'portal-cloud-9'); ?></p>
            <a class="p9-my-order-btn primary" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Start Shopping', 'portal-cloud-9'); ?></a>
        </div>
    <?php else : ?>
        <div class="p9-my-orders-grid" id="p9-my-orders-grid">
            <?php foreach ($orders as $order) :
                $status        = $order->get_status();
                $status_name   = wc_get_order_status_name($status);
                $needs_payment = $order->needs_payment();
                $items         = $order->get_items();
                $item_count    = count($items);
                $first_item    = reset($items);
                $product       = $first_item ? $first_item->get_product() : null;
                $thumbnail     = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';
                if (!$thumbnail) $thumbnail = wc_placeholder_img_src('thumbnail');
            ?>
                <div class="p9-my-order-card" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-status="<?php echo esc_attr($status); ?>">
                    <!-- Status ribbon -->
                    <div class="p9-my-order-ribbon p9-status-<?php echo esc_attr($status); ?>">
                        <span class="p9-ribbon-dot"></span>
                        <?php echo esc_html($status_name); ?>
                    </div>
                    
                    <!-- Order header -->
                    <div class="p9-my-order-top">
                        <div class="p9-my-order-info">
                            <span class="p9-my-order-number">#<?php echo esc_html($order->get_order_number()); ?></span>
                            <span class="p9-my-order-date">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
                            </span>
                        </div>
                        <div class="p9-my-order-total"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></div>
                    </div>
                    
                    <!-- Product preview -->
                    <div class="p9-my-order-preview">
                        <div class="p9-my-order-thumb">
                            <img src="<?php echo esc_url($thumbnail); ?>" alt="">
                        </div>
                        <div class="p9-my-order-items-info">
                            <?php if ($first_item) : ?>
                                <span class="p9-my-item-name"><?php echo esc_html($first_item->get_name()); ?></span>
                            <?php endif; ?>
                            <?php if ($item_count > 1) : ?>
                                <span class="p9-my-item-more">+<?php echo absint($item_count - 1); ?> <?php echo esc_html(_n('more item', 'more items', $item_count - 1, 'portal-cloud-9')); ?></span>
                            <?php endif; ?>
                            <span class="p9-my-item-qty"><?php 
                            // translators: %d: Total number of items in the order
                            echo esc_html(sprintf(__('%d items total', 'portal-cloud-9'), absint(array_sum(array_map(function($item) { return $item->get_quantity(); }, $items))))); 
                            ?></span>
                        </div>
                    </div>
                    
                    <!-- Payment method & shipping -->
                    <div class="p9-my-order-meta-row">
                        <div class="p9-my-meta-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                                <line x1="1" y1="10" x2="23" y2="10"/>
                            </svg>
                            <?php echo esc_html($order->get_payment_method_title() ?: __('N/A', 'portal-cloud-9')); ?>
                        </div>
                        <?php if ($order->get_shipping_method()) : ?>
                        <div class="p9-my-meta-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <rect x="1" y="3" width="15" height="13"/>
                                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                                <circle cx="5.5" cy="18.5" r="2.5"/>
                                <circle cx="18.5" cy="18.5" r="2.5"/>
                            </svg>
                            <?php echo esc_html($order->get_shipping_method()); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="p9-my-order-actions">
                        <button type="button" class="p9-my-order-btn ghost p9-view-order-details" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <?php esc_html_e('Details', 'portal-cloud-9'); ?>
                        </button>
                        <?php if ($needs_payment) : ?>
                            <a class="p9-my-order-btn primary" href="<?php echo esc_url($order->get_checkout_payment_url()); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                                    <line x1="1" y1="10" x2="23" y2="10"/>
                                </svg>
                                <?php esc_html_e('Pay Now', 'portal-cloud-9'); ?>
                            </a>
                        <?php elseif ($status === 'completed') : ?>
                            <button type="button" class="p9-my-order-btn secondary p9-reorder-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <polyline points="23 4 23 10 17 10"/>
                                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                                </svg>
                                <?php esc_html_e('Reorder', 'portal-cloud-9'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($total_pages > 1) : ?>
        <!-- Customer Orders Pagination -->
        <div class="p9-orders-pagination">
            <div class="p9-pagination-info">
                <?php 
                $start_item = (($current_page - 1) * $per_page) + 1;
                $end_item = min($current_page * $per_page, $total_orders);
                // translators: 1: First order number, 2: Last order number, 3: Total number of orders
                echo esc_html(sprintf(__('Showing %1$d-%2$d of %3$d orders', 'portal-cloud-9'), absint($start_item), absint($end_item), absint($total_orders))); 
                ?>
            </div>
            <div class="p9-pagination-buttons">
                <?php if ($current_page > 1) : ?>
                    <a href="<?php echo esc_url(add_query_arg('orders_page', $current_page - 1)); ?>" class="p9-pagination-btn p9-prev-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>
                        <?php esc_html_e('Previous', 'portal-cloud-9'); ?>
                    </a>
                <?php endif; ?>
                
                <div class="p9-pagination-numbers">
                    <?php
                    // Show page numbers with ellipsis for large page counts
                    $range = 2; // Show 2 pages before and after current
                    
                    if ($current_page > $range + 1) {
                        echo '<a href="' . esc_url(add_query_arg('orders_page', 1)) . '" class="p9-page-num">1</a>';
                        if ($current_page > $range + 2) {
                            echo '<span class="p9-page-ellipsis">...</span>';
                        }
                    }
                    
                    for ($i = max(1, $current_page - $range); $i <= min($total_pages, $current_page + $range); $i++) {
                        $active = $i === $current_page ? ' active' : '';
                        echo '<a href="' . esc_url(add_query_arg('orders_page', $i)) . '" class="p9-page-num' . esc_attr($active) . '">' . absint($i) . '</a>';
                    }
                    
                    if ($current_page < $total_pages - $range) {
                        if ($current_page < $total_pages - $range - 1) {
                            echo '<span class="p9-page-ellipsis">...</span>';
                        }
                        echo '<a href="' . esc_url(add_query_arg('orders_page', $total_pages)) . '" class="p9-page-num">' . absint($total_pages) . '</a>';
                    }
                    ?>
                </div>
                
                <?php if ($current_page < $total_pages) : ?>
                    <a href="<?php echo esc_url(add_query_arg('orders_page', $current_page + 1)); ?>" class="p9-pagination-btn p9-next-btn">
                        <?php esc_html_e('Next', 'portal-cloud-9'); ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
    <?php endif; ?>
    
    <!-- My Orders Modal -->
    <?php include PORTALCLOUD9_PLUGIN_PATH . 'templates/my-orders-modal.php'; ?>


<?php else : ?>

    <!-- ========== ORDERS (Manager View) – existing glass grid ========== -->
    <?php
    /* reuse previous manager logic & markup; only colours updated via CSS above */
    $order_statuses = wc_get_order_statuses();
    
    // Get manager per page setting
    $manager_per_page = $orders_per_page_manager;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter, absint()-sanitized.
$manager_current_page = isset( $_GET['orders_page'] ) ? max( 1, absint( $_GET['orders_page'] ) ) : 1;
    
    $args = [
        'limit'    => $manager_per_page,
        'page'     => $manager_current_page,
        'orderby'  => 'date',
        'order'    => 'DESC',
        'paginate' => true,
        'type'     => 'shop_order', // Exclude refunds - they don't have get_order_number()
    ];
    $manager_orders_query = wc_get_orders($args);
    $orders = $manager_orders_query->orders;
    $manager_total_orders = $manager_orders_query->total;
    $manager_total_pages = $manager_orders_query->max_num_pages;
    
    $stats        = portalcloud9_get_order_stats(null);
    $sources      = [
        ''          => __('All Sources', 'portal-cloud-9'),
        'organic'   => __('Organic/Google', 'portal-cloud-9'),
        'direct'    => __('Direct', 'portal-cloud-9'),
        'tiktok'    => __('TikTok', 'portal-cloud-9'),
        'instagram' => __('Instagram', 'portal-cloud-9'),
        'facebook'  => __('Facebook', 'portal-cloud-9'),
        'email'     => __('Email Marketing', 'portal-cloud-9'),
        'affiliate' => __('Affiliate', 'portal-cloud-9'),
    ];
    ?>

    <header class="p9-orders-header">
        <div class="p9-orders-title-group">
            <div class="p9-orders-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <path d="M9 14l2 2 4-4"/>
                </svg>
            </div>
            <div>
                <h1 class="p9-orders-title"><?php esc_html_e('Orders Management', 'portal-cloud-9'); ?></h1>
                <p class="p9-orders-subtitle"><?php 
                // translators: %d: Total number of orders in the system
                echo esc_html(sprintf(__('%d total orders', 'portal-cloud-9'), absint($manager_total_orders))); 
                ?></p>
            </div>
        </div>

        <div class="p9-orders-controls">
            <div class="p9-orders-search">
                <span class="p9-orders-search-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                </span>
                <input type="text" class="p9-orders-search-input" placeholder="<?php esc_attr_e('Search orders...', 'portal-cloud-9'); ?>">
            </div>

            <select class="p9-orders-filter" id="p9-filter-status">
                <option value=""><?php esc_html_e('All Statuses', 'portal-cloud-9'); ?></option>
                <?php foreach ($order_statuses as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>

            <select class="p9-orders-filter" id="p9-filter-source">
                <?php foreach ($sources as $k => $v) : ?>
                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </header>

    <!-- Stats Row -->
    <div class="p9-orders-stats">
        <div class="p9-stat-card" data-stat="total">
            <div class="p9-stat-value"><?php echo esc_html($stats['total']); ?></div>
            <div class="p9-stat-label"><?php esc_html_e('Total Orders', 'portal-cloud-9'); ?></div>
        </div>
        <div class="p9-stat-card p9-stat-pending" data-stat="pending">
            <div class="p9-stat-value"><?php echo esc_html($stats['pending']); ?></div>
            <div class="p9-stat-label"><?php esc_html_e('Pending', 'portal-cloud-9'); ?></div>
        </div>
        <div class="p9-stat-card" data-stat="processing">
            <div class="p9-stat-value"><?php echo esc_html($stats['processing']); ?></div>
            <div class="p9-stat-label"><?php esc_html_e('Processing', 'portal-cloud-9'); ?></div>
        </div>
        <div class="p9-stat-card p9-stat-completed" data-stat="completed">
            <div class="p9-stat-value"><?php echo esc_html($stats['completed']); ?></div>
            <div class="p9-stat-label"><?php esc_html_e('Completed', 'portal-cloud-9'); ?></div>
        </div>
        <div class="p9-stat-card p9-stat-cancelled" data-stat="revenue">
            <div class="p9-stat-value"><?php echo wp_kses_post(wc_price($stats['revenue'])); ?></div>
            <div class="p9-stat-label"><?php esc_html_e('Revenue', 'portal-cloud-9'); ?></div>
        </div>
    </div>

    <!-- Orders Grid (AJAX will refill) -->
    <div class="p9-orders-grid">
        <?php
        if (empty($orders)) :
            echo '<div class="p9-orders-empty">
                    <div class="p9-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                        <rect x="9" y="3" width="6" height="4" rx="2"/><path d="M9 14l2 2 4-4"/></svg></div>
                    <h2 class="p9-empty-title">'.esc_html__('No orders yet','portal-cloud-9').'</h2>
                    <p class="p9-empty-message">'.esc_html__('Orders will appear here once customers start purchasing.','portal-cloud-9').'</p>
                  </div>';
        else :
            foreach ($orders as $order) :
                $status_class = 'p9-status-' . str_replace('wc-', '', $order->get_status());
                $source       = portalcloud9_get_order_source($order);
                $first_item   = current($order->get_items());
                $product      = $first_item ? $first_item->get_product() : null;
                $img          = $product ? wp_get_attachment_image_url($product->get_image_id(),'thumbnail') : '';
                if (!$img) $img = wc_placeholder_img_src('thumbnail');
                $customer_name = $order->get_formatted_billing_full_name() ?: __('Guest','portal-cloud-9');
                $product_name = $first_item ? $first_item->get_name() : '';
                ?>
                <article class="p9-order-card <?php echo esc_attr($status_class); ?>" 
                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                    data-status="<?php echo esc_attr('wc-' . $order->get_status()); ?>"
                    data-source="<?php echo esc_attr($source); ?>"
                    data-search="<?php echo esc_attr(strtolower($order->get_order_number() . ' ' . $customer_name . ' ' . $order->get_billing_email() . ' ' . $product_name)); ?>">
                    <div class="p9-order-card-header">
                        <span class="p9-order-number">#<?php echo esc_html($order->get_order_number()); ?></span>
                        <span class="p9-order-status <?php echo esc_attr($status_class); ?>">
                            <span class="p9-status-dot"></span>
                            <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
                        </span>
                    </div>
                    <div class="p9-order-customer">
                        <div class="p9-customer-avatar"><?php echo wp_kses_post( get_avatar($order->get_billing_email(), 36) ); ?></div>
                        <div class="p9-customer-info">
                            <div class="p9-customer-name"><?php echo esc_html($customer_name); ?></div>
                            <div class="p9-customer-email"><?php echo esc_html($order->get_billing_email()); ?></div>
                        </div>
                    </div>
                    <?php if ($first_item) : ?>
                    <div class="p9-order-product">
                        <div class="p9-product-image"><img src="<?php echo esc_url($img); ?>" alt=""></div>
                        <div class="p9-product-details">
                            <div class="p9-product-name"><?php echo esc_html($first_item->get_name()); ?></div>
                            <div class="p9-product-meta">
                                <span>ID: <?php echo esc_html($first_item->get_product_id()); ?></span>
                                <?php if ($product && $product->get_sku()) : ?>
                                    <span>SKU: <?php echo esc_html($product->get_sku()); ?></span>
                                <?php endif; ?>
                                <span class="p9-product-qty">×<?php echo esc_html($first_item->get_quantity()); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="p9-order-details">
                        <div class="p9-detail-item">
                            <span class="p9-detail-label"><?php esc_html_e('Date','portal-cloud-9'); ?></span>
                            <span class="p9-detail-value"><?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></span>
                        </div>
                        <div class="p9-detail-item">
                            <span class="p9-detail-label"><?php esc_html_e('Total','portal-cloud-9'); ?></span>
                            <span class="p9-detail-value p9-amount"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
                        </div>
                    </div>
                    <div class="p9-order-source">
                        <span class="p9-source-icon p9-source-<?php echo esc_attr($source); ?>"><?php 
                        echo wp_kses( portalcloud9_get_source_icon( $source ), [
                            'svg'  => [ 'viewbox' => [], 'fill' => [], 'width' => [], 'height' => [], 'stroke' => [], 'stroke-width' => [], 'xmlns' => [], 'class' => [] ],
                            'path' => [ 'd' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [] ],
                            'rect' => [ 'x' => [], 'y' => [], 'width' => [], 'height' => [], 'rx' => [], 'fill' => [] ],
                            'line' => [ 'x1' => [], 'y1' => [], 'x2' => [], 'y2' => [] ],
                            'circle' => [ 'cx' => [], 'cy' => [], 'r' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ],
                            'polyline' => [ 'points' => [] ],
                        ] );
                        ?></span>
                        <span class="p9-source-name"><?php echo esc_html($sources[$source] ?? __('Direct','portal-cloud-9')); ?></span>
                    </div>
                    <div class="p9-order-card-footer">
                        <button type="button" class="p9-order-btn p9-order-btn-update">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <?php esc_html_e('Update','portal-cloud-9'); ?>
                        </button>
                        <?php if ($product) : ?>
                        <a href="<?php echo esc_url(home_url('/user-portal/edit-product/' . $product->get_id() . '/')); ?>" class="p9-order-btn p9-order-btn-modify">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <?php esc_html_e('Modify','portal-cloud-9'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <?php if ($manager_total_pages > 1) : ?>
    <!-- Manager Orders Pagination -->
    <div class="p9-orders-pagination">
        <div class="p9-pagination-info">
            <?php 
            $start_item = (($manager_current_page - 1) * $manager_per_page) + 1;
            $end_item = min($manager_current_page * $manager_per_page, $manager_total_orders);
            // translators: 1: First order number, 2: Last order number, 3: Total number of orders
            echo esc_html(sprintf(__('Showing %1$d-%2$d of %3$d orders', 'portal-cloud-9'), absint($start_item), absint($end_item), absint($manager_total_orders))); 
            ?>
        </div>
        <div class="p9-pagination-buttons">
            <?php if ($manager_current_page > 1) : ?>
                <a href="<?php echo esc_url(add_query_arg('orders_page', $manager_current_page - 1)); ?>" class="p9-pagination-btn p9-prev-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                    <?php esc_html_e('Previous', 'portal-cloud-9'); ?>
                </a>
            <?php endif; ?>
            
            <div class="p9-pagination-numbers">
                <?php
                // Show page numbers with ellipsis for large page counts
                $range = 2; // Show 2 pages before and after current
                
                if ($manager_current_page > $range + 1) {
                    echo '<a href="' . esc_url(add_query_arg('orders_page', 1)) . '" class="p9-page-num">1</a>';
                    if ($manager_current_page > $range + 2) {
                        echo '<span class="p9-page-ellipsis">...</span>';
                    }
                }
                
                for ($i = max(1, $manager_current_page - $range); $i <= min($manager_total_pages, $manager_current_page + $range); $i++) {
                    $active = $i === $manager_current_page ? ' active' : '';
                    echo '<a href="' . esc_url(add_query_arg('orders_page', $i)) . '" class="p9-page-num' . esc_attr($active) . '">' . absint($i) . '</a>';
                }
                
                if ($manager_current_page < $manager_total_pages - $range) {
                    if ($manager_current_page < $manager_total_pages - $range - 1) {
                        echo '<span class="p9-page-ellipsis">...</span>';
                    }
                    echo '<a href="' . esc_url(add_query_arg('orders_page', $manager_total_pages)) . '" class="p9-page-num">' . absint($manager_total_pages) . '</a>';
                }
                ?>
            </div>
            
            <?php if ($manager_current_page < $manager_total_pages) : ?>
                <a href="<?php echo esc_url(add_query_arg('orders_page', $manager_current_page + 1)); ?>" class="p9-pagination-btn p9-next-btn">
                    <?php esc_html_e('Next', 'portal-cloud-9'); ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Modal – already handled by orders.js -->
    <?php include PORTALCLOUD9_PLUGIN_PATH.'templates/orders-modal.php'; ?>

<?php endif; // end manager view ?>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>
