<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are function-scoped, not global.
defined('ABSPATH') || exit;

$current_user = wp_get_current_user();
if (in_array('administrator', $current_user->roles) || in_array('shop_manager', $current_user->roles)) {
    wp_die(
        '<h1>Access Denied</h1>
        <p>Favourites are available only for <strong>customers</strong>, <strong>sellers</strong>, and <strong>buyers</strong>.</p>
        <p><a href="' . esc_url(home_url('/user-portal/')) . '">Return to Dashboard</a></p>',
        'Access Denied',
        ['response' => 403]
    );
}

$user_id = get_current_user_id();
$p9Fav = $GLOBALS['portcld9_favourites'] ?? new PortalCloud9_Favourites();
$items = $p9Fav->get_user_favourites($user_id, ['return' => 'objects']);
$count = count($items);

// Check if a product was just added after login
$product_added = 0;
$show_notification = false;
$product_name = '';

if (isset($_GET['fav_added'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter.
    // Product was added after login (nonce already verified in handle_post_login_favourite)
    $product_added = absint($_GET['fav_added']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter.
    
    if ($product_added && wc_get_product($product_added)) {
        $show_notification = true;
        $product = wc_get_product($product_added);
        $product_name = $product->get_name();
    }
}
?>

<div class="p9-favourites-container">
    <?php if ($show_notification): ?>
        <div class="p9-login-success-notification" id="p9-login-notification">
            <div class="p9-notification-content">
                <svg class="p9-notification-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div class="p9-notification-text">
                    <strong>Added to favourites!</strong>
                    <p><?php echo esc_html($product_name); ?> has been saved to your favourites.</p>
                </div>
                <button class="p9-notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        </div>
    <?php endif; ?>
    
    <header class="p9-favourites-header">
        <div>
            <h2>❤️ My Favourites</h2>
            <p>Products you've saved for later</p>
        </div>
        <?php if ($count): ?>
            <div class="p9-favourites-actions">
                <span class="p9-favourites-count">
                    <?php echo absint($count); ?> <?php echo absint($count) === 1 ? 'item' : 'items'; ?>
                </span>
                <label class="p9-bulk-select-label">
                    <input type="checkbox" id="p9-select-all">
                    <span>Select All</span>
                </label>
                <button class="p9-btn p9-btn-outline p9-btn-sm p9-hidden" 
                        data-selected="0" 
                        id="p9-bulk-remove" 
                        type="button">
                    <span class="p9-bulk-text">Remove Selected</span>
                    <span class="p9-bulk-count" style="display:none;margin-left:4px"></span>
                </button>
            </div>
        <?php endif; ?>
    </header>

    <?php if (!$count): ?>
        <div class="p9-empty-favourites">
            <svg class="p9-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <h3>No favourites yet</h3>
            <p>Tap the heart icon on any product to save it here.</p>
            <p class="p9-favourites-hint">Look for the ❤️ icon on product pages.</p>
            <a href="<?php echo esc_url( home_url('/') ); ?>" class="p9-btn p9-btn-primary">Start Shopping</a>
        </div>
    <?php else: ?>
        <div class="p9-favourites-grid">
            <?php foreach ($items as $product):
                if (!$product || !$product->is_visible()) continue;
                $id = $product->get_id();
                $categories = get_the_terms($id, 'product_cat');
                $category_name = '';
                if ($categories && !is_wp_error($categories)) {
                    $category_name = $categories[0]->name;
                }
            ?>
                <article class="p9-favourite-card" data-product-id="<?php echo esc_attr($id); ?>">
                    <input type="checkbox" 
                           class="p9-bulk-checkbox" 
                           data-product-id="<?php echo esc_attr($id); ?>">
                    
                    <button class="p9-remove-fave" 
                            data-product-id="<?php echo esc_attr($id); ?>" 
                            aria-label="Remove from favourites" 
                            title="Remove from favourites">×</button>
                    
                    <div class="p9-favourite-image">
                        <a href="<?php echo esc_url(get_permalink($id)); ?>">
                            <?php echo wp_kses_post( $product->get_image('woocommerce_thumbnail') ); ?>
                        </a>
                        <?php if ($category_name): ?>
                            <span class="p9-category-badge"><?php echo esc_html($category_name); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="p9-favourite-content">
                        <h3 class="p9-favourite-title">
                            <a href="<?php echo esc_url(get_permalink($id)); ?>">
                                <?php echo esc_html($product->get_name()); ?>
                            </a>
                        </h3>

                        <?php if ($product->get_rating_count()): ?>
                            <div class="p9-product-rating">
                                <?php echo wp_kses_post( wc_get_rating_html($product->get_average_rating()) ); ?>
                                <span class="p9-rating-count">(<?php echo absint($product->get_rating_count()); ?>)</span>
                            </div>
                        <?php endif; ?>

                        <div class="p9-favourite-price">
                            <?php echo wp_kses_post( $product->get_price_html() ); ?>
                        </div>

                        <?php
                        $stock = $product->get_stock_quantity();
                        $status = $product->get_stock_status();
                        $class = $status === 'outofstock' ? 'out-stock' : ($stock && $stock <= 5 ? 'low-stock' : 'in-stock');
                        $text = $status === 'outofstock' ? 'Out of Stock' : ($stock && $stock <= 5 ? "Only $stock left" : 'In Stock');
                        ?>
                        <span class="p9-stock-badge p9-<?php echo esc_attr($class); ?>">
                            <?php echo esc_html($text); ?>
                        </span>

                        <div class="p9-favourite-actions">
                            <?php if ($product->is_in_stock()): ?>
                                <?php if ($product->is_type('simple')): ?>
                                    <button class="p9-btn p9-btn-add p9-add-to-cart" 
                                            data-product-id="<?php echo esc_attr($id); ?>"
                                            data-product-sku="<?php echo esc_attr($product->get_sku()); ?>">
                                        <span class="button-text">Add to Cart</span>
                                        <span class="loading-spinner" style="display:none">⏳</span>
                                    </button>
                                <?php else: ?>
                                    <a href="<?php echo esc_url(get_permalink($id)); ?>" class="p9-btn p9-btn-add">
                                        Select Options
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <button class="p9-btn p9-btn-add" disabled>Out of Stock</button>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(get_permalink($id)); ?>" class="p9-btn p9-btn-outline p9-btn-view">
                                View
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="p9-share-wishlist">
            <h3>✨ Share your wishlist</h3>
            <div class="p9-share-buttons">
                <button class="p9-share-btn" data-share="facebook">📘 Facebook</button>
                <button class="p9-share-btn" data-share="twitter">🐦 Twitter</button>
                <button class="p9-share-btn" data-share="whatsapp">💬 WhatsApp</button>
                <button class="p9-share-btn" data-share="copy">📋 Copy Link</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php // NOTE: favourites.css is enqueued via wp_enqueue_scripts (class-favourites.php assets()) ?>

<?php if ($show_notification): ?>

<?php endif; ?>
