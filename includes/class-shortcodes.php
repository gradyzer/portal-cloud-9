<?php
/**
 * Portal Cloud 9 - Shortcodes
 * Fixed version with null-safe post content access
 */
defined('ABSPATH') || exit;

class PortalCloud9_Shortcodes {
    
    public function __construct() {
        add_shortcode('portalcloud9_portal', [$this, 'portal']);
        add_shortcode('portalcloud9_product_inquiry', [$this, 'inquiry']);
        add_shortcode('portalcloud9_seller_phone', [$this, 'seller_phone']);
        add_shortcode('portalcloud9_favourite_button', [$this, 'fav_button']);
        add_shortcode('portalcloud9_favourites_count', [$this, 'fav_count']);
        add_shortcode('portalcloud9_notification_bubble', [$this, 'notification_bubble']);
        add_action('wp_enqueue_scripts', [$this, 'scripts']);
    }
    
    /**
     * FIXED: Safely get post content without triggering null errors
     */
    private function get_post_content_safe() {
        $post = get_post();
        if (!$post || !is_object($post)) {
            return '';
        }
        return isset($post->post_content) ? $post->post_content : '';
    }
    
    public function scripts() {
        // FIXED: Use safe method to check post content
        $should_enqueue = false;
        
        // Check if we're on a product page
        if (function_exists('is_product') && is_product()) {
            $should_enqueue = true;
        }
        
        // Check if shortcode exists in post content (safely)
        if (!$should_enqueue) {
            $content = $this->get_post_content_safe();
            if (!empty($content) && has_shortcode($content, 'portalcloud9_product_inquiry')) {
                $should_enqueue = true;
            }
        }
        
        if ($should_enqueue) {
            // Use current domain to support any WordPress site
            
            
            wp_enqueue_script('jquery');

            // Enqueue inquiry CSS and JS (moved from inline in shortcode output)
            if (defined('PORTCLD9_PLUGIN_URL') && defined('PORTCLD9_VERSION')) {
                wp_enqueue_style(
                    'portalcloud9-inquiry',
                    PORTCLD9_PLUGIN_URL . 'assets/css/inquiry.css',
                    [],
                    PORTCLD9_VERSION
                );
                wp_enqueue_script(
                    'portalcloud9-inquiry',
                    PORTCLD9_PLUGIN_URL . 'assets/js/inquiry.js',
                    ['jquery'],
                    PORTCLD9_VERSION,
                    true
                );
                wp_localize_script('portalcloud9-inquiry', 'portalcloud9_inquiry_data', [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce('portalcloud9_nonce'),
                ]);

                // Enqueue seller-phone CSS (moved from inline in shortcode output)
                wp_enqueue_style(
                    'portalcloud9-seller-phone',
                    PORTCLD9_PLUGIN_URL . 'assets/css/seller-phone.css',
                    [],
                    PORTCLD9_VERSION
                );
            }

            wp_localize_script('jquery', 'portalcloud9_ajax', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce('portalcloud9_nonce'),
                'user_id'  => get_current_user_id(),
            ]);
        }
    }
    
    public function portal($atts) {
        if (!is_user_logged_in()) {
            return '<div style="text-align:center;padding:40px;background:#f8f9fa;border-radius:8px;">
                <h3>Login Required</h3>
                <p>Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to view your dashboard.</p>
            </div>';
        }
        
        $url = home_url('/user-portal/');
        if (!get_query_var('portalcloud9_dashboard')) {
            wp_safe_redirect($url);
            exit;
        }
        return '';
    }
    
    public function inquiry($atts) {
        // FIXED: Safe check for is_product
        if (!function_exists('is_product') || !is_product()) {
            return '';
        }

        // Check if product inquiry is enabled
        $options = get_option('portalcloud9_options', []);
        if (empty($options['enable_product_inquiry'])) {
            return '';
        }
        
        if (!function_exists('wc_get_product')) {
            return '';
        }
        
        $product = wc_get_product(get_the_ID());
        if (!$product) {
            return '';
        }
        
        $atts = shortcode_atts([
            'style'    => 'floating',
            'position' => 'bottom-right',
            'title'    => 'Product Inquiry',
            'button'   => 'Ask About This Product'
        ], $atts);
        
        $uid = 'p9-inquiry-' . $product->get_id() . '-' . wp_rand(100, 999);
        $nonce = wp_create_nonce('portalcloud9_nonce');
        
        // Get product image safely
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
        if (!$image_url && function_exists('wc_placeholder_img_src')) {
            $image_url = wc_placeholder_img_src('thumbnail');
        }
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($uid); ?>" class="portalcloud9-inquiry portalcloud9-inquiry-<?php echo esc_attr($atts['style']); ?>" data-position="<?php echo esc_attr($atts['position']); ?>">
            <?php if ($atts['style'] === 'floating'): ?>
                <button class="portalcloud9-inquiry-trigger"><?php echo esc_html($atts['button']); ?></button>
            <?php endif; ?>

            <div class="portalcloud9-inquiry-box <?php echo esc_attr( $atts['style'] === 'inline' ? 'portalcloud9-inquiry-open' : '' ); ?>">
                <div class="portalcloud9-inquiry-header">
                    <h3><?php echo esc_html($atts['title']); ?></h3>
                    <?php if ($atts['style'] === 'floating'): ?>
                        <button class="portalcloud9-inquiry-close">&times;</button>
                    <?php endif; ?>
                </div>

                <div class="portalcloud9-inquiry-product">
                    <img src="<?php echo esc_url($image_url); ?>" alt="">
                    <div>
                        <h4><?php echo esc_html($product->get_name()); ?></h4>
                        <span><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
                    </div>
                </div>

                <div class="portalcloud9-inquiry-messages"></div>

                <form class="portalcloud9-inquiry-form" data-product-id="<?php echo absint( $product->get_id() ); ?>">
                    <?php wp_nonce_field('portalcloud9_product_nonce', 'portalcloud9_inquiry_nonce'); ?>
                    <?php if (!is_user_logged_in()): ?>
                        <input type="text" name="guest_name" placeholder="Your Name" required>
                        <input type="email" name="guest_email" placeholder="Your Email" required>
                    <?php endif; ?>
                    <textarea name="message" placeholder="Type your message…" required maxlength="500"></textarea>
                    <button type="submit">Send →</button>
                </form>

                <!-- Glass toast -->
                <div class="portalcloud9-inquiry-toast">
                    <span class="portalcloud9-toast-icon"></span>
                    <span class="portalcloud9-toast-text"></span>
                </div>
            </div>
        </div>


        <?php
        return ob_get_clean();
    }
    
    public function seller_phone($atts) {
        $atts = shortcode_atts([
            'product_id' => 0,
            'style'      => 'button',
            'label'      => 'Call Seller',
            'class'      => ''
        ], $atts);
        
        $pid = absint($atts['product_id']) ?: get_the_ID();
        $tel = get_post_meta($pid, '_portalcloud9_seller_phone', true);
        
        if (!$tel) {
            return '';
        }
        
        $clean = preg_replace('/\D+/', '', $tel);
        $cls = 'portalcloud9-seller-phone portalcloud9-phone-style-' . esc_attr($atts['style']);
        
        if (!empty($atts['class'])) {
            $cls .= ' ' . esc_attr($atts['class']);
        }
        
        // Check if user is logged in
        $is_logged_in = is_user_logged_in();
        
        // Mask phone number for non-logged-in users
        $display_number = $tel;
        if (!$is_logged_in) {
            // Show first 4 digits, mask the rest
            $digits_only = preg_replace('/\D+/', '', $tel);
            if (strlen($digits_only) > 4) {
                $first_four = substr($digits_only, 0, 4);
                $masked_length = strlen($digits_only) - 4;
                $masked = str_repeat('•', $masked_length);
                $display_number = $first_four . ' ' . $masked;
            }
        }
        
        // Detect WhatsApp-style from label
        $is_whatsapp = (stripos($atts['label'], 'whatsapp') !== false || stripos($atts['label'], '💬') !== false);
        $whatsapp_class = $is_whatsapp ? ' portalcloud9-whatsapp-style' : '';
        
        // Add locked class for non-logged-in users
        $locked_class = !$is_logged_in ? ' portalcloud9-phone-locked' : '';
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr( $cls . $whatsapp_class . $locked_class ); ?>">
            <?php if ($atts['style'] === 'button'): ?>
                <?php if ($is_logged_in): ?>
                    <a href="tel:<?php echo esc_attr($clean); ?>" 
                       class="portalcloud9-phone-btn pc9-phone-number"
                       data-product-id="<?php echo esc_attr($pid); ?>"
                       data-phone="<?php echo esc_attr($tel); ?>">
                        <span class="portalcloud9-phone-icon">
                            <?php echo esc_html( $is_whatsapp ? '💬' : '📞' ); ?>
                        </span>
                        <span class="portalcloud9-phone-content">
                            <span class="portalcloud9-phone-label"><?php echo esc_html($atts['label']); ?></span>
                            <span class="portalcloud9-phone-number-text"><?php echo esc_html($display_number); ?></span>
                        </span>
                    </a>
                <?php else: ?>
                    <div class="portalcloud9-phone-btn portalcloud9-phone-disabled">
                        <span class="portalcloud9-phone-icon">
                            🔒
                        </span>
                        <span class="portalcloud9-phone-content">
                            <span class="portalcloud9-phone-label"><?php echo esc_html($atts['label']); ?></span>
                            <span class="portalcloud9-phone-number"><?php echo esc_html($display_number); ?></span>
                        </span>
                    </div>
                    <div class="portalcloud9-phone-login-prompt">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Login</a> to reveal the full phone number
                    </div>
                <?php endif; ?>
                
            <?php elseif ($atts['style'] === 'icon'): ?>
                <?php if ($is_logged_in): ?>
                    <a href="tel:<?php echo esc_attr($clean); ?>" 
                       class="portalcloud9-phone-icon-only pc9-phone-number" 
                       title="<?php echo esc_attr($atts['label'] . ': ' . $tel); ?>"
                       data-product-id="<?php echo esc_attr($pid); ?>"
                       data-phone="<?php echo esc_attr($tel); ?>">
                        <span class="portalcloud9-phone-icon-inner">
                            <?php echo esc_html( $is_whatsapp ? '💬' : '📞' ); ?>
                        </span>
                    </a>
                <?php else: ?>
                    <div class="portalcloud9-phone-icon-only portalcloud9-phone-disabled" title="Login to reveal phone number">
                        <span class="portalcloud9-phone-icon-inner">
                            🔒
                        </span>
                    </div>
                    <div class="portalcloud9-phone-login-prompt portalcloud9-phone-login-compact">
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Login to reveal</a>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <?php if ($is_logged_in): ?>
                    <a href="tel:<?php echo esc_attr($clean); ?>" 
                       class="portalcloud9-phone-inline pc9-phone-number"
                       data-product-id="<?php echo esc_attr($pid); ?>"
                       data-phone="<?php echo esc_attr($tel); ?>">
                        <span class="portalcloud9-phone-icon">
                            <?php echo esc_html( $is_whatsapp ? '💬' : '📞' ); ?>
                        </span>
                        <span class="portalcloud9-phone-label"><?php echo esc_html($atts['label']); ?></span>
                        <span class="portalcloud9-phone-number-text"><?php echo esc_html($display_number); ?></span>
                    </a>
                <?php else: ?>
                    <div class="portalcloud9-phone-inline portalcloud9-phone-disabled">
                        <span class="portalcloud9-phone-icon">
                            🔒
                        </span>
                        <span class="portalcloud9-phone-label"><?php echo esc_html($atts['label']); ?></span>
                        <span class="portalcloud9-phone-number"><?php echo esc_html($display_number); ?></span>
                    </div>
                    <div class="portalcloud9-phone-login-prompt">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Login</a> to reveal the full phone number
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php
        return ob_get_clean();
    }
    
    public function fav_button($atts) {
        if (isset($GLOBALS['portalcloud9_favourites']) && method_exists($GLOBALS['portalcloud9_favourites'], 'btn_shortcode')) {
            return $GLOBALS['portalcloud9_favourites']->btn_shortcode($atts);
        }
        return '';
    }
    
    public function fav_count($atts) {
        if (isset($GLOBALS['portalcloud9_favourites']) && method_exists($GLOBALS['portalcloud9_favourites'], 'count_shortcode')) {
            return $GLOBALS['portalcloud9_favourites']->count_shortcode($atts);
        }
        return 0;
    }
    
    public function notification_bubble($atts) {
        if (!is_user_logged_in()) {
            return '';
        }
        
        $atts = shortcode_atts([
            'style'     => 'pill',
            'show_zero' => 'true'
        ], $atts);
        
        $count = 0;
        if (class_exists('PortalCloud9_Messaging_Integration')) {
            $m = new PortalCloud9_Messaging_Integration();
            $count = $m->get_unread_count();
        }
        
        if (!$count && $atts['show_zero'] !== 'true') {
            return '';
        }
        
        // Safe constant check
        $plugin_url = defined('PORTALCLOUD9_PLUGIN_URL') ? PORTALCLOUD9_PLUGIN_URL : '';
        $version = defined('PORTALCLOUD9_VERSION') ? PORTALCLOUD9_VERSION : '1.0.0';
        
        if ($plugin_url) {
            wp_enqueue_style('portalcloud9-dashboard', $plugin_url . 'assets/css/dashboard.css', ['portalcloud9-fontawesome'], $version);
        }
        
        $url = home_url('/user-portal/inbox/');
        $text = $count ?: '0';
        
        return sprintf(
            '<a href="%s" class="portalcloud9-notification-bubble" aria-label="%s unread messages">
                <span class="portalcloud9-bubble-icon">📨</span>
                <span class="portalcloud9-bubble-count">%s</span>
            </a>',
            esc_url($url),
            esc_attr($count),
            esc_html($text)
        );
    }
}

// Don't auto-instantiate here - let the main plugin file do it
// This prevents double instantiation
if (!class_exists('PortalCloud9_Shortcodes_Loaded')) {
    class PortalCloud9_Shortcodes_Loaded {}
}