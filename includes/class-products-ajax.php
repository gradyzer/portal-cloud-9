<?php
/**
 * Portal Cloud 9 - Products AJAX Handlers
 * Complete version with image upload, product add/update, and taxonomy handlers
 */
defined('ABSPATH') || exit;

/* ==========================================================================
   IMAGE UPLOAD HANDLER
   ========================================================================== */

add_action('wp_ajax_portcld9_upload_image', 'portcld9_ajax_upload_image');

/* ==========================================================================
   SAVE PRODUCT HANDLER (Alias for add-product.js compatibility)
   ========================================================================== */

add_action('wp_ajax_portcld9_save_product', 'portcld9_ajax_save_product');
function portcld9_ajax_save_product() {
    // This is an alias - route to add_product handler
    portcld9_ajax_add_product();
}
function portcld9_ajax_upload_image() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid security token. Please refresh the page.');
    }
    
    // Check login
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to upload images.');
    }
    
    // Check for file
    if (empty($_FILES['file'] /* phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below. */)) {
        wp_send_json_error('No file was uploaded.');
    }
    
    $file = $_FILES['file'] /* phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below. */;
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed_types)) {
        wp_send_json_error('Invalid file type. Please upload JPG, PNG, GIF, or WebP images.');
    }
    
    // Validate file size (10MB max)
    $max_size = 10 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        wp_send_json_error('File size must be less than 10MB.');
    }
    
    // Include required WordPress files for media handling
    if ( ! function_exists( 'media_handle_upload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    
    // Handle the upload
    $attachment_id = media_handle_upload('file', 0);
    
    if (is_wp_error($attachment_id)) {
        wp_send_json_error('Upload failed: ' . $attachment_id->get_error_message());
    }
    
    // Determine which context this upload belongs to: 'featured' or 'gallery'
    $context = (isset($_POST['image_context']) && sanitize_key( wp_unslash( $_POST['image_context'] ) ) === 'gallery') ? 'gallery' : 'featured';
    
    // Fixed at 1:1 square, 700 × 700 px — configurable in Portal Cloud 9 Pro.
    $ratio = '1:1';
    $size  = 700;
    
    // Process image: crop to selected ratio, resize to selected size, convert to WebP
    $processed_url = portcld9_process_uploaded_image($attachment_id, $ratio, $size);
    
    // Get the final URL
    $image_url = $processed_url ?: wp_get_attachment_url($attachment_id);
    
    wp_send_json_success([
        'id'  => $attachment_id,
        'url' => $image_url,
    ]);
}

/**
 * Process an uploaded image: crop to the specified aspect ratio from centre,
 * resize the longest side to $size px, convert to WebP at 90 % quality.
 *
 * @param int    $attachment_id  WordPress attachment ID.
 * @param string $ratio          Aspect ratio string, e.g. '1:1', '16:9', '19:6'. Default '1:1'.
 * @param int    $size           Longest dimension in pixels. Default 700.
 * @return string|false          Final attachment URL, or false on failure.
 */
function portcld9_process_uploaded_image($attachment_id, $ratio = '1:1', $size = 700) {
    $file_path = get_attached_file($attachment_id);
    
    if (!$file_path || !file_exists($file_path)) {
        return false;
    }
    
    // Parse ratio string into numeric width:height parts
    $ratio_parts = explode(':', $ratio);
    $ratio_w = isset($ratio_parts[0]) ? (float)$ratio_parts[0] : 1;
    $ratio_h = isset($ratio_parts[1]) ? (float)$ratio_parts[1] : 1;
    if ($ratio_w <= 0) { $ratio_w = 1; }
    if ($ratio_h <= 0) { $ratio_h = 1; }

    // Derive target width and height from the longest-side size and the ratio
    if ($ratio_w >= $ratio_h) {
        // Landscape or square: size is the width
        $target_w = $size;
        $target_h = (int)round($size * $ratio_h / $ratio_w);
    } else {
        // Portrait: size is the height
        $target_h = $size;
        $target_w = (int)round($size * $ratio_w / $ratio_h);
    }

    // Get image editor
    $editor = wp_get_image_editor($file_path);
    
    if (is_wp_error($editor)) {
        return false;
    }
    
    // Get current dimensions
    $current_size = $editor->get_size();
    $src_w = $current_size['width'];
    $src_h = $current_size['height'];
    
    // Crop to the target aspect ratio from the centre of the source image
    $src_ratio = $src_w / $src_h;
    $tgt_ratio = $ratio_w / $ratio_h;

    if (abs($src_ratio - $tgt_ratio) > 0.001) {
        if ($src_ratio > $tgt_ratio) {
            // Source is wider than target: crop the sides
            $crop_h = $src_h;
            $crop_w = (int)round($src_h * $tgt_ratio);
            $crop_x = (int)round(($src_w - $crop_w) / 2);
            $crop_y = 0;
        } else {
            // Source is taller than target: crop the top and bottom
            $crop_w = $src_w;
            $crop_h = (int)round($src_w / $tgt_ratio);
            $crop_x = 0;
            $crop_y = (int)round(($src_h - $crop_h) / 2);
        }
        $editor->crop($crop_x, $crop_y, $crop_w, $crop_h);
    }

    // Resize to the target dimensions
    $editor->resize($target_w, $target_h, true);
    
    // Set quality to 90 %
    $editor->set_quality(90);
    
    // Try to save as WebP
    $path_info      = pathinfo($file_path);
    $webp_supported = $editor->supports_mime_type('image/webp');
    
    if ($webp_supported) {
        $webp_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
        $saved     = $editor->save($webp_path, 'image/webp');
        
        if (!is_wp_error($saved) && file_exists($webp_path)) {
            $upload_dir    = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'] . '/', '', $webp_path);
            
            update_attached_file($attachment_id, $webp_path);
            update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
            
            wp_update_post([
                'ID'             => $attachment_id,
                'post_mime_type' => 'image/webp',
            ]);
            
            $metadata = wp_generate_attachment_metadata($attachment_id, $webp_path);
            wp_update_attachment_metadata($attachment_id, $metadata);
            
            // Remove the original file if it differs from the WebP path
            if ($file_path !== $webp_path && file_exists($file_path)) {
                wp_delete_file($file_path);
            }
            
            return wp_get_attachment_url($attachment_id);
        }
    }
    
    // Fallback: save in the original format when WebP is unavailable
    $saved = $editor->save($file_path);
    
    if (!is_wp_error($saved)) {
        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $metadata);
    }
    
    return wp_get_attachment_url($attachment_id);
}

/* ==========================================================================
   ADD PRODUCT HANDLER
   ========================================================================== */

add_action('wp_ajax_portcld9_add_product', 'portcld9_ajax_add_product');
function portcld9_ajax_add_product() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid security token. Please refresh the page.');
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to add products.');
    }
    
    if (!function_exists('wc_get_product')) {
        wp_send_json_error('WooCommerce is not active.');
    }
    
    $user_id = get_current_user_id();
    
    // Validate required fields
    $title = sanitize_text_field( wp_unslash( $_POST['product_title'] ?? '') );
    $description = wp_kses_post( wp_unslash( $_POST['product_description'] ?? '' ) );
    $regular_price = floatval($_POST['regular_price'] ?? 0);
    
    if (empty($title)) {
        wp_send_json_error('Product title is required.');
    }
    
    if ($regular_price <= 0) {
        wp_send_json_error('Please enter a valid price.');
    }
    
    // Determine status
    $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'publish') );
    if (!in_array($status, ['publish', 'draft', 'pending'])) {
        $status = 'publish';
    }
    
    // Create the product
    $product = new WC_Product_Simple();
    
    $product->set_name($title);
    $product->set_description($description);
    $product->set_short_description(sanitize_textarea_field( wp_unslash( $_POST['short_description'] ?? '' ) ));
    $product->set_regular_price($regular_price);
    
    // Sale price
    $sale_price = floatval( sanitize_text_field( wp_unslash( $_POST['sale_price'] ?? '0' ) ) );
    if ($sale_price > 0 && $sale_price < $regular_price) {
        $product->set_sale_price($sale_price);
    }
    
    // Stock
    $stock_status = sanitize_text_field( wp_unslash( $_POST['stock_status'] ?? 'instock') );
    $product->set_stock_status($stock_status);
    
    $stock_quantity = isset( $_POST['stock_quantity'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_quantity'] ) ) : '';
    if ($stock_quantity !== '' && is_numeric($stock_quantity)) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity(absint($stock_quantity));
    }
    
    // Dimensions
    if (!empty($_POST['weight'])) $product->set_weight(floatval($_POST['weight']));
    if (!empty($_POST['length'])) $product->set_length(floatval($_POST['length']));
    if (!empty($_POST['width'])) $product->set_width(floatval($_POST['width']));
    if (!empty($_POST['height'])) $product->set_height(floatval($_POST['height']));
    
    // SKU
    $sku = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '') );
    if (empty($sku)) {
        $sku = 'PC9-' . time() . '-' . wp_rand(100, 999);
    }
    
    // Check if SKU exists
    $existing = wc_get_product_id_by_sku($sku);
    if ($existing) {
        $sku = $sku . '-' . wp_rand(100, 999);
    }
    $product->set_sku($sku);
    
    // Featured
    $product->set_featured(!empty($_POST['featured']));
    
    // Status
    $product->set_status($status);
    
    // Featured image
    $image_id = absint($_POST['image_id'] ?? 0);
    if ($image_id) {
        $product->set_image_id($image_id);
    }
    
    // Gallery images
    if (!empty($_POST['gallery_ids'])) {
        $gallery_ids = array_map('absint', array_filter(explode(',', sanitize_text_field( wp_unslash( $_POST['gallery_ids'] ) ))));
        if (!empty($gallery_ids)) {
            $product->set_gallery_image_ids($gallery_ids);
        }
    }
    
    // Save product
    $product_id = $product->save();
    
    if (!$product_id) {
        wp_send_json_error('Failed to create product.');
    }
    
    // Set the author
    wp_update_post([
        'ID'          => $product_id,
        'post_author' => $user_id,
    ]);
    
    // Categories
    if (!empty($_POST['product_cat']) && is_array($_POST['product_cat'])) {
        $cat_ids = array_map('absint', $_POST['product_cat']);
        wp_set_object_terms($product_id, $cat_ids, 'product_cat');
    }
    
    // Tags
    if (!empty($_POST['product_tag']) && is_array($_POST['product_tag'])) {
        $tag_ids = array_map('absint', $_POST['product_tag']);
        wp_set_object_terms($product_id, $tag_ids, 'product_tag');
    }
    
    // Seller phone
    if (!empty($_POST['seller_phone'])) {
        update_post_meta($product_id, '_portalcloud9_seller_phone', sanitize_text_field( wp_unslash( $_POST['seller_phone'] ) ));
    }
    
    wp_send_json_success([
        'product_id'  => $product_id,
        'product_url' => get_permalink($product_id),
        'edit_url'    => home_url('/user-portal/edit-product/' . $product_id . '/'),
        'message'     => 'Product created successfully!',
    ]);
}

/* ==========================================================================
   UPDATE PRODUCT HANDLER
   ========================================================================== */

add_action('wp_ajax_portcld9_update_product', 'portcld9_ajax_update_product');
function portcld9_ajax_update_product() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid security token. Please refresh the page.');
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to update products.');
    }
    
    if (!function_exists('wc_get_product')) {
        wp_send_json_error('WooCommerce is not active.');
    }
    
    $product_id = absint($_POST['product_id'] ?? 0);
    
    if (!$product_id) {
        wp_send_json_error('Invalid product ID.');
    }
    
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error('Product not found.');
    }
    
    // Check ownership
    $post = get_post($product_id);
    if ((int) $post->post_author !== get_current_user_id()) {
        wp_send_json_error('You do not have permission to edit this product.');
    }
    
    // Validate required fields
    $title = sanitize_text_field( wp_unslash( $_POST['product_title'] ?? '') );
    $description = wp_kses_post( wp_unslash( $_POST['product_description'] ?? '' ) );
    $regular_price = floatval($_POST['regular_price'] ?? 0);
    
    if (empty($title)) {
        wp_send_json_error('Product title is required.');
    }
    
    if ($regular_price <= 0) {
        wp_send_json_error('Please enter a valid price.');
    }
    
    // Determine status
    $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'publish') );
    if (!in_array($status, ['publish', 'draft', 'pending'])) {
        $status = 'publish';
    }
    
    // Update product
    $product->set_name($title);
    $product->set_description($description);
    $product->set_short_description(sanitize_textarea_field( wp_unslash( $_POST['short_description'] ?? '' ) ));
    $product->set_regular_price($regular_price);
    
    // Sale price
    $sale_price = floatval( sanitize_text_field( wp_unslash( $_POST['sale_price'] ?? '0' ) ) );
    if ($sale_price > 0 && $sale_price < $regular_price) {
        $product->set_sale_price($sale_price);
    } else {
        $product->set_sale_price('');
    }
    
    // Stock
    $stock_status = sanitize_text_field( wp_unslash( $_POST['stock_status'] ?? 'instock') );
    $product->set_stock_status($stock_status);
    
    $stock_quantity = isset( $_POST['stock_quantity'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_quantity'] ) ) : '';
    if ($stock_quantity !== '' && is_numeric($stock_quantity)) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity(absint($stock_quantity));
    } else {
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null);
    }
    
    // Dimensions
    $product->set_weight(!empty($_POST['weight']) ? floatval($_POST['weight']) : '');
    $product->set_length(!empty($_POST['length']) ? floatval($_POST['length']) : '');
    $product->set_width(!empty($_POST['width']) ? floatval($_POST['width']) : '');
    $product->set_height(!empty($_POST['height']) ? floatval($_POST['height']) : '');
    
    // SKU
    $sku = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '') );
    if (!empty($sku)) {
        $existing = wc_get_product_id_by_sku($sku);
        if ($existing && $existing !== $product_id) {
            $sku = $sku . '-' . wp_rand(100, 999);
        }
        $product->set_sku($sku);
    }
    
    // Featured
    $product->set_featured(!empty($_POST['featured']));
    
    // Status
    $product->set_status($status);
    
    // Featured image
    $image_id = absint($_POST['image_id'] ?? 0);
    $product->set_image_id($image_id ?: 0);
    
    // Gallery images
    $gallery_ids = [];
    if (!empty($_POST['gallery_ids'])) {
        $gallery_ids = array_map('absint', array_filter(explode(',', sanitize_text_field( wp_unslash( $_POST['gallery_ids'] ) ))));
    }
    $product->set_gallery_image_ids($gallery_ids);
    
    // Save product
    $product->save();
    
    // Categories
    if (isset($_POST['product_cat']) && is_array($_POST['product_cat'])) {
        $cat_ids = array_map('absint', $_POST['product_cat']);
        wp_set_object_terms($product_id, $cat_ids, 'product_cat');
    } else {
        wp_set_object_terms($product_id, [], 'product_cat');
    }
    
    // Tags
    if (isset($_POST['product_tag']) && is_array($_POST['product_tag'])) {
        $tag_ids = array_map('absint', $_POST['product_tag']);
        wp_set_object_terms($product_id, $tag_ids, 'product_tag');
    } else {
        wp_set_object_terms($product_id, [], 'product_tag');
    }
    
    // Seller phone
    if (isset($_POST['seller_phone'])) {
        update_post_meta($product_id, '_portalcloud9_seller_phone', sanitize_text_field( wp_unslash( $_POST['seller_phone'] ) ));
    }
    
    wp_send_json_success([
        'product_id'  => $product_id,
        'product_url' => get_permalink($product_id),
        'edit_url'    => home_url('/user-portal/edit-product/' . $product_id . '/'),
        'message'     => 'Product updated successfully!',
    ]);
}

/* ==========================================================================
   ADD CATEGORY HANDLER
   ========================================================================== */

add_action('wp_ajax_portcld9_add_category', 'portcld9_ajax_add_category');
function portcld9_ajax_add_category() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid security token.');
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in.');
    }
    
    $category_name = sanitize_text_field( wp_unslash( $_POST['category_name'] ?? '') );
    
    if (empty($category_name)) {
        wp_send_json_error('Category name is required.');
    }
    
    // Check if category already exists
    $existing = get_term_by('name', $category_name, 'product_cat');
    
    if ($existing) {
        wp_send_json_success([
            'category' => [
                'term_id' => $existing->term_id,
                'name'    => $existing->name,
                'slug'    => $existing->slug,
            ],
            'message' => 'Category already exists.',
        ]);
    }
    
    // Create new category
    $result = wp_insert_term($category_name, 'product_cat');
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    
    $term = get_term($result['term_id'], 'product_cat');
    
    wp_send_json_success([
        'category' => [
            'term_id' => $term->term_id,
            'name'    => $term->name,
            'slug'    => $term->slug,
        ],
        'message' => 'Category created successfully.',
    ]);
}

/* ==========================================================================
   CREATE TAGS HANDLER
   ========================================================================== */

add_action('wp_ajax_portcld9_create_tags', 'portcld9_ajax_create_tags');
function portcld9_ajax_create_tags() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid security token.');
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in.');
    }
    
    $tags_input = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '') );
    
    if (empty($tags_input)) {
        wp_send_json_error('Tags are required.');
    }
    
    // Split by comma
    $tag_names = array_map('trim', explode(',', $tags_input));
    $tag_names = array_filter($tag_names);
    
    if (empty($tag_names)) {
        wp_send_json_error('Please enter valid tag names.');
    }
    
    $created_tags = [];
    
    foreach ($tag_names as $tag_name) {
        $tag_name = sanitize_text_field($tag_name);
        
        if (empty($tag_name)) {
            continue;
        }
        
        // Check if tag exists
        $existing = get_term_by('name', $tag_name, 'product_tag');
        
        if ($existing) {
            $created_tags[] = [
                'term_id' => $existing->term_id,
                'name'    => $existing->name,
                'slug'    => $existing->slug,
            ];
            continue;
        }
        
        // Create new tag
        $result = wp_insert_term($tag_name, 'product_tag');
        
        if (!is_wp_error($result)) {
            $term = get_term($result['term_id'], 'product_tag');
            $created_tags[] = [
                'term_id' => $term->term_id,
                'name'    => $term->name,
                'slug'    => $term->slug,
            ];
        }
    }
    
    if (empty($created_tags)) {
        wp_send_json_error('Failed to create tags.');
    }
    
    wp_send_json_success([
        'tags'    => $created_tags,
        'message' => count($created_tags) . ' tag(s) created/found.',
    ]);
}

/* ==========================================================================
   EXISTING HANDLERS (from your original file)
   ========================================================================== */

// Main products loading handler
add_action('wp_ajax_portcld9_load_products', 'portcld9_ajax_load_products');
// Alias for products.js compatibility
add_action('wp_ajax_portcld9_get_products', 'portcld9_ajax_load_products');

function portcld9_ajax_load_products() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $page     = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 15;
    $search   = isset($_POST['search']) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
    $category = isset($_POST['category']) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
    $status   = isset($_POST['status']) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'any';
    $stock    = isset($_POST['stock']) ? sanitize_text_field( wp_unslash( $_POST['stock'] ) ) : 'any';
    
    $meta_query = ['relation' => 'AND'];
    
    if ($stock === 'instock') {
        $meta_query[] = [
            'key'     => '_stock_status',
            'value'   => 'instock',
            'compare' => '=',
        ];
    } elseif ($stock === 'outofstock') {
        $meta_query[] = [
            'key'     => '_stock_status',
            'value'   => 'outofstock',
            'compare' => '=',
        ];
    } elseif ($stock === 'low') {
        $meta_query[] = [
            'key'     => '_stock',
            'value'   => 5,
            'type'    => 'numeric',
            'compare' => '<=',
        ];
        $meta_query[] = [
            'key'     => '_stock_status',
            'value'   => 'instock',
            'compare' => '=',
        ];
    }
    
    $args = [
        'post_type'      => 'product',
        'post_status'    => ($status === 'any') ? ['publish', 'draft', 'private'] : $status,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'author'         => get_current_user_id(),
        'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for product status filtering.
    ];
    
    if (!empty($search)) {
        $args['s'] = $search;
    }
    
    if (!empty($category)) {
        $args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for product category filtering.
            [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $category,
            ],
        ];
    }
    
    $q = new WP_Query($args);
    $products = [];
    
    while ($q->have_posts()) {
        $q->the_post();
        $product = wc_get_product(get_the_ID());
        
        $regular = wc_price(wc_get_price_to_display($product, ['price' => $product->get_regular_price()]));
        $sale = $product->get_sale_price() 
            ? wc_price(wc_get_price_to_display($product, ['price' => $product->get_sale_price()])) 
            : '';
        
        $stock_qty = $product->get_stock_quantity();
        $stock_class = $product->get_stock_status();
        
        if ($stock_class === 'instock' && $stock_qty !== null && $stock_qty <= 5) {
            $stock_class = 'low';
        }
        
        $products[] = [
            'id'                 => $product->get_id(),
            'title'              => $product->get_name(),
            'image'              => wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src(),
            'regular_price_html' => $regular,
            'sale_price_html'    => $sale,
            'status'             => $product->get_status(),
            'category_name'      => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names'])[0] ?? '',
            'stock_class'        => $stock_class,
            'stock_text'         => $stock_class === 'instock' 
                ? ($stock_qty !== null ? "In stock ($stock_qty)" : 'In stock') 
                : ($stock_class === 'outofstock' ? 'Out of stock' : 'Low stock'),
            'sku'                => $product->get_sku(),
        ];
    }
    
    wp_reset_postdata();
    
    wp_send_json_success([
        'products'   => $products,
        'pagination' => [
            'current_page' => $page,
            'total_pages'  => $q->max_num_pages,
            'total'        => $q->found_posts,
        ],
    ]);
}

add_action('wp_ajax_portcld9_bulk_update_status', 'portcld9_ajax_bulk_update_status');
function portcld9_ajax_bulk_update_status() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];
    $status = isset($_POST['status']) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'publish';
    
    if (empty($product_ids)) {
        wp_send_json_error('No products selected');
    }
    
    if (!in_array($status, ['publish', 'draft'], true)) {
        wp_send_json_error('Invalid status');
    }
    
    $updated = 0;
    
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product || $product->get_post_data()->post_author != get_current_user_id()) {
            continue;
        }
        
        wp_update_post([
            'ID'          => $product_id,
            'post_status' => $status,
        ]);
        
        $updated++;
    }
    
    wp_send_json_success([
        'message' => sprintf('%d product(s) updated to %s', $updated, $status),
        'updated' => $updated,
    ]);
}

add_action('wp_ajax_portcld9_bulk_delete_products', 'portcld9_ajax_bulk_delete_products');
// Alias for products.js compatibility
add_action('wp_ajax_portcld9_bulk_products', 'portcld9_ajax_bulk_products_handler');

function portcld9_ajax_bulk_products_handler() {
    // Route to appropriate handler based on bulk_action parameter
    $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Bulk action verified in sub-handlers.
    
    if ($bulk_action === 'delete') {
        portcld9_ajax_bulk_delete_products();
    } else {
        wp_send_json_error('Invalid bulk action');
    }
}

function portcld9_ajax_bulk_delete_products() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];
    
    if (empty($product_ids)) {
        wp_send_json_error('No products selected');
    }
    
    $deleted = 0;
    
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product || $product->get_post_data()->post_author != get_current_user_id()) {
            continue;
        }
        
        if (wp_trash_post($product_id)) {
            $deleted++;
        }
    }
    
    wp_send_json_success([
        'message' => sprintf('%d product(s) deleted', $deleted),
        'deleted' => $deleted,
    ]);
}

add_action('wp_ajax_portcld9_delete_product', 'portcld9_ajax_delete_product');
function portcld9_ajax_delete_product() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    if (!$product_id) {
        wp_send_json_error('Invalid product ID');
    }
    
    $product = wc_get_product($product_id);
    
    if (!$product || $product->get_post_data()->post_author != get_current_user_id()) {
        wp_send_json_error('You do not own this product');
    }
    
    if (wp_trash_post($product_id)) {
        wp_send_json_success([
            'message' => 'Product deleted successfully',
        ]);
    } else {
        wp_send_json_error('Failed to delete product');
    }
}
