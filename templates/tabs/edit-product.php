<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
defined('ABSPATH') || exit;

$product_id = absint(get_query_var('portcld9_edit_id', 0));
$product = wc_get_product($product_id);

if (!$product || (int) get_post_field('post_author', $product->get_id()) !== get_current_user_id()) {
    echo '<div class="p9-error">Product not found or you do not have permission to edit it.</div>';
    return;
}

$currency = get_woocommerce_currency_symbol();
$image_id = $product->get_image_id();
$image_url = $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : '';
$sel_cats = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'ids']);
$sel_tags = wp_get_object_terms($product_id, 'product_tag', ['fields' => 'ids']);
$upload_nonce = wp_create_nonce('portalcloud9_upload_product_image');
$gallery_ids = $product->get_gallery_image_ids();

/* ── Read image-processing settings ─────────────────────────────────────── */
$_pc9_opts = get_option('portalcloud9_options', []);

// Images fixed at 1:1, 700 × 700 px.
$_pc9_feat_ratio = '1:1'; $_pc9_feat_size = 700;
$_pc9_gal_ratio  = '1:1'; $_pc9_gal_size  = 700;

if (!function_exists('_pc9_ratio_css')) {
    function _pc9_ratio_css($r) {
        $p = explode(':', $r); return $p[0] . ' / ' . $p[1];
    }
    function _pc9_ratio_dims($r, $size) {
        $p = explode(':', $r); $rw = (float)$p[0]; $rh = (float)$p[1];
        if ($rw >= $rh) { $w = $size; $h = (int)round($size * $rh / $rw); }
        else            { $h = $size; $w = (int)round($size * $rw / $rh); }
        return $w . ' × ' . $h . ' px';
    }
    function _pc9_ratio_indicator($r, $pct = 76) {
        $parts = explode(':', $r);
        $rw = (float)$parts[0]; $rh = (float)$parts[1];
        if ($rw >= $rh) { $w = $pct; $h = round($pct * $rh / $rw, 2); }
        else            { $h = $pct; $w = round($pct * $rw / $rh, 2); }
        return ['w' => $w, 'h' => $h];
    }
}

$_pc9_feat_css  = _pc9_ratio_css($_pc9_feat_ratio);
$_pc9_feat_dims = _pc9_ratio_dims($_pc9_feat_ratio, $_pc9_feat_size);
$_pc9_gal_css   = _pc9_ratio_css($_pc9_gal_ratio);
$_pc9_gal_dims  = _pc9_ratio_dims($_pc9_gal_ratio, $_pc9_gal_size);

/* Indicator percentages for the ratio frame inside the 1:1 viewport */
$_pc9_feat_ind  = _pc9_ratio_indicator($_pc9_feat_ratio);
$_pc9_gal_ind   = _pc9_ratio_indicator($_pc9_gal_ratio);
$_pc9_feat_ind_style = '--p9-ind-w:' . $_pc9_feat_ind['w'] . '%;--p9-ind-h:' . $_pc9_feat_ind['h'] . '%';
$_pc9_gal_ind_style  = '--p9-ind-w:' . $_pc9_gal_ind['w']  . '%;--p9-ind-h:' . $_pc9_gal_ind['h']  . '%';

$_pc9_feat_hint = 'JPG, PNG, GIF, WebP ≤ 10 MB';
if ($_pc9_feat_ratio !== '1:1' || $_pc9_feat_size !== 700) {
    $_pc9_feat_hint .= ' — saved as ' . esc_html($_pc9_feat_ratio) . ' WebP (' . esc_html($_pc9_feat_dims) . ')';
} else {
    $_pc9_feat_hint .= ' — auto-converted to 700 × 700 WebP';
}

$_pc9_gal_hint = 'JPG, PNG, GIF, WebP • Max 4 images • ≤ 10 MB each';
if ($_pc9_gal_ratio !== '1:1' || $_pc9_gal_size !== 700) {
    $_pc9_gal_hint .= ' — saved as ' . esc_html($_pc9_gal_ratio) . ' WebP (' . esc_html($_pc9_gal_dims) . ')';
}
?>


<?php // NOTE: add-product.css (shared with edit) is enqueued via wp_enqueue_scripts in portal-cloud-9.php ?>

<div class="p9-add-product-wrap">
    <div class="p9-edit-topbar">
        <a class="p9-btn p9-btn-outline" href="<?php echo esc_url( home_url('/user-portal/products/') ); ?>">
            <span>←</span> Back to Products
        </a>
        <div></div>
        <div></div>
    </div>

    <form enctype="multipart/form-data" id="p9-edit-product-form">
        <?php wp_nonce_field('portalcloud9_nonce', 'portcld9_nonce_field'); ?>
        <input name="product_id" value="<?php echo absint( $product_id ); ?>" type="hidden">
        <input name="image_id" value="<?php echo esc_attr($image_id); ?>" type="hidden" id="p9-featured-image-id">
        <input name="portalcloud9_upload_nonce" value="<?php echo esc_attr($upload_nonce); ?>" type="hidden">

        <div class="p9-form-grid">
            <!-- LEFT COLUMN -->
            <div class="p9-form-left">
                <!-- Product Information -->
                <div class="p9-glass-card">
                    <h3 class="p9-card-title">📝 Product Information</h3>
                    <div class="p9-field">
                        <label>Product Title <span>*</span></label>
                        <input name="product_title" value="<?php echo esc_attr($product->get_name()); ?>" placeholder="Enter product name…" required maxlength="200">
                    </div>
                    <div class="p9-field">
                        <label>Description <span>*</span></label>
                        <div class="p9-rich-editor">
                            <div class="p9-editor-toolbar">
                                <button type="button" data-command="bold"><b>B</b></button>
                                <button type="button" data-command="italic"><i>I</i></button>
                                <button type="button" data-command="underline"><u>U</u></button>
                                <button type="button" data-command="insertOrderedList">1.</button>
                                <button type="button" data-command="insertUnorderedList">•</button>
                                <button type="button" data-command="createLink">🔗</button>
                            </div>
                            <div class="p9-rich-textarea" id="p9-description" contenteditable="true" data-placeholder="Describe your product in detail…"><?php echo wp_kses_post( $product->get_description() ); ?></div>
                            <textarea name="product_description" style="display:none"><?php echo esc_textarea($product->get_description()); ?></textarea>
                        </div>
                    </div>
                    <div class="p9-field">
                        <label>Short Description</label>
                        <textarea name="short_description" placeholder="Brief description shown in listings…" rows="3"><?php echo esc_textarea($product->get_short_description()); ?></textarea>
                    </div>
                </div>

                <!-- Pricing -->
                <div class="p9-glass-card">
                    <h3 class="p9-card-title">💰 Pricing</h3>
                    <div class="p9-price-row">
                        <div class="p9-field">
                            <label>Regular Price <span>*</span></label>
                            <div class="p9-price-input">
                                <span class="p9-currency"><?php echo esc_html( $currency ); ?></span>
                                <input name="regular_price" value="<?php echo esc_attr($product->get_regular_price()); ?>" type="number" placeholder="0.00" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="p9-field">
                            <label>Sale Price</label>
                            <div class="p9-price-input">
                                <span class="p9-currency"><?php echo esc_html( $currency ); ?></span>
                                <input name="sale_price" value="<?php echo esc_attr($product->get_sale_price()); ?>" type="number" placeholder="0.00" step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inventory & Dimensions -->
                <div class="p9-glass-card">
                    <h3 class="p9-card-title">📦 Inventory & Dimensions</h3>
                    <div class="p9-inventory-row">
                        <div class="p9-field">
                            <label>Stock Status</label>
                            <select name="stock_status">
                                <option value="instock" <?php selected($product->get_stock_status(), 'instock'); ?>>In Stock</option>
                                <option value="outofstock" <?php selected($product->get_stock_status(), 'outofstock'); ?>>Out of Stock</option>
                                <option value="onbackorder" <?php selected($product->get_stock_status(), 'onbackorder'); ?>>On Backorder</option>
                            </select>
                        </div>
                        <div class="p9-field">
                            <label>Stock Quantity</label>
                            <input name="stock_quantity" value="<?php echo esc_attr($product->get_stock_quantity()); ?>" type="number" placeholder="Unlimited" min="0">
                        </div>
                    </div>
                    <div class="p9-inventory-row">
                        <div class="p9-field">
                            <label>Weight (kg)</label>
                            <input name="weight" value="<?php echo esc_attr($product->get_weight()); ?>" type="number" placeholder="0" step="any">
                        </div>
                        <div class="p9-field">
                            <label>Length (cm)</label>
                            <input name="length" value="<?php echo esc_attr($product->get_length()); ?>" type="number" placeholder="0" step="any">
                        </div>
                    </div>
                    <div class="p9-inventory-row">
                        <div class="p9-field">
                            <label>Width (cm)</label>
                            <input name="width" value="<?php echo esc_attr($product->get_width()); ?>" type="number" placeholder="0" step="any">
                        </div>
                        <div class="p9-field">
                            <label>Height (cm)</label>
                            <input name="height" value="<?php echo esc_attr($product->get_height()); ?>" type="number" placeholder="0" step="any">
                        </div>
                    </div>
                </div>

                <!-- Product Gallery -->
                <div class="p9-glass-card">
                    <h3 class="p9-card-title">🖼️ Product Gallery</h3>
                    <p class="p9-field-hint">
                        Manage gallery images for your product (Max 4 images)
                    </p>
                    <div class="p9-gallery-upload-box">
                        <input type="file" id="p9-gallery-input" accept="image/*" style="display:none" multiple>
                        <div class="p9-gallery-trigger" id="p9-gallery-trigger"
                             data-ratio="<?php echo esc_attr($_pc9_gal_ratio); ?>"
                             data-css-ratio="<?php echo esc_attr($_pc9_gal_css); ?>"
                             style="<?php echo esc_attr($_pc9_gal_ind_style); ?>">
                            <div class="p9-upload-icon">🖼️</div>
                            <p><strong>Click to add gallery images</strong></p>
                            <small><?php echo esc_html($_pc9_gal_hint); ?></small>
                        </div>
                    </div>
                    <div class="p9-gallery-preview" id="p9-gallery-preview" style="<?php echo esc_attr( !empty($gallery_ids) ? '' : 'display:none;' ); ?>">
                        <?php if (!empty($gallery_ids)) :
                            foreach ($gallery_ids as $gal_id) :
                                $gal_url = wp_get_attachment_image_url($gal_id, 'woocommerce_thumbnail');
                                if ($gal_url) : ?>
                                    <div class="p9-gallery-item" data-image-id="<?php echo esc_attr($gal_id); ?>">
                                        <img src="<?php echo esc_url($gal_url); ?>" alt="Gallery image">
                                        <button type="button" class="p9-gallery-remove" data-image-id="<?php echo esc_attr($gal_id); ?>">×</button>
                                    </div>
                                <?php endif;
                            endforeach;
                        endif; ?>
                    </div>
                    <input name="gallery_ids" value="<?php echo esc_attr(implode(',', $gallery_ids)); ?>" type="hidden" id="p9-gallery-ids">
                </div>
            </div>

            <!-- RIGHT COLUMN -->
            <div class="p9-form-right">
                <!-- Product Image -->
                <div class="p9-glass-card">
                    <h3 class="p9-card-title">📷 Product Image</h3>
                    <div class="p9-image-upload-box">
                        <input type="file" id="p9-product-image" accept="image/*" style="display:none">
                        
                        <div id="p9-upload-area" style="<?php echo $image_url ? 'display:none;' : ''; ?><?php echo esc_attr($_pc9_feat_ind_style); ?>"
                             data-ratio="<?php echo esc_attr($_pc9_feat_ratio); ?>"
                             data-css-ratio="<?php echo esc_attr($_pc9_feat_css); ?>">
                            <div id="p9-upload-trigger">
                                <div class="p9-upload-icon">📷</div>
                                <p><strong>Click or drag image here</strong></p>
                                <small><?php echo esc_html($_pc9_feat_hint); ?></small>
                                <div class="p9-upload-progress" id="p9-upload-progress" style="display:none">
                                    <div class="p9-progress-bar">
                                        <div class="p9-progress-fill" id="p9-progress-fill"></div>
                                    </div>
                                    <span class="p9-progress-text" id="p9-progress-text">0%</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p9-image-preview" id="p9-image-preview" style="<?php echo esc_attr( $image_url ? '' : 'display:none;' ); ?>">
                            <img alt="Product preview" id="p9-preview-image" src="<?php echo esc_url($image_url); ?>">
                            <button type="button" class="p9-remove-img-btn" id="p9-remove-image">×</button>
                        </div>
                    </div>
                </div>

                <!-- Categories -->
                <div class="p9-glass-card p9-category-card">
                    <h3 class="p9-card-title">🏷️ Categories</h3>
                    <div class="p9-selected-terms" id="p9-selected-categories"></div>
                    <div class="p9-terms-list p9-categories">
                        <?php
                        $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                        foreach ($cats as $c) :
                            $checked = in_array($c->term_id, $sel_cats) ? 'checked' : '';
                            $selected_class = in_array($c->term_id, $sel_cats) ? 'term-selected' : '';
                        ?>
                            <label class="p9-term-item <?php echo esc_attr( $selected_class ); ?>">
                                <input type="checkbox" name="product_cat[]" value="<?php echo esc_attr($c->term_id); ?>" <?php echo esc_attr($checked); ?>>
                                <span><?php echo esc_html($c->name); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="p9-field p9-field-mt">
                        <input id="p9-new-category-name" placeholder="Add new category">
                        <button type="button" class="p9-btn p9-btn-accent" id="p9-add-category-btn">Add Category</button>
                    </div>
                </div>

                <!-- Tags -->
                <div class="p9-glass-card p9-tag-card">
                    <h3 class="p9-card-title">🔖 Tags</h3>
                    <div class="p9-selected-terms" id="p9-selected-tags"></div>
                    <div class="p9-terms-list p9-tags">
                        <?php
                        $tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
                        foreach ($tags as $t) :
                            $checked = in_array($t->term_id, $sel_tags) ? 'checked' : '';
                            $selected_class = in_array($t->term_id, $sel_tags) ? 'term-selected' : '';
                        ?>
                            <label class="p9-term-item <?php echo esc_attr( $selected_class ); ?>">
                                <input type="checkbox" name="product_tag[]" value="<?php echo esc_attr($t->term_id); ?>" <?php echo esc_attr($checked); ?>>
                                <span><?php echo esc_html($t->name); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="p9-field p9-field-mt">
                        <input id="p9-new-tags" placeholder="Add comma-separated tags">
                        <button type="button" class="p9-btn p9-btn-accent" id="p9-create-tags-btn">Add Tags</button>
                    </div>
                </div>

                <!-- Additional Settings -->
                <div class="p9-glass-card">
                    <h3 class="p9-card-title">⚙️ Additional Settings</h3>
                    <div class="p9-field p9-field-mb">
                        <div class="p9-featured-checkbox">
                            <input name="featured" value="1" type="checkbox" id="p9-featured-chk" <?php checked($product->is_featured(), true); ?>>
                            <label for="p9-featured-chk">Featured Product</label>
                        </div>
                    </div>
                    <div class="p9-field">
                        <label>SKU (leave blank to auto-generate)</label>
                        <input name="sku" value="<?php echo esc_attr($product->get_sku()); ?>" placeholder="SKU-123">
                    </div>
                    <div class="p9-field">
                        <label>Seller Phone (public)</label>
                        <input name="seller_phone" value="<?php echo esc_attr(get_post_meta($product_id, '_portalcloud9_seller_phone', true)); ?>" type="tel" placeholder="+1 (555) 123-4567">
                    </div>
                </div>
            </div>
        </div>

        <div class="p9-form-actions">
            <button type="button" class="p9-btn p9-btn-secondary" id="p9-save-draft">📝 Save as Draft</button>
            <button type="submit" class="p9-btn p9-btn-primary" id="p9-update-product">✅ Update Product</button>
        </div>

        <input name="image_id" value="<?php echo absint( $image_id ); ?>" type="hidden" id="p9-image-id">
    </form>
</div>

<!-- Success Modal -->
<div class="p9-modal" id="p9-product-success-modal" style="display:none">
    <div class="p9-modal-content">
        <div class="p9-modal-header">
            <h3>✅ Product Updated!</h3>
            <button type="button" class="p9-modal-close">×</button>
        </div>
        <div class="p9-modal-body">
            <p id="p9-success-message"></p>
            <div class="p9-modal-actions">
                <a class="p9-btn p9-btn-primary" href="#" id="p9-view-new-product" target="_blank">👁️ View Product</a>
                <a class="p9-btn p9-btn-secondary" href="#" id="p9-edit-new-product">✏️ Continue Editing</a>
            </div>
        </div>
        <div class="p9-modal-footer">
            <button type="button" class="p9-btn p9-btn-primary" id="p9-view-products">📋 View All Products</button>
        </div>
    </div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>
