<?php
/**
 * Products Class
 * Handles product CRUD operations and image processing
 * 
 * @package Portal_Cloud_9
 */

defined('ABSPATH') || exit;

/**
 * Portal Cloud 9 Products Handler
 */
class PortalCloud9_Products
{
    /**
     * Constructor - Register hooks
     */
    public function __construct()
    {
        add_action('wp_ajax_portcld9_save_product', [$this, 'save_product']);
        add_action('wp_ajax_portcld9_update_product', [$this, 'update_product']);
        add_action('wp_ajax_portcld9_delete_product', [$this, 'delete_product']);
        add_action('wp_ajax_portcld9_upload_image', [$this, 'upload_image']);
        add_action('wp_ajax_nopriv_portcld9_upload_image', [$this, 'upload_image']);
        add_action('wp_ajax_portcld9_add_category', [$this, 'add_category']);
        add_action('wp_ajax_portcld9_create_tags', [$this, 'create_tags']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_products_assets']);
    }
    
    /**
     * Enqueue products-specific assets
     */
    public function enqueue_products_assets()
    {
        if (get_query_var('portalcloud9_tab') !== 'products') {
            return;
        }
        
        $ver = defined('PORTALCLOUD9_VERSION') ? PORTALCLOUD9_VERSION : '1.0';
        wp_enqueue_style('portalcloud9-products', PORTALCLOUD9_PLUGIN_URL . 'assets/css/products.css', ['portalcloud9-fontawesome'], $ver);
    }
    
    /**
     * Verify nonce and user capabilities
     */
    private function verify_nonce()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : ( isset($_POST['portcld9_nonce_field']) ? sanitize_text_field( wp_unslash( $_POST['portcld9_nonce_field'] ) ) : '' );
        
        if (!wp_verify_nonce($nonce, 'portalcloud9_nonce')) {
            wp_send_json_error('Invalid nonce', 403);
        }
        
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Insufficient permissions', 403);
        }
    }
    
    /**
     * Save new product via AJAX
     */
    public function save_product(): void
    {
        $this->verify_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_nonce() above.
        // Sanitize inputs
        $status = isset($_POST['status']) && in_array(sanitize_key( wp_unslash( $_POST['status'] ) ), ['publish', 'draft'], true) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';
        $title = sanitize_text_field( wp_unslash( $_POST['product_title'] ?? '') );
        $description = wp_kses_post( wp_unslash( $_POST['product_description'] ?? '' ) );
        $short_desc = wp_kses_post( wp_unslash( $_POST['short_description'] ?? '' ) );
        $regular = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['regular_price'] ?? '' ) ) );
        $sale = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['sale_price'] ?? '' ) ) );
        $image_id = absint($_POST['image_id'] ?? 0);
        
        if (!$title) {
            wp_send_json_error('Product title is required.');
        }
        
        // Create new product
        $product = new \WC_Product_Simple();
        $product->set_name($title);
        $product->set_description($description);
        $product->set_short_description($short_desc);
        $product->set_status($status);
        $product->set_regular_price($regular);
        
        if ($sale > 0) {
            $product->set_sale_price($sale);
        }
        
        $product->set_price($sale > 0 ? $sale : $regular);
        
        if ($image_id) {
            $product->set_image_id($image_id);
        }
        
        // Set SKU
        $sku = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '') );
        if ($sku) {
            try {
                $product->set_sku($sku);
            } catch (Exception $e) {
                // SKU already exists, ignore
            }
        }
        
        // Set featured status
        $is_featured = !empty($_POST['featured']);
        if (method_exists($product, 'set_featured')) {
            $product->set_featured($is_featured);
        }
        
        // Stock management
        $stock_status_raw = sanitize_text_field( wp_unslash( $_POST['stock_status'] ?? 'instock' ) );
        $stock_status      = in_array( $stock_status_raw, [ 'instock', 'outofstock', 'onbackorder' ], true ) ? $stock_status_raw : 'instock';
        $stock_quantity = wc_stock_amount( sanitize_text_field( wp_unslash( $_POST['stock_quantity'] ?? '' ) ) );
        
        if ($stock_quantity !== '' && is_numeric($stock_quantity)) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $stock_quantity);
            $product->set_stock_status($stock_status);
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status($stock_status);
        }
        
        // Dimensions and weight
        $weight = sanitize_text_field( wp_unslash( $_POST['weight'] ?? '') );
        $length = sanitize_text_field( wp_unslash( $_POST['length'] ?? '') );
        $width = sanitize_text_field( wp_unslash( $_POST['width'] ?? '') );
        $height = sanitize_text_field( wp_unslash( $_POST['height'] ?? '') );
        
        if ($weight) {
            $product->set_weight($weight);
        }
        if ($length) {
            $product->set_length($length);
        }
        if ($width) {
            $product->set_width($width);
        }
        if ($height) {
            $product->set_height($height);
        }
        
        $product->save();
        
        // Save seller phone
        if (isset($_POST['seller_phone'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta($product->get_id(), '_portalcloud9_seller_phone', sanitize_text_field( wp_unslash( $_POST['seller_phone'] ) ));
        }
        
        // Fallback for featured if method doesn't exist
        if (!method_exists($product, 'set_featured')) {
            update_post_meta($product->get_id(), '_featured', $is_featured ? 'yes' : 'no');
        }
        
        // Set categories and tags
        $this->set_terms($product->get_id());
        
        // Set gallery images
        if (isset($_POST['gallery_ids'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $gallery_ids = sanitize_text_field( wp_unslash( $_POST['gallery_ids'] ) );
            if (!empty($gallery_ids)) {
                update_post_meta($product->get_id(), '_product_image_gallery', $gallery_ids);
            } else {
                delete_post_meta($product->get_id(), '_product_image_gallery');
            }
        }
        
        wp_send_json_success([
            'id' => $product->get_id(),
            'product_url' => get_permalink($product->get_id()),
            'edit_url' => home_url('/user-portal/edit-product/' . $product->get_id() . '/'),
            'redirect' => home_url('/user-portal/products/'),
        ]);
    }
    
    /**
     * Update existing product via AJAX
     */
    public function update_product(): void
    {
        $this->verify_nonce();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_nonce() above.
        
        $product_id = absint($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product || (int) get_post_field('post_author', $product->get_id()) !== get_current_user_id()) {
            wp_send_json_error('Product not found or insufficient permissions.');
        }
        
        // Sanitize inputs
        $status = isset($_POST['status']) && in_array(sanitize_key( wp_unslash( $_POST['status'] ) ), ['publish', 'draft'], true) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';
        $title = sanitize_text_field( wp_unslash( $_POST['product_title'] ?? '') );
        $description = wp_kses_post( wp_unslash( $_POST['product_description'] ?? '' ) );
        $short_desc = wp_kses_post( wp_unslash( $_POST['short_description'] ?? '' ) );
        $regular = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['regular_price'] ?? '' ) ) );
        $sale = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['sale_price'] ?? '' ) ) );
        $image_id = absint($_POST['image_id'] ?? 0);
        
        if ($title) {
            $product->set_name($title);
        }
        
        $product->set_description($description);
        $product->set_short_description($short_desc);
        $product->set_status($status);
        $product->set_regular_price($regular);
        $product->set_sale_price($sale > 0 ? $sale : '');
        $product->set_price($sale > 0 ? $sale : $regular);
        
        if ($image_id) {
            $product->set_image_id($image_id);
        }
        
        // Set SKU
        $sku = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '') );
        if ($sku) {
            try {
                $product->set_sku($sku);
            } catch (Exception $e) {
                // SKU already exists, ignore
            }
        }
        
        // Set featured status
        $is_featured = !empty($_POST['featured']);
        if (method_exists($product, 'set_featured')) {
            $product->set_featured($is_featured);
        }
        
        // Stock management
        $stock_status_raw = sanitize_text_field( wp_unslash( $_POST['stock_status'] ?? 'instock' ) );
        $stock_status      = in_array( $stock_status_raw, [ 'instock', 'outofstock', 'onbackorder' ], true ) ? $stock_status_raw : 'instock';
        $stock_quantity = wc_stock_amount( sanitize_text_field( wp_unslash( $_POST['stock_quantity'] ?? '' ) ) );
        
        if ($stock_quantity !== '' && is_numeric($stock_quantity)) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $stock_quantity);
            $product->set_stock_status($stock_status);
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status($stock_status);
        }
        
        // Dimensions and weight
        $weight = sanitize_text_field( wp_unslash( $_POST['weight'] ?? '') );
        $length = sanitize_text_field( wp_unslash( $_POST['length'] ?? '') );
        $width = sanitize_text_field( wp_unslash( $_POST['width'] ?? '') );
        $height = sanitize_text_field( wp_unslash( $_POST['height'] ?? '') );
        
        if ($weight) {
            $product->set_weight($weight);
        }
        if ($length) {
            $product->set_length($length);
        }
        if ($width) {
            $product->set_width($width);
        }
        if ($height) {
            $product->set_height($height);
        }
        
        $product->save();
        
        // Save seller phone
        if (isset($_POST['seller_phone'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta($product->get_id(), '_portalcloud9_seller_phone', sanitize_text_field( wp_unslash( $_POST['seller_phone'] ) ));
        }
        
        // Fallback for featured if method doesn't exist
        if (!method_exists($product, 'set_featured')) {
            update_post_meta($product->get_id(), '_featured', $is_featured ? 'yes' : 'no');
        }
        
        // Update categories and tags
        $this->set_terms($product_id);
        
        // Update gallery images
        if (isset($_POST['gallery_ids'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $gallery_ids = sanitize_text_field( wp_unslash( $_POST['gallery_ids'] ) );
            if (!empty($gallery_ids)) {
                update_post_meta($product_id, '_product_image_gallery', $gallery_ids);
            } else {
                delete_post_meta($product_id, '_product_image_gallery');
            }
        }
        
        wp_send_json_success([
            'id' => $product_id,
            'product_url' => get_permalink($product_id),
            'edit_url' => home_url('/user-portal/edit-product/' . $product_id . '/'),
            'redirect' => home_url('/user-portal/products/'),
        ]);
    }
    
    /**
     * Delete product via AJAX
     */
    public function delete_product(): void
    {
        $this->verify_nonce();
        
        $product_id = absint($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID.');
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product || (int) get_post_field('post_author', $product->get_id()) !== get_current_user_id()) {
            wp_send_json_error('You do not have permission to delete this product.');
        }
        
        $result = $product->delete(true);
        
        if ($result) {
            wp_send_json_success('Product deleted successfully.');
        } else {
            wp_send_json_error('Failed to delete product.');
        }
    }
    
    /**
     * Upload product image via AJAX
     */
    public function upload_image(): void
    {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        
        if (!wp_verify_nonce($nonce, 'portalcloud9_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions to upload files.');
        }
        
        if (empty($_FILES['file'])) {
            wp_send_json_error('No file uploaded.');
        }
        
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated via allowed_types and size check below.
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        if (!in_array($file['type'], $allowed_types, true)) {
            wp_send_json_error('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            wp_send_json_error('File size exceeds 10 MB limit.');
        }
        
        $upload_overrides = ['test_form' => false];
        $movefile = wp_handle_upload($file, $upload_overrides);
        
        if (isset($movefile['error'])) {
            wp_send_json_error($movefile['error']);
        }
        
        $uploaded_file_path = $movefile['file'];
        $processed_file = $this->process_product_image($uploaded_file_path);
        
        if (is_wp_error($processed_file)) {
            $final_file = $uploaded_file_path;
            $final_mime = $movefile['type'];
        } else {
            if ($processed_file !== $uploaded_file_path && file_exists($uploaded_file_path)) {
                wp_delete_file($uploaded_file_path);
            }
            $final_file = $processed_file;
            $final_mime = 'image/webp';
        }
        
        $attachment = [
            'post_mime_type' => $final_mime,
            'post_title' => sanitize_file_name(pathinfo($final_file, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ];
        
        $attach_id = wp_insert_attachment($attachment, $final_file);
        
        if (is_wp_error($attach_id)) {
            wp_send_json_error('Failed to create attachment.');
        }
        
        $attach_data = wp_generate_attachment_metadata($attach_id, $final_file);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        wp_send_json_success([
            'id' => $attach_id,
            'url' => wp_get_attachment_url($attach_id),
        ]);
    }
    
    /**
     * Process product image - crop to square and resize to 700x700
     * 
     * @param string $file_path Path to uploaded image
     * @return string|WP_Error Path to processed image or error
     */
    private function process_product_image($file_path)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return new WP_Error('gd_not_available', 'GD library not available');
        }
        
        $image_info = @getimagesize($file_path);
        
        if (!$image_info) {
            return new WP_Error('invalid_image', 'Could not read image');
        }
        
        list($width, $height, $type) = $image_info;
        
        // Create image resource based on type
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($file_path);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($file_path);
                break;
            case IMAGETYPE_GIF:
                $source = @imagecreatefromgif($file_path);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $source = @imagecreatefromwebp($file_path);
                } else {
                    return new WP_Error('webp_not_supported', 'WebP not supported');
                }
                break;
            default:
                return new WP_Error('unsupported_type', 'Unsupported image type');
        }
        
        if (!$source) {
            return new WP_Error('image_create_failed', 'Failed to create image resource');
        }
        
        // Calculate crop dimensions for square
        $output_size = 700;
        $size = min($width, $height);
        $src_x = ($width - $size) / 2;
        $src_y = ($height - $size) / 2;
        
        // Create destination image
        $destination = imagecreatetruecolor($output_size, $output_size);
        imagealphablending($destination, false);
        imagesavealpha($destination, true);
        
        // Resample image
        imagecopyresampled(
            $destination,
            $source,
            0, 0,
            $src_x, $src_y,
            $output_size, $output_size,
            $size, $size
        );
        
        // Save as WebP or JPEG
        $path_parts = pathinfo($file_path);
        $webp_file = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.webp';
        
        if (function_exists('imagewebp')) {
            $saved = @imagewebp($destination, $webp_file, 90);
        } else {
            $webp_file = $path_parts['dirname'] . '/' . $path_parts['filename'] . '-700x700.jpg';
            $saved = @imagejpeg($destination, $webp_file, 90);
        }
        
        imagedestroy($source);
        imagedestroy($destination);
        
        if (!$saved) {
            return new WP_Error('save_failed', 'Failed to save processed image');
        }
        
        return $webp_file;
    }
    
    /**
     * Add product category via AJAX
     */
    public function add_category(): void
    {
        $this->verify_nonce();
        
        $name = sanitize_text_field( wp_unslash( $_POST['category_name'] ?? '') );
        
        if (!$name) {
            wp_send_json_error('Category name is required.');
        }
        
        $term = wp_insert_term($name, 'product_cat');
        
        if (is_wp_error($term)) {
            wp_send_json_error($term->get_error_message());
        }
        
        $term_obj = get_term($term['term_id'], 'product_cat');
        
        wp_send_json_success(['category' => (array) $term_obj]);
    }
    
    /**
     * Create product tags via AJAX
     */
    public function create_tags(): void
    {
        $this->verify_nonce();
        
        $raw_tags = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '') );
        
        if (!$raw_tags) {
            wp_send_json_error('No tags provided.');
        }
        
        $tag_names = array_map('trim', explode(',', $raw_tags));
        $tag_names = array_filter($tag_names);
        
        if (empty($tag_names)) {
            wp_send_json_error('No valid tags provided.');
        }
        
        $created = [];
        
        foreach ($tag_names as $tag_name) {
            $term = wp_insert_term($tag_name, 'product_tag');
            
            if (is_wp_error($term)) {
                if (isset($term->error_data['term_exists'])) {
                    $term_id = $term->error_data['term_exists'];
                } else {
                    continue;
                }
            } else {
                $term_id = $term['term_id'];
            }
            
            $term_obj = get_term($term_id, 'product_tag');
            
            if ($term_obj && !is_wp_error($term_obj)) {
                $created[] = (array) $term_obj;
            }
        }
        
        wp_send_json_success(['tags' => $created]);
    }
    
    /**
     * Set product categories and tags
     * 
     * @param int $product_id Product ID
     */
    private function set_terms(int $product_id): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from nonce-verified AJAX handlers.
        $categories = isset($_POST['product_cat']) && is_array($_POST['product_cat']) ? array_map('absint', $_POST['product_cat']) : [];
        $tags = isset($_POST['product_tag']) && is_array($_POST['product_tag']) ? array_map('absint', $_POST['product_tag']) : [];
        
        wp_set_object_terms($product_id, $categories, 'product_cat', false);
        wp_set_object_terms($product_id, $tags, 'product_tag', false);
    }
}

// Initialize
new PortalCloud9_Products();
