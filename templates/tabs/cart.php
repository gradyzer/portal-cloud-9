<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
/**
 * Portal Cloud 9 - Enhanced Cart Template
 * Glassmorphic design with instant AJAX functionality
 * Version: 2.0.0
 */

defined('ABSPATH') || exit;

// Ensure WooCommerce is active
if (!class_exists('WooCommerce')) {
    echo '<div class="p9-error">WooCommerce is required for cart functionality.</div>';
    return;
}

// Get cart data
$cart = WC()->cart;
$cart_items = $cart->get_cart();
$cart_count = $cart->get_cart_contents_count();
$cart_total = $cart->get_cart_total();
$cart_subtotal = $cart->get_cart_subtotal();
$applied_coupons = $cart->get_applied_coupons();
?>

<div class="p9-cart-wrapper">
    <div class="p9-cart-container">
        
        <!-- Header -->
        <header class="p9-cart-header">
            <div class="p9-cart-title-group">
                <div class="p9-cart-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                        <circle cx="9" cy="21" r="1"/>
                        <circle cx="20" cy="21" r="1"/>
                        <path d="m1 1 4 4 1.7 9.4a2 2 0 0 0 2 1.6h9.6a2 2 0 0 0 2-1.6L23 6H6"/>
                    </svg>
                </div>
                <h1 class="p9-cart-title">Shopping Cart</h1>
            </div>
            <div class="p9-cart-count">
                <span class="p9-cart-count-number"><?php echo esc_html($cart_count); ?></span>
                <span>item<?php echo esc_html( $cart_count !== 1 ? 's' : '' ); ?></span>
            </div>
        </header>

        <?php if (empty($cart_items)) : ?>
            
            <!-- Empty Cart State -->
            <div class="p9-empty-cart">
                <div class="p9-empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="9" cy="21" r="1"/>
                        <circle cx="20" cy="21" r="1"/>
                        <path d="m1 1 4 4 1.7 9.4a2 2 0 0 0 2 1.6h9.6a2 2 0 0 0 2-1.6L23 6H6"/>
                    </svg>
                </div>
                <h2 class="p9-empty-title">Your cart is empty</h2>
                <p class="p9-empty-message">Looks like you haven't added anything to your cart yet. Start shopping to fill it up!</p>
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="p9-btn p9-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Start Shopping
                </a>
            </div>

        <?php else : ?>
            
            <!-- Cart Items Section -->
            <section class="p9-cart-items-section">
                <?php foreach ($cart_items as $cart_item_key => $cart_item) :
                    $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
                    $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);
                    
                    if ($_product && $_product->exists() && $cart_item['quantity'] > 0) :
                        $product_permalink = $_product->is_visible() ? $_product->get_permalink($cart_item) : '';
                        $product_name = apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key);
                        $product_price = apply_filters('woocommerce_cart_item_price', WC()->cart->get_product_price($_product), $cart_item, $cart_item_key);
                        $product_subtotal = apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key);
                        $stock_quantity = $_product->get_stock_quantity();
                        $max_qty = $stock_quantity ? $stock_quantity : 9999;
                        $is_on_sale = $_product->is_on_sale();
                        $sku = $_product->get_sku();
                ?>
                    <article class="p9-cart-item" data-cart-key="<?php echo esc_attr($cart_item_key); ?>" data-product-id="<?php echo esc_attr($product_id); ?>">
                        
                        <!-- Product Image -->
                        <div class="p9-cart-item-image">
                            <?php if ($is_on_sale) : ?>
                                <span class="p9-cart-item-badge">Sale</span>
                            <?php endif; ?>
                            <?php if ($product_permalink) : ?>
                                <a href="<?php echo esc_url($product_permalink); ?>">
                                    <?php echo wp_kses_post( $_product->get_image('woocommerce_thumbnail') ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo wp_kses_post( $_product->get_image('woocommerce_thumbnail') ); ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Product Details -->
                        <div class="p9-cart-item-details">
                            <h3 class="p9-cart-item-name">
                                <?php if ($product_permalink) : ?>
                                    <a href="<?php echo esc_url($product_permalink); ?>"><?php echo wp_kses_post($product_name); ?></a>
                                <?php else : ?>
                                    <?php echo wp_kses_post($product_name); ?>
                                <?php endif; ?>
                            </h3>
                            
                            <div class="p9-cart-item-meta">
                                <span class="p9-cart-item-price"><?php echo wp_kses_post( $product_price ); ?></span>
                                <?php if ($sku) : ?>
                                    <span class="p9-cart-item-sku">SKU: <?php echo esc_html($sku); ?></span>
                                <?php endif; ?>
                                <?php
                                // Display variation attributes
                                if ($_product->is_type('variation')) {
                                    $attributes = $_product->get_variation_attributes();
                                    foreach ($attributes as $attr_name => $attr_value) {
                                        if ($attr_value) {
                                            echo '<span class="p9-cart-item-variation">' . esc_html(ucfirst(str_replace('attribute_pa_', '', $attr_name))) . ': ' . esc_html($attr_value) . '</span>';
                                        }
                                    }
                                }
                                ?>
                            </div>
                            
                            <!-- Quantity Controls -->
                            <div class="p9-quantity-wrapper">
                                <div class="p9-quantity-control">
                                    <button type="button" class="p9-qty-btn p9-qty-minus" aria-label="Decrease quantity" <?php echo esc_attr( $cart_item['quantity'] <= 1 ? 'disabled' : '' ); ?>>−</button>
                                    <input type="number" 
                                           class="p9-qty-input" 
                                           value="<?php echo esc_attr($cart_item['quantity']); ?>" 
                                           min="1" 
                                           max="<?php echo esc_attr($max_qty); ?>" 
                                           aria-label="Quantity"
                                           data-cart-key="<?php echo esc_attr($cart_item_key); ?>">
                                    <button type="button" class="p9-qty-btn p9-qty-plus" aria-label="Increase quantity" <?php echo esc_attr( $cart_item['quantity'] >= $max_qty ? 'disabled' : '' ); ?>>+</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Item Actions -->
                        <div class="p9-cart-item-actions">
                            <div class="p9-item-subtotal"><?php echo wp_kses_post( $product_subtotal ); ?></div>
                            <button type="button" class="p9-remove-btn" data-cart-key="<?php echo esc_attr($cart_item_key); ?>" aria-label="Remove <?php echo esc_attr($product_name); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    <line x1="10" y1="11" x2="10" y2="17"/>
                                    <line x1="14" y1="11" x2="14" y2="17"/>
                                </svg>
                                Remove
                            </button>
                        </div>
                    </article>
                <?php endif; ?>
                <?php endforeach; ?>
            </section>

            <!-- Summary Section -->
            <aside class="p9-cart-summary">
                <header class="p9-summary-header">
                    <h2 class="p9-summary-title">Order Summary</h2>
                </header>
                
                <div class="p9-summary-body">
                    <!-- Subtotal -->
                    <div class="p9-summary-row">
                        <span>Subtotal</span>
                        <span id="p9-summary-subtotal"><?php echo wp_kses_post( $cart_subtotal ); ?></span>
                    </div>
                    
                    <!-- Shipping -->
                    <?php if ($cart->needs_shipping() && $cart->show_shipping()) : ?>
                        <div class="p9-summary-row">
                            <span>Shipping</span>
                            <span id="p9-summary-shipping"><?php echo wp_kses_post( WC()->cart->get_cart_shipping_total() ); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Discount -->
                    <?php if ($cart->get_discount_total() > 0) : ?>
                        <div class="p9-summary-row p9-discount">
                            <span>Discount</span>
                            <span id="p9-summary-discount">-<?php echo wp_kses_post( wc_price($cart->get_discount_total()) ); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="p9-summary-row p9-discount" style="display: none;">
                            <span>Discount</span>
                            <span id="p9-summary-discount"></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Total -->
                    <div class="p9-summary-total">
                        <span class="p9-summary-total-label">Total</span>
                        <span class="p9-summary-total-value" id="p9-summary-total"><?php echo wp_kses_post( $cart_total ); ?></span>
                    </div>
                    
                    <!-- Coupon Section -->
                    <div class="p9-coupon-section">
                        <button type="button" class="p9-coupon-toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                                <line x1="7" y1="7" x2="7.01" y2="7"/>
                            </svg>
                            Have a coupon?
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="p9-svg-push-right">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>
                        
                        <div class="p9-coupon-form">
                            <div class="p9-coupon-input-wrap">
                                <input type="text" class="p9-coupon-input" placeholder="Enter coupon code" aria-label="Coupon code">
                                <button type="button" class="p9-coupon-apply">Apply</button>
                            </div>
                            <div class="p9-coupon-message"></div>
                        </div>
                        
                        <!-- Applied Coupons -->
                        <div class="p9-applied-coupons">
                            <?php foreach ($applied_coupons as $coupon_code) : ?>
                                <div class="p9-applied-coupon" data-coupon="<?php echo esc_attr($coupon_code); ?>">
                                    <span class="p9-coupon-code">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                                            <line x1="7" y1="7" x2="7.01" y2="7"/>
                                        </svg>
                                        <?php echo esc_html(strtoupper($coupon_code)); ?>
                                    </span>
                                    <button type="button" class="p9-remove-coupon" data-coupon="<?php echo esc_attr($coupon_code); ?>" aria-label="Remove coupon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <line x1="18" y1="6" x2="6" y2="18"/>
                                            <line x1="6" y1="6" x2="18" y2="18"/>
                                        </svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="p9-summary-actions">
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="p9-btn p9-btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                                <line x1="1" y1="10" x2="23" y2="10"/>
                            </svg>
                            Proceed to Checkout
                        </a>
                        <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="p9-btn p9-btn-outline">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9" cy="21" r="1"/>
                                <circle cx="20" cy="21" r="1"/>
                                <path d="m1 1 4 4 1.7 9.4a2 2 0 0 0 2 1.6h9.6a2 2 0 0 0 2-1.6L23 6H6"/>
                            </svg>
                            View Full Cart
                        </a>
                    </div>
                </div>
            </aside>

        <?php endif; ?>
    </div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>
