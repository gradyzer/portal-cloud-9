<?php
/**
 * Portal Cloud 9 - Messaging Integration
 * 
 * UPDATED v2.7.0:
 * 1. Threads grouped by sender_id + product_id (not just sender_id)
 *    - Each product inquiry creates a separate thread
 *    - Same user asking about different products = different threads
 * 2. Self-messaging prevention
 *    - Users cannot send inquiry about their own products
 *    - Shows error notification instead
 * 3. All previous fixes maintained
 * 
 * @version 2.7.0
 * @filepath includes/class-messaging-integration.php
 */
defined('ABSPATH') || exit;

if (class_exists('PortalCloud9_Messaging_Integration')) {
    return;
}

final class PortalCloud9_Messaging_Integration {
    
    private $messaging_active = false;
    
    public function __construct() {
        // Check if messaging is enabled via settings
        $this->messaging_active = $this->is_messaging_enabled();
        
        if (did_action('init')) {
            $this->init_hooks();
        } else {
            add_action('init', [$this, 'init_hooks']);
        }
    }
    
    /**
     * Check if messaging feature is enabled in settings
     */
    private function is_messaging_enabled() {
        $opts = get_option('portalcloud9_options', []);
        // Default to enabled if not set
        return isset($opts['enable_messaging']) ? (bool) $opts['enable_messaging'] : true;
    }
    
    public function init_hooks() {
        $this->register_message_post_type();
        
        // Product inquiry - available for both logged in and guests (but only if messaging enabled)
        if ($this->messaging_active) {
            add_action('wp_ajax_portalcloud9_send_product_inquiry', [$this, 'ajax_send_product_inquiry']);
            add_action('wp_ajax_nopriv_portalcloud9_send_product_inquiry', [$this, 'ajax_send_product_inquiry']);
        }
        
        // Register ALL AJAX actions - no conditional check
        // These hooks MUST be registered on every page load so they're available when AJAX calls come in
        $actions = [
            'portalcloud9_get_inbox_data',
            'portalcloud9_get_thread',
            'portalcloud9_send_reply',
            'portalcloud9_mark_thread_read',
            'portalcloud9_get_unread_count',
            'portcld9_load_thread',
            'portcld9_send_reply',
            'portcld9_mark_thread_read',
            'portcld9_mark_all_read',
            'portcld9_delete_conversations',
            'portcld9_check_new_messages',      // NEW: Real-time message polling
            'portcld9_check_inbox_updates',     // NEW: Real-time inbox updates
            'portcld9_check_user_status',       // NEW: Check user online/offline status
            'portcld9_update_heartbeat',        // NEW: Update user's online status heartbeat
        ];
        
        foreach ($actions as $act) {
            add_action('wp_ajax_' . $act, [$this, 'ajax_router']);
        }
        
        // Frontend hooks
        if (!is_admin()) {
            add_filter('wp_nav_menu_items', [$this, 'hide_messaging_bubble'], 5, 2);
            add_action('wp_footer', [$this, 'add_dashboard_notification_script']);
        }
    }
    
    private function register_message_post_type() {
        if (!post_type_exists('portalcloud9_message')) {
            register_post_type('portalcloud9_message', [
                'labels' => [
                    'name' => 'Messages',
                    'singular_name' => 'Message'
                ],
                'public' => false,
                'show_ui' => false,
                'capability_type' => 'post',
                'supports' => ['title', 'editor', 'author'],
            ]);
        }
    }
    
    public function ajax_router() {
        $action = str_replace(['wp_ajax_', 'wp_ajax_nopriv_'], '', current_action());
        
        // Check if messaging is enabled for all actions except unread count
        if (!$this->messaging_active && $action !== 'portalcloud9_get_unread_count') {
            wp_send_json_error('Messaging is currently disabled.');
            return;
        }
        
        // Skip nonce check for product inquiry (has its own)
        if (!in_array($action, ['portalcloud9_send_product_inquiry'])) {
            if (!check_ajax_referer('portalcloud9_nonce', 'nonce', false)) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
                wp_send_json_error('Invalid security token. Please refresh the page.');
            }
            if (!is_user_logged_in()) {
                wp_send_json_error('Please log in to access your inbox.');
            }
        }
        
        switch ($action) {
            case 'portalcloud9_get_inbox_data':
                $this->ajax_get_inbox_data();
                break;
            case 'portalcloud9_get_thread':
                $this->ajax_get_thread();
                break;
            case 'portalcloud9_send_reply':
                $this->ajax_send_reply();
                break;
            case 'portalcloud9_mark_thread_read':
                $this->ajax_mark_thread_read();
                break;
            case 'portalcloud9_get_unread_count':
                $this->ajax_get_unread_count();
                break;
            case 'portcld9_load_thread':
                $this->ajax_p9_load_thread();
                break;
            case 'portcld9_send_reply':
                $this->ajax_p9_send_reply();
                break;
            case 'portcld9_mark_thread_read':
                $this->ajax_p9_mark_thread_read();
                break;
            case 'portcld9_mark_all_read':
                $this->ajax_p9_mark_all_read();
                break;
            case 'portcld9_delete_conversations':
                $this->ajax_p9_delete_conversations();
                break;
            case 'portcld9_check_new_messages':
                $this->ajax_p9_check_new_messages();
                break;
            case 'portcld9_check_inbox_updates':
                $this->ajax_p9_check_inbox_updates();
                break;
            case 'portcld9_check_user_status':
                $this->ajax_p9_check_user_status();
                break;
            case 'portcld9_update_heartbeat':
                $this->ajax_p9_update_heartbeat();
                break;
        }
    }
    
    /**
     * Handle product inquiry submission
     * UPDATED: Added self-messaging prevention
     */
    public function ajax_send_product_inquiry() {
        if (!$this->messaging_active) {
            wp_send_json_error('Messaging is currently disabled.');
            return;
        }
        
        if (!function_exists('wc_get_product')) {
            wp_send_json_error('WooCommerce is not active');
        }
        
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'portalcloud9_nonce' ) ) {
            wp_send_json_error( 'Invalid security token - please refresh the page and try again.' );
            return;
        }
        
        $product_id = absint($_POST['product_id'] ?? 0);
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $sender_id = get_current_user_id();
        $sender_name = '';
        $sender_email = '';
        $is_guest = false;
        
        if (!$product_id) {
            wp_send_json_error('Product ID is missing');
        }
        
        if (!$message) {
            wp_send_json_error('Message is empty');
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found');
        }
        
        // Get product owner
        $product_post = get_post($product_id);
        $receiver_id = (int) $product_post->post_author;
        
        if (!$receiver_id) {
            wp_send_json_error('Product owner not found');
        }
        
        // ========================================
        // SELF-MESSAGING PREVENTION
        // ========================================
        if ($sender_id && $sender_id === $receiver_id) {
            wp_send_json_error('You cannot send a message to yourself about your own product.');
            return;
        }
        
        if (!$sender_id) {
            $sender_name = sanitize_text_field( wp_unslash( $_POST['guest_name'] ?? '') );
            $sender_email = sanitize_email( wp_unslash( $_POST['guest_email'] ?? '') );
            $is_guest = true;
            
            if (!$sender_name || !$sender_email) {
                wp_send_json_error('Please provide your name and email address');
            }
            
            if (!is_email($sender_email)) {
                wp_send_json_error('Please provide a valid email address');
            }
        }
        
        if ($sender_id) {
            $sender_user = get_userdata($sender_id);
            $receiver_user = get_userdata($receiver_id);
            $post_title = ($sender_user ? $sender_user->display_name : 'User') . ' → ' . ($receiver_user ? $receiver_user->display_name : 'Seller');
        } else {
            $receiver_user = get_userdata($receiver_id);
            $post_title = $sender_name . ' (Guest) → ' . ($receiver_user ? $receiver_user->display_name : 'Seller');
        }
        
        $message_id = wp_insert_post([
            'post_type' => 'portalcloud9_message',
            'post_title' => $post_title,
            'post_content' => $message,
            'post_status' => 'publish',
            'post_author' => $sender_id ?: 0,
        ]);
        
        if (!$message_id) {
            wp_send_json_error('Failed to save message');
        }
        
        update_post_meta($message_id, 'sender_id', $sender_id ?: 0);
        update_post_meta($message_id, 'receiver_id', $receiver_id);
        update_post_meta($message_id, 'product_id', $product_id);
        update_post_meta($message_id, 'is_read', '0');
        
        if ($is_guest) {
            update_post_meta($message_id, 'is_guest', '1');
            update_post_meta($message_id, 'guest_name', $sender_name);
            update_post_meta($message_id, 'guest_email', $sender_email);
        }
        
        $this->send_new_message_email($receiver_id, $sender_id, $sender_name, $sender_email, $message, $product);
        
        wp_send_json_success([
            'message_id' => $message_id,
            'message' => 'Message sent successfully!',
        ]);
    }
    
    private function ajax_get_inbox_data() {
        // Update current user's last seen time
        $this->update_user_last_seen();
        
        $inbox = $this->get_inbox_data();
        wp_send_json_success($inbox);
    }
    
    private function ajax_get_thread() {
        // Verify nonce
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
        
        // Check user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized');
        }
        
        $sender_id = absint($_POST['sender_id'] ?? 0);
        $product_id = absint($_POST['product_id'] ?? 0);
        $receiver_id = get_current_user_id();
        
        if (!$sender_id) {
            wp_send_json_error('Invalid sender ID');
        }
        
        $thread = $this->get_thread_messages($sender_id, $receiver_id, $product_id);
        wp_send_json_success($thread);
    }
    
    /**
     * UPDATED: Send reply now includes product_id to maintain thread context
     */
    private function ajax_send_reply() {
        $receiver_id = absint($_POST['receiver_id'] ?? 0);
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $product_id = absint($_POST['product_id'] ?? 0);
        $sender_id = get_current_user_id();
        
        if (!$receiver_id || !$message) {
            wp_send_json_error('Missing data');
        }
        
        $sender_user = get_userdata($sender_id);
        $receiver_user = get_userdata($receiver_id);
        
        $message_id = wp_insert_post([
            'post_type' => 'portalcloud9_message',
            'post_title' => ($sender_user ? $sender_user->display_name : 'User') . ' → ' . ($receiver_user ? $receiver_user->display_name : 'User'),
            'post_content' => $message,
            'post_status' => 'publish',
            'post_author' => $sender_id,
        ]);
        
        if (!$message_id) {
            wp_send_json_error('Failed to send reply');
        }
        
        update_post_meta($message_id, 'sender_id', $sender_id);
        update_post_meta($message_id, 'receiver_id', $receiver_id);
        update_post_meta($message_id, 'is_read', '0');
        
        // If product_id provided, use it directly
        if ($product_id) {
            update_post_meta($message_id, 'product_id', $product_id);
        } else {
            // Fallback: get product_id from previous messages in the thread
            $prev_messages = get_posts([
                'post_type' => 'portalcloud9_message',
                'posts_per_page' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                    'relation' => 'OR',
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_id', 'value' => $sender_id, 'compare' => '='],
                        ['key' => 'receiver_id', 'value' => $receiver_id, 'compare' => '='],
                    ],
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_id', 'value' => $receiver_id, 'compare' => '='],
                        ['key' => 'receiver_id', 'value' => $sender_id, 'compare' => '='],
                    ],
                ],
            ]);
            
            if (!empty($prev_messages)) {
                $prev_product_id = get_post_meta($prev_messages[0]->ID, 'product_id', true);
                if ($prev_product_id) {
                    update_post_meta($message_id, 'product_id', $prev_product_id);
                }
            }
        }
        
        $guest_email = '';
        if (!empty($prev_messages)) {
            $guest_email = get_post_meta($prev_messages[0]->ID, 'guest_email', true);
        }
        
        if ($guest_email && is_email($guest_email)) {
            $this->send_reply_to_guest_email($guest_email, $sender_id, $message);
        } else {
            $this->send_reply_email($receiver_id, $sender_id, $message);
        }
        
        wp_send_json_success(['message_id' => $message_id]);
    }
    
    private function ajax_mark_thread_read() {
        $sender_id = absint($_POST['sender_id'] ?? 0);
        $product_id = absint($_POST['product_id'] ?? 0);
        $receiver_id = get_current_user_id();
        
        if (!$sender_id) {
            wp_send_json_error('Invalid sender ID');
        }
        
        $this->mark_thread_as_read($sender_id, $receiver_id, $product_id);
        wp_send_json_success();
    }
    
    private function ajax_get_unread_count() {
        $count = $this->get_unread_count();
        wp_send_json_success(['count' => $count]);
    }
    
    public function get_unread_count(): int {
        if ( ! is_user_logged_in() || ! $this->messaging_active ) {
            return 0;
        }

        global $wpdb;
        $user_id = get_current_user_id();

        // Direct COUNT query — far cheaper than fetching all IDs via get_posts(-1)
        $count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} recv ON recv.post_id = p.ID
                 AND recv.meta_key = 'receiver_id' AND recv.meta_value = %d
             INNER JOIN {$wpdb->postmeta} read_m ON read_m.post_id = p.ID
                 AND read_m.meta_key = 'is_read' AND read_m.meta_value = '0'
             WHERE p.post_type = 'portalcloud9_message'
               AND p.post_status = 'publish'",
            $user_id
        ) );

        return $count;
    }
    
    /**
     * UPDATED: Load thread with product_id support
     * Thread key format: "user_{sender_id}_{product_id}" or "guest_{hash}_{product_id}"
     */
    private function ajax_p9_load_thread() {
        // Update current user's last seen time
        $this->update_user_last_seen();
        
        $thread_key_raw = isset($_POST['sender_id']) ? sanitize_text_field( wp_unslash( $_POST['sender_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value sanitized via sanitize_text_field() + wp_unslash().
        $user_id = get_current_user_id();
        
        $thread_key = sanitize_text_field($thread_key_raw);
        
        if (empty($thread_key)) {
            wp_send_json_error('No conversation selected');
            return;
        }
        
        if (!$user_id) {
            wp_send_json_error('Please log in to view messages');
            return;
        }
        
        // Parse thread key to extract sender_id and product_id
        // Format: "user_{sender_id}_{product_id}" or "guest_{hash}_{product_id}" or legacy "guest_{hash}"
        $is_guest_thread = (strpos($thread_key, 'guest_') === 0);
        
        if ($is_guest_thread) {
            // Guest thread: guest_{hash}_{product_id} or legacy guest_{hash}
            $parts = explode('_', $thread_key);
            if (count($parts) >= 3) {
                // New format: guest_{hash}_{product_id}
                $guest_hash = $parts[1];
                $product_id = absint($parts[2]);
            } else {
                // Legacy format: guest_{hash}
                $guest_hash = str_replace('guest_', '', $thread_key);
                $product_id = 0;
            }
            $thread_data = $this->get_guest_thread_messages($guest_hash, $user_id, $product_id);
            $sender_id = 0;
        } else {
            // User thread: user_{sender_id}_{product_id} or legacy {sender_id}
            if (strpos($thread_key, 'user_') === 0) {
                // New format: user_{sender_id}_{product_id}
                $parts = explode('_', $thread_key);
                $sender_id = absint($parts[1] ?? 0);
                $product_id = absint($parts[2] ?? 0);
            } else {
                // Legacy format: just sender_id
                $sender_id = absint($thread_key);
                $product_id = 0;
            }
            
            if ($sender_id <= 0) {
                wp_send_json_error('Invalid conversation');
                return;
            }
            
            $thread_data = $this->get_thread_messages($sender_id, $user_id, $product_id);
        }
        
        // Mark messages as read
        if ($is_guest_thread) {
            $this->mark_guest_thread_as_read($guest_hash, $user_id, $product_id);
        } else {
            $this->mark_thread_as_read($sender_id, $user_id, $product_id);
        }
        
        // Get sender info
        if ($is_guest_thread) {
            $sender_info = [
                'id' => $thread_key,
                'name' => $thread_data['guest_name'] ?? 'Guest User',
                'avatar' => get_avatar_url(0, ['size' => 64, 'default' => 'mystery']),
                'is_guest' => true,
            ];
        } else {
            $other_user = get_userdata($sender_id);
            $sender_info = [
                'id' => $sender_id,
                'name' => $other_user ? $other_user->display_name : 'Unknown User',
                'avatar' => get_avatar_url($sender_id, ['size' => 64]),
                'is_guest' => false,
            ];
        }
        
        wp_send_json_success([
            'messages' => $thread_data['messages'],
            'product' => $thread_data['product'],
            'sender' => $sender_info,
            'product_id' => $product_id,
        ]);
    }
    
    /**
     * UPDATED: Send reply with product_id to maintain thread separation
     * JS sends sender_id (which is actually the thread_key identifying the other party)
     */
    private function ajax_p9_send_reply() {
        // Update current user's last seen time
        $this->update_user_last_seen();
        
        // JS sends 'sender_id' which is actually the thread_key (the other party)
        $thread_key_raw = isset($_POST['sender_id']) ? sanitize_text_field( wp_unslash( $_POST['sender_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value sanitized via sanitize_text_field() + wp_unslash().
        // Also check for receiver_id for backward compatibility
        if (empty($thread_key_raw)) {
            $thread_key_raw = isset($_POST['receiver_id']) ? sanitize_text_field( wp_unslash( $_POST['receiver_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value sanitized via sanitize_text_field() + wp_unslash().
        }
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $product_id = absint($_POST['product_id'] ?? 0);
        $sender_id = get_current_user_id();
        
        $thread_key = sanitize_text_field($thread_key_raw);
        
        if (empty($thread_key) || empty($message)) {
            wp_send_json_error('Missing required data');
            return;
        }
        
        // Parse thread key
        $is_guest_thread = (strpos($thread_key, 'guest_') === 0);
        
        if ($is_guest_thread) {
            // Guest thread
            $parts = explode('_', $thread_key);
            $guest_hash = $parts[1] ?? '';
            if (count($parts) >= 3 && !$product_id) {
                $product_id = absint($parts[2]);
            }
            $receiver_id = 0;
            $guest_email = $this->get_guest_email_by_hash($guest_hash, $sender_id);
        } else {
            // User thread
            if (strpos($thread_key, 'user_') === 0) {
                $parts = explode('_', $thread_key);
                $receiver_id = absint($parts[1] ?? 0);
                if (count($parts) >= 3 && !$product_id) {
                    $product_id = absint($parts[2]);
                }
            } else {
                $receiver_id = absint($thread_key);
            }
            $guest_email = '';
        }
        
        // Create message
        $sender_user = get_userdata($sender_id);
        
        if ($is_guest_thread) {
            $post_title = ($sender_user ? $sender_user->display_name : 'User') . ' → Guest';
        } else {
            $receiver_user = get_userdata($receiver_id);
            $post_title = ($sender_user ? $sender_user->display_name : 'User') . ' → ' . ($receiver_user ? $receiver_user->display_name : 'User');
        }
        
        $message_id = wp_insert_post([
            'post_type' => 'portalcloud9_message',
            'post_title' => $post_title,
            'post_content' => $message,
            'post_status' => 'publish',
            'post_author' => $sender_id,
        ]);
        
        if (!$message_id) {
            wp_send_json_error('Failed to send message');
            return;
        }
        
        update_post_meta($message_id, 'sender_id', $sender_id);
        update_post_meta($message_id, 'receiver_id', $receiver_id);
        update_post_meta($message_id, 'is_read', '0');
        
        if ($product_id) {
            update_post_meta($message_id, 'product_id', $product_id);
        }
        
        if ($is_guest_thread && $guest_email) {
            update_post_meta($message_id, 'is_guest', '1');
            update_post_meta($message_id, 'guest_email', $guest_email);
            $this->send_reply_to_guest_email($guest_email, $sender_id, $message);
        } elseif ($receiver_id) {
            $this->send_reply_email($receiver_id, $sender_id, $message);
        }

        // Bust inbox cache for both sender and receiver so both see the new message
        $this->bust_inbox_cache( $sender_id );
        if ( $receiver_id ) {
            $this->bust_inbox_cache( $receiver_id );
        }

        wp_send_json_success([
            'message_id' => $message_id,
            'message' => [
                'id' => $message_id,
                'content' => $message,
                'is_sent' => true,
                'timestamp' => current_time('mysql'),
                'time_formatted' => 'Just now',
            ],
        ]);
    }
    
    /**
     * UPDATED: Mark thread read with product_id filter
     */
    private function ajax_p9_mark_thread_read() {
        $thread_key_raw = isset($_POST['sender_id']) ? sanitize_text_field( wp_unslash( $_POST['sender_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value sanitized via sanitize_text_field() + wp_unslash().
        $user_id = get_current_user_id();
        
        $thread_key = sanitize_text_field($thread_key_raw);
        
        if (empty($thread_key)) {
            wp_send_json_error('Invalid thread');
            return;
        }
        
        // Parse thread key
        $is_guest_thread = (strpos($thread_key, 'guest_') === 0);
        
        if ($is_guest_thread) {
            $parts = explode('_', $thread_key);
            $guest_hash = $parts[1] ?? '';
            $product_id = absint($parts[2] ?? 0);
            $this->mark_guest_thread_as_read($guest_hash, $user_id, $product_id);
        } else {
            if (strpos($thread_key, 'user_') === 0) {
                $parts = explode('_', $thread_key);
                $sender_id = absint($parts[1] ?? 0);
                $product_id = absint($parts[2] ?? 0);
            } else {
                $sender_id = absint($thread_key);
                $product_id = 0;
            }
            $this->mark_thread_as_read($sender_id, $user_id, $product_id);
        }

        $this->bust_inbox_cache( $user_id );
        
        wp_send_json_success();
    }
    
    private function ajax_p9_mark_all_read() {
        global $wpdb;
        $user_id = get_current_user_id();

        // Get unread message IDs via direct SQL — avoids loading full post objects
        $unread_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complex join query; no suitable WP API available.
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} recv ON recv.post_id = p.ID
                 AND recv.meta_key = 'receiver_id' AND recv.meta_value = %d
             INNER JOIN {$wpdb->postmeta} read_m ON read_m.post_id = p.ID
                 AND read_m.meta_key = 'is_read' AND read_m.meta_value = '0'
             WHERE p.post_type = 'portalcloud9_message'
               AND p.post_status = 'publish'",
            $user_id
        ) );

        foreach ( $unread_ids as $id ) {
            update_post_meta( (int) $id, 'is_read', '1' );
        }

        $this->bust_inbox_cache( $user_id );

        wp_send_json_success( [ 'marked' => count( $unread_ids ) ] );
    }
    
    /**
     * UPDATED: Delete conversations with product_id support
     */
    private function ajax_p9_delete_conversations() {
        $thread_ids = isset( $_POST['thread_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['thread_ids'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value sanitized via sanitize_text_field() + wp_unslash().
        
        if (empty($thread_ids) || !is_array($thread_ids)) {
            wp_send_json_error('No conversations selected');
            return;
        }
        
        $deleted = 0;
        $user_id = get_current_user_id();
        
        foreach ($thread_ids as $thread_key_raw) {
            $thread_key = sanitize_text_field($thread_key_raw);
            
            if (strpos($thread_key, 'guest_') === 0) {
                // Guest thread: guest_{hash}_{product_id}
                $parts = explode('_', $thread_key);
                $guest_hash = $parts[1] ?? '';
                $product_id = absint($parts[2] ?? 0);
                
                $guest_messages = get_posts([
                    'post_type' => 'portalcloud9_message',
                    'posts_per_page' => -1,
                    'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                        ['key' => 'receiver_id', 'value' => $user_id, 'compare' => '='],
                        ['key' => 'is_guest', 'value' => '1', 'compare' => '='],
                    ],
                ]);
                
                foreach ($guest_messages as $message) {
                    $email = get_post_meta($message->ID, 'guest_email', true);
                    $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
                    
                    // Match by guest hash AND product_id (if specified)
                    if ($email && md5($email) === $guest_hash) {
                        if ($product_id === 0 || $msg_product_id === $product_id) {
                            wp_delete_post($message->ID, true);
                            $deleted++;
                        }
                    }
                }
            } else {
                // User thread: user_{sender_id}_{product_id} or legacy
                if (strpos($thread_key, 'user_') === 0) {
                    $parts = explode('_', $thread_key);
                    $sender_id = absint($parts[1] ?? 0);
                    $product_id = absint($parts[2] ?? 0);
                } else {
                    $sender_id = absint($thread_key);
                    $product_id = 0;
                }
                
                if ($sender_id <= 0) continue;
                
                $meta_query = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                    'relation' => 'OR',
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_id', 'value' => $sender_id, 'compare' => '='],
                        ['key' => 'receiver_id', 'value' => $user_id, 'compare' => '='],
                    ],
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_id', 'value' => $user_id, 'compare' => '='],
                        ['key' => 'receiver_id', 'value' => $sender_id, 'compare' => '='],
                    ],
                ];
                
                $messages = get_posts([
                    'post_type' => 'portalcloud9_message',
                    'posts_per_page' => -1,
                    'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                ]);
                
                foreach ($messages as $message) {
                    $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
                    
                    // If product_id specified, only delete messages for that product
                    if ($product_id === 0 || $msg_product_id === $product_id) {
                        wp_delete_post($message->ID, true);
                        $deleted++;
                    }
                }
            }
        }
        
        $this->bust_inbox_cache( $user_id );

        wp_send_json_success(['deleted' => $deleted]);
    }

    /**
     * NEW: Check for new messages in current thread (real-time polling)
     * Returns only messages newer than last_message_id
     */
    private function ajax_p9_check_new_messages() {
        // Verify nonce
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
        
        // Check user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized');
        }
        
        $thread_key_raw = isset($_POST['sender_id']) ? sanitize_text_field( wp_unslash( $_POST['sender_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value sanitized via sanitize_text_field() + wp_unslash().
        $last_message_id = absint($_POST['last_message_id'] ?? 0);
        $user_id = get_current_user_id();
        
        $thread_key = sanitize_text_field($thread_key_raw);
        
        if (empty($thread_key) || !$user_id) {
            wp_send_json_success(['new_messages' => []]);
            return;
        }
        
        // Parse thread key to get sender_id and product_id
        $is_guest_thread = (strpos($thread_key, 'guest_') === 0);
        $sender_id = 0;
        $product_id = 0;
        $guest_hash = '';
        
        if ($is_guest_thread) {
            $parts = explode('_', $thread_key);
            $guest_hash = $parts[1] ?? '';
            $product_id = absint($parts[2] ?? 0);
        } else {
            if (strpos($thread_key, 'user_') === 0) {
                $parts = explode('_', $thread_key);
                $sender_id = absint($parts[1] ?? 0);
                $product_id = absint($parts[2] ?? 0);
            } else {
                $sender_id = absint($thread_key);
            }
        }
        
        // Get new messages
        $new_messages = [];
        
        if ($is_guest_thread) {
            // Guest thread - check for new messages
            $messages = get_posts([
                'post_type' => 'portalcloud9_message',
                'posts_per_page' => 20,
                'orderby' => 'date',
                'order' => 'ASC',
                'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                    ['key' => 'is_guest', 'value' => '1', 'compare' => '='],
                ],
            ]);
            
            foreach ($messages as $message) {
                if ($message->ID <= $last_message_id) continue;
                
                $email = get_post_meta($message->ID, 'guest_email', true);
                $msg_receiver_id = (int) get_post_meta($message->ID, 'receiver_id', true);
                $msg_sender_id = (int) get_post_meta($message->ID, 'sender_id', true);
                $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
                
                // Check if belongs to this thread
                if ($email && md5($email) === $guest_hash) {
                    if (($msg_receiver_id === $user_id || $msg_sender_id === $user_id)) {
                        if ($product_id === 0 || $msg_product_id === $product_id) {
                            $new_messages[] = [
                                'id' => $message->ID,
                                'message' => $message->post_content,
                                'content' => $message->post_content,
                                'is_sent' => ($msg_sender_id === $user_id),
                                'timestamp' => get_the_date('Y-m-d H:i:s', $message->ID),
                                'date_formatted' => $this->format_message_date($message->ID),
                                'time_formatted' => $this->format_message_date($message->ID),
                            ];
                        }
                    }
                }
            }
        } else {
            // Regular user thread
            $messages = get_posts([
                'post_type' => 'portalcloud9_message',
                'posts_per_page' => 20,
                'orderby' => 'date',
                'order' => 'ASC',
                'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                    'relation' => 'OR',
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_id', 'value' => $sender_id, 'compare' => '='],
                        ['key' => 'receiver_id', 'value' => $user_id, 'compare' => '='],
                    ],
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_id', 'value' => $user_id, 'compare' => '='],
                        ['key' => 'receiver_id', 'value' => $sender_id, 'compare' => '='],
                    ],
                ],
            ]);
            
            foreach ($messages as $message) {
                if ($message->ID <= $last_message_id) continue;
                
                $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
                $msg_sender_id = (int) get_post_meta($message->ID, 'sender_id', true);
                
                // Filter by product if specified
                if ($product_id > 0 && $msg_product_id !== $product_id) continue;
                
                $new_messages[] = [
                    'id' => $message->ID,
                    'message' => $message->post_content,
                    'content' => $message->post_content,
                    'is_sent' => ($msg_sender_id === $user_id),
                    'timestamp' => get_the_date('Y-m-d H:i:s', $message->ID),
                    'date_formatted' => $this->format_message_date($message->ID),
                    'time_formatted' => $this->format_message_date($message->ID),
                ];
            }
        }
        
        wp_send_json_success(['new_messages' => $new_messages]);
    }
    
    /**
     * NEW: Check for inbox updates (new conversations, unread count)
     * Used for real-time inbox list updates
     */
    private function ajax_p9_check_inbox_updates() {
        $last_check = sanitize_text_field( wp_unslash( $_POST['last_check'] ?? '') );
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_success([
                'has_changes' => false,
                'unread_count' => 0,
                'timestamp' => current_time('mysql'),
            ]);
            return;
        }
        
        // Get current unread count
        $unread_count = $this->get_unread_count();
        
        // Check if there are new messages since last check
        $has_changes = false;
        $updated_threads = [];
        
        if (!empty($last_check)) {
            // Get messages since last check
            $new_messages = get_posts([
                'post_type' => 'portalcloud9_message',
                'posts_per_page' => 10,
                'orderby' => 'date',
                'order' => 'DESC',
                'date_query' => [
                    ['after' => $last_check],
                ],
                'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                    'relation' => 'OR',
                    ['key' => 'receiver_id', 'value' => $user_id, 'compare' => '='],
                    ['key' => 'sender_id', 'value' => $user_id, 'compare' => '='],
                ],
            ]);
            
            if (!empty($new_messages)) {
                $has_changes = true;
                
                // Get updated thread info
                $inbox_data = $this->get_inbox_data();
                $updated_threads = $inbox_data['threads'];
            }
        }
        
        wp_send_json_success([
            'has_changes' => $has_changes,
            'unread_count' => $unread_count,
            'threads' => $updated_threads,
            'timestamp' => current_time('mysql'),
        ]);
    }
    
    /**
     * Invalidate the inbox data cache for a user.
     * Call this whenever messages are sent, read, or deleted.
     *
     * @param int $user_id
     */
    public function bust_inbox_cache( int $user_id ): void {
        delete_transient( 'portcld9_inbox_' . $user_id );
    }

    /**
     * Get inbox data - threads grouped by sender + product.
     *
     * Performance notes vs the old implementation:
     *   OLD: 2× unbounded get_posts() + N× get_userdata() + N× wc_get_product()
     *        + N× get_user_meta() (is_user_online) = dozens of DB round-trips.
     *   NEW: 1 SQL query for IDs → bulk meta cache → 1 get_users() → 1 get_posts()
     *        for products → 0 is_user_online() calls (status is live via AJAX).
     *        Result cached in a transient and reused for 30 seconds.
     */
    public function get_inbox_data(): array {
        if ( ! is_user_logged_in() ) {
            return [ 'threads' => [], 'unread_count' => 0 ];
        }

        $current_user_id = get_current_user_id();
        $cache_key        = 'portcld9_inbox_' . $current_user_id;

        // ── Transient cache (30 s) ────────────────────────────────────────────
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;

        // ── Step 1: single SQL query for all message IDs involving this user ──
        // Capped at 500 messages so the inbox never gets unbounded on large sites.
        $message_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complex join query; no suitable WP API available.
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type   = 'portalcloud9_message'
               AND p.post_status = 'publish'
               AND pm.meta_key  IN ('sender_id', 'receiver_id')
               AND pm.meta_value = %d
             ORDER BY p.post_date DESC
             LIMIT 500",
            $current_user_id
        ) );

        if ( empty( $message_ids ) ) {
            $result = [ 'threads' => [], 'unread_count' => 0 ];
            set_transient( $cache_key, $result, 30 );
            return $result;
        }

        // ── Step 2: fetch posts + bulk-prime the meta cache in ONE query ──────
        // get_posts() with post__in + update_post_meta_cache calls
        // update_post_meta_cache() internally, loading ALL meta rows for every
        // returned post in a single SELECT … WHERE post_id IN (…) query.
        $all_messages = get_posts( [
            'post_type'              => 'portalcloud9_message',
            'post__in'               => $message_ids,
            'posts_per_page'         => count( $message_ids ),
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'no_found_rows'          => true,
        ] );

        // ── Step 3: group into threads (all meta reads hit the object cache) ──
        $conversations     = [];
        $conversation_meta = [];

        foreach ( $all_messages as $message ) {
            $msg_sender_id   = (int) get_post_meta( $message->ID, 'sender_id',   true );
            $msg_receiver_id = (int) get_post_meta( $message->ID, 'receiver_id', true );
            $msg_product_id  = (int) get_post_meta( $message->ID, 'product_id',  true );
            $guest_email     =       get_post_meta( $message->ID, 'guest_email', true );

            // Determine the other party
            $other_party_id = 0;
            if ( ! empty( $guest_email ) ) {
                $other_party_id = 0;
            } elseif ( $msg_sender_id === $current_user_id && $msg_receiver_id > 0 ) {
                $other_party_id = $msg_receiver_id;
            } elseif ( $msg_receiver_id === $current_user_id && $msg_sender_id > 0 ) {
                $other_party_id = $msg_sender_id;
            } elseif ( $msg_sender_id > 0 && $msg_sender_id !== $current_user_id ) {
                $other_party_id = $msg_sender_id;
            } elseif ( $msg_receiver_id > 0 && $msg_receiver_id !== $current_user_id ) {
                $other_party_id = $msg_receiver_id;
            } else {
                continue;
            }

            $thread_key = ! empty( $guest_email )
                ? 'guest_' . md5( $guest_email ) . '_' . $msg_product_id
                : 'user_' . $other_party_id . '_' . $msg_product_id;

            if ( ! isset( $conversations[ $thread_key ] ) ) {
                $conversations[ $thread_key ]     = [];
                $conversation_meta[ $thread_key ] = [
                    'other_party_id' => $other_party_id,
                    'product_id'     => $msg_product_id,
                    'is_guest'       => ! empty( $guest_email ),
                    'guest_email'    => $guest_email,
                ];
            }

            if ( $other_party_id > 0 && $conversation_meta[ $thread_key ]['other_party_id'] <= 0 ) {
                $conversation_meta[ $thread_key ]['other_party_id'] = $other_party_id;
            }

            $conversations[ $thread_key ][] = $message;
        }

        // ── Step 4: collect all unique user IDs and product IDs ───────────────
        $all_user_ids    = [];
        $all_product_ids = [];

        foreach ( $conversation_meta as $meta ) {
            if ( ! $meta['is_guest'] && $meta['other_party_id'] > 0 ) {
                $all_user_ids[] = $meta['other_party_id'];
            }
            if ( $meta['product_id'] > 0 ) {
                $all_product_ids[] = $meta['product_id'];
            }
        }

        // ── Step 5: batch-load all users in ONE query ─────────────────────────
        $users_map = [];
        if ( ! empty( $all_user_ids ) ) {
            $users = get_users( [
                'include' => array_unique( $all_user_ids ),
                'fields'  => [ 'ID', 'display_name' ],
            ] );
            foreach ( $users as $u ) {
                $users_map[ $u->ID ] = $u->display_name;
            }
        }

        // ── Step 6: batch-load all products in ONE query ──────────────────────
        $products_map = [];
        if ( ! empty( $all_product_ids ) && function_exists( 'wc_get_product' ) ) {
            $product_posts = get_posts( [
                'post_type'              => [ 'product', 'product_variation' ],
                'post__in'               => array_unique( $all_product_ids ),
                'posts_per_page'         => count( $all_product_ids ),
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
                'no_found_rows'          => true,
            ] );
            foreach ( $product_posts as $pp ) {
                $wc = wc_get_product( $pp->ID ); // hits WC's internal object cache
                if ( $wc ) {
                    $products_map[ $pp->ID ] = [
                        'id'    => $pp->ID,
                        'title' => $wc->get_name(),
                        'url'   => get_permalink( $pp->ID ),
                    ];
                }
            }
        }

        // ── Step 7: build thread objects ──────────────────────────────────────
        $threads = [];

        foreach ( $conversations as $thread_key => $thread_messages ) {
            // The messages array is already sorted DESC (from get_posts above),
            // so the first element IS the latest message for this thread.
            usort( $thread_messages, function ( $a, $b ) {
                return strtotime( $b->post_date ) - strtotime( $a->post_date );
            } );
            $latest_message = $thread_messages[0];

            $conv_meta      = $conversation_meta[ $thread_key ];
            $is_guest_thread = $conv_meta['is_guest'];
            $other_party_id  = $conv_meta['other_party_id'];
            $product_id      = $conv_meta['product_id'];

            // Validate other_party_id for non-guest threads (all meta already cached)
            if ( ! $is_guest_thread && $other_party_id <= 0 ) {
                foreach ( $thread_messages as $tm ) {
                    $tm_sender   = (int) get_post_meta( $tm->ID, 'sender_id',   true );
                    $tm_receiver = (int) get_post_meta( $tm->ID, 'receiver_id', true );
                    if ( $tm_sender > 0 && $tm_sender !== $current_user_id ) {
                        $other_party_id = $tm_sender;
                        break;
                    }
                    if ( $tm_receiver > 0 && $tm_receiver !== $current_user_id ) {
                        $other_party_id = $tm_receiver;
                        break;
                    }
                }
                if ( $other_party_id <= 0 ) {
                    continue;
                }
            }

            $msg_sender_id  = (int) get_post_meta( $latest_message->ID, 'sender_id', true );
            $latest_was_sent = ( $msg_sender_id === $current_user_id );
            $message_preview = $latest_was_sent
                ? 'You: ' . wp_trim_words( wp_strip_all_tags( $latest_message->post_content ), 10, '...' )
                :            wp_trim_words( wp_strip_all_tags( $latest_message->post_content ), 12, '...' );

            $is_read = get_post_meta( $latest_message->ID, 'is_read', true );
            $is_unread = ( ! $latest_was_sent && $is_read === '0' );

            // Display info — uses batch-loaded maps, no extra DB calls
            if ( $is_guest_thread ) {
                $display_name = 'Guest User';
                foreach ( $thread_messages as $tm ) {
                    $guest_name = get_post_meta( $tm->ID, 'guest_name', true );
                    if ( ! empty( $guest_name ) ) {
                        $display_name = $guest_name;
                        break;
                    }
                }
                $display_avatar = get_avatar_url( 0, [ 'size' => 64, 'default' => 'mystery' ] );
            } else {
                $display_name   = $users_map[ $other_party_id ] ?? 'Unknown User';
                $display_avatar = get_avatar_url( $other_party_id, [ 'size' => 64 ] );
            }

            $threads[ $thread_key ] = [
                'sender_id'             => $thread_key,
                'sender_name'           => $display_name,
                'sender_avatar'         => $display_avatar,
                'latest_message'        => $message_preview,
                'latest_message_id'     => $latest_message->ID,
                'latest_date'           => $latest_message->post_date,
                'latest_date_formatted' => $this->format_message_date( $latest_message->ID ),
                'is_unread'             => $is_unread,
                'product'               => $products_map[ $product_id ] ?? null,
                'product_id'            => $product_id,
                'is_guest'              => $is_guest_thread,
                'is_online'             => false, // fetched live via AJAX — no per-thread DB hit here
                'other_party_id'        => $other_party_id,
            ];
        }

        // ── Step 8: sort by latest message date ───────────────────────────────
        uasort( $threads, function ( $a, $b ) {
            return strtotime( $b['latest_date'] ) - strtotime( $a['latest_date'] );
        } );

        $unread_count = count( array_filter( $threads, function ( $t ) { return $t['is_unread']; } ) );

        $result = [
            'threads'      => array_values( $threads ),
            'unread_count' => $unread_count,
        ];

        // Cache for 30 seconds — busted by bust_inbox_cache() on write/read events
        set_transient( $cache_key, $result, 30 );

        return $result;
    }
    
    /**
     * UPDATED: Get thread messages filtered by product_id
     */
    public function get_thread_messages(int $other_user_id, int $current_user_id, int $product_id = 0): array {
        $meta_query = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
            'relation' => 'OR',
            [
                'relation' => 'AND',
                ['key' => 'sender_id', 'value' => $other_user_id, 'compare' => '='],
                ['key' => 'receiver_id', 'value' => $current_user_id, 'compare' => '='],
            ],
            [
                'relation' => 'AND',
                ['key' => 'sender_id', 'value' => $current_user_id, 'compare' => '='],
                ['key' => 'receiver_id', 'value' => $other_user_id, 'compare' => '='],
            ],
        ];
        
        $messages = get_posts([
            'post_type' => 'portalcloud9_message',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
        ]);
        
        $thread_messages = [];
        $product_info = null;
        
        foreach ($messages as $message) {
            $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
            
            // Filter by product_id if specified
            if ($product_id > 0 && $msg_product_id !== $product_id) {
                continue;
            }
            
            $msg_sender_id = (int) get_post_meta($message->ID, 'sender_id', true);
            $is_read = get_post_meta($message->ID, 'is_read', true);
            
            $thread_messages[] = [
                'id' => $message->ID,
                'message' => $message->post_content,  // JS expects 'message' not 'content'
                'content' => $message->post_content,  // Keep for backward compatibility
                'is_sent' => ($msg_sender_id === $current_user_id),
                'timestamp' => get_the_date('Y-m-d H:i:s', $message->ID),
                'date_formatted' => $this->format_message_date($message->ID),  // JS expects 'date_formatted'
                'time_formatted' => $this->format_message_date($message->ID),  // Keep for backward compatibility
                'is_read' => ($is_read === '1'),
            ];
            
            // Get product info from first message with product_id
            if (!$product_info && $msg_product_id && function_exists('wc_get_product')) {
                $product = wc_get_product($msg_product_id);
                if ($product) {
                    $image_id = $product->get_image_id();
                    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                    
                    $product_info = [
                        'id' => $msg_product_id,
                        'title' => $product->get_name(),
                        'image' => $image_url,
                        'price' => $product->get_price_html(),
                        'url' => get_permalink($msg_product_id),
                        'stock_status' => $this->get_stock_status_label($product),
                    ];
                }
            }
        }
        
        return ['messages' => $thread_messages, 'product' => $product_info];
    }
    
    /**
     * UPDATED: Get guest thread messages with product_id filter
     */
    private function get_guest_thread_messages(string $guest_hash, int $receiver_id, int $product_id = 0): array {
        $guest_messages = get_posts([
            'post_type' => 'portalcloud9_message',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                ['key' => 'is_guest', 'value' => '1', 'compare' => '='],
            ],
        ]);
        
        $thread_messages = [];
        $product_info = null;
        $guest_name = '';
        $guest_email = '';
        
        foreach ($guest_messages as $message) {
            $email = get_post_meta($message->ID, 'guest_email', true);
            $msg_receiver_id = (int) get_post_meta($message->ID, 'receiver_id', true);
            $msg_sender_id = (int) get_post_meta($message->ID, 'sender_id', true);
            $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
            
            // Check if this message belongs to the thread
            $belongs_to_thread = false;
            if ($email && md5($email) === $guest_hash) {
                if ($msg_receiver_id === $receiver_id || $msg_sender_id === $receiver_id) {
                    // Filter by product_id if specified
                    if ($product_id === 0 || $msg_product_id === $product_id) {
                        $belongs_to_thread = true;
                    }
                }
            }
            
            // Also include seller's replies
            if (!$belongs_to_thread && $msg_sender_id === $receiver_id) {
                $reply_receiver = (int) get_post_meta($message->ID, 'receiver_id', true);
                if ($reply_receiver === 0) {
                    $reply_guest_email = get_post_meta($message->ID, 'guest_email', true);
                    if ($reply_guest_email && md5($reply_guest_email) === $guest_hash) {
                        if ($product_id === 0 || $msg_product_id === $product_id) {
                            $belongs_to_thread = true;
                        }
                    }
                }
            }
            
            if (!$belongs_to_thread) continue;
            
            // Get guest info
            if (empty($guest_name)) {
                $guest_name = get_post_meta($message->ID, 'guest_name', true);
            }
            if (empty($guest_email)) {
                $guest_email = $email;
            }
            
            $is_read = get_post_meta($message->ID, 'is_read', true);
            $is_sent = ($msg_sender_id === $receiver_id);
            
            $thread_messages[] = [
                'id' => $message->ID,
                'message' => $message->post_content,  // JS expects 'message'
                'content' => $message->post_content,  // Keep for backward compatibility
                'is_sent' => $is_sent,
                'timestamp' => get_the_date('Y-m-d H:i:s', $message->ID),
                'date_formatted' => $this->format_message_date($message->ID),  // JS expects 'date_formatted'
                'time_formatted' => $this->format_message_date($message->ID),  // Keep for backward compatibility
                'is_read' => ($is_read === '1'),
            ];
            
            // Get product info
            if (!$product_info && $msg_product_id && function_exists('wc_get_product')) {
                $product = wc_get_product($msg_product_id);
                if ($product) {
                    $image_id = $product->get_image_id();
                    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                    
                    $product_info = [
                        'id' => $msg_product_id,
                        'title' => $product->get_name(),
                        'image' => $image_url,
                        'price' => $product->get_price_html(),
                        'url' => get_permalink($msg_product_id),
                        'stock_status' => $this->get_stock_status_label($product),
                    ];
                }
            }
        }
        
        return [
            'messages' => $thread_messages,
            'product' => $product_info,
            'guest_name' => $guest_name ?: 'Guest User',
            'guest_email' => $guest_email,
        ];
    }
    
    /**
     * UPDATED: Mark thread as read with product_id filter
     */
    public function mark_thread_as_read(int $sender_id, int $receiver_id, int $product_id = 0): void {
        $messages = get_posts([
            'post_type' => 'portalcloud9_message',
            'posts_per_page' => -1,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                ['key' => 'sender_id', 'value' => $sender_id, 'compare' => '='],
                ['key' => 'receiver_id', 'value' => $receiver_id, 'compare' => '='],
                ['key' => 'is_read', 'value' => '0', 'compare' => '='],
            ],
        ]);
        
        foreach ($messages as $message) {
            $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
            
            // Filter by product_id if specified
            if ($product_id > 0 && $msg_product_id !== $product_id) {
                continue;
            }
            
            update_post_meta($message->ID, 'is_read', '1');
        }
    }
    
    /**
     * Mark guest thread as read with product_id filter
     */
    private function mark_guest_thread_as_read(string $guest_hash, int $receiver_id, int $product_id = 0): void {
        $guest_messages = get_posts([
            'post_type' => 'portalcloud9_message',
            'posts_per_page' => -1,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                ['key' => 'receiver_id', 'value' => $receiver_id, 'compare' => '='],
                ['key' => 'is_guest', 'value' => '1', 'compare' => '='],
                ['key' => 'is_read', 'value' => '0', 'compare' => '='],
            ],
        ]);
        
        foreach ($guest_messages as $message) {
            $email = get_post_meta($message->ID, 'guest_email', true);
            $msg_product_id = (int) get_post_meta($message->ID, 'product_id', true);
            
            if ($email && md5($email) === $guest_hash) {
                // Filter by product_id if specified
                if ($product_id === 0 || $msg_product_id === $product_id) {
                    update_post_meta($message->ID, 'is_read', '1');
                }
            }
        }
    }
    
    private function get_guest_email_by_hash(string $guest_hash, int $receiver_id): string {
        $guest_messages = get_posts([
            'post_type' => 'portalcloud9_message',
            'posts_per_page' => -1,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional meta_query usage.
                ['key' => 'is_guest', 'value' => '1', 'compare' => '='],
            ],
        ]);
        
        foreach ($guest_messages as $message) {
            $email = get_post_meta($message->ID, 'guest_email', true);
            $msg_receiver_id = (int) get_post_meta($message->ID, 'receiver_id', true);
            $msg_sender_id = (int) get_post_meta($message->ID, 'sender_id', true);
            
            if ($email && md5($email) === $guest_hash) {
                if ($msg_receiver_id === $receiver_id || $msg_sender_id === $receiver_id) {
                    return $email;
                }
            }
        }
        return '';
    }
    
    private function send_new_message_email($receiver_id, $sender_id, $sender_name, $sender_email, $message, $product) {
        $receiver = get_userdata($receiver_id);
        if (!$receiver) return;
        
        $sender_display = '';
        if ($sender_id) {
            $sender_user = get_userdata($sender_id);
            $sender_display = $sender_user ? $sender_user->display_name : 'User';
        } else {
            $sender_display = $sender_name . ' (Guest)';
        }
        
        $product_title = $product ? $product->get_name() : 'Unknown Product';
        $subject = sprintf('[%s] New message about: %s', get_bloginfo('name'), $product_title);
        $from_info = $sender_id ? $sender_display : sprintf('%s (%s)', $sender_name, $sender_email);
        
        $body = sprintf(
            "Hi %s,\n\nYou have received a new message from %s about %s:\n\n%s\n\nView and reply: %s\n\nThank you!",
            $receiver->display_name,
            $from_info,
            $product_title,
            wp_trim_words($message, 30),
            home_url('/user-portal/inbox/')
        );
        
        wp_mail($receiver->user_email, $subject, $body);
    }
    
    private function send_reply_email($receiver_id, $sender_id, $message) {
        $receiver = get_userdata($receiver_id);
        $sender = get_userdata($sender_id);
        if (!$receiver || !$sender) return;
        
        $subject = sprintf('[%s] New message from %s', get_bloginfo('name'), $sender->display_name);
        $body = sprintf(
            "Hi %s,\n\nYou have received a new message from %s:\n\n%s\n\nView and reply: %s\n\nThank you!",
            $receiver->display_name,
            $sender->display_name,
            wp_trim_words($message, 30),
            home_url('/user-portal/inbox/')
        );
        
        wp_mail($receiver->user_email, $subject, $body);
    }
    
    private function send_reply_to_guest_email($guest_email, $sender_id, $message) {
        if (!is_email($guest_email)) return;
        
        $sender = get_userdata($sender_id);
        if (!$sender) return;
        
        $subject = sprintf('[%s] Reply from %s', get_bloginfo('name'), $sender->display_name);
        $body = sprintf(
            "Hello,\n\nYou have received a reply from %s:\n\n%s\n\nTo continue this conversation, please log in at: %s\n\nThank you!",
            $sender->display_name,
            wp_trim_words($message, 30),
            home_url()
        );
        
        wp_mail($guest_email, $subject, $body);
    }
    
    private function get_stock_status_label($product): string {
        if (!$product) return 'Unknown';
        
        switch ($product->get_stock_status()) {
            case 'instock': return 'In Stock';
            case 'outofstock': return 'Out of Stock';
            case 'onbackorder': return 'On Backorder';
            default: return 'In Stock';
        }
    }
    
    private function format_message_date(int $post_id): string {
        $time = get_the_time('U', $post_id);
        if (!$time) return 'Unknown';
        
        $diff = time() - $time;
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) {
            $mins = round($diff / 60);
            return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
        }
        if ($diff < 86400) {
            $hours = round($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        }
        if ($diff < 604800) {
            $days = round($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        }
        return get_the_date('M j, Y', $post_id);
    }
    
    public function hide_messaging_bubble($items, $args) {
        if (!$this->messaging_active) {
            if (is_string($items)) {
                $items = preg_replace('/<li[^>]*class="[^"]*portalcloud9-bubble-menu[^"]*"[^>]*>.*?<\/li>/is', '', $items);
            }
        }
        return $items;
    }
    
    public function add_dashboard_notification_script(): void {
        if (!is_user_logged_in() || !get_query_var('portalcloud9_dashboard') || !$this->messaging_active) {
            return;
        }
        ?>
        <?php
    }
    
    /**
     * Update user's last seen time
     * Called whenever user performs an action (loads inbox, sends message, etc.)
     */
    private function update_user_last_seen($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        $timestamp = current_time('timestamp');
        update_user_meta($user_id, 'p9_last_seen', $timestamp);
        
        
        return true;
    }
    
    /**
     * Check if user is currently online
     * User is considered online if last seen within 5 minutes
     * 
     * @param int $user_id User ID to check
     * @return bool True if user is online, false otherwise
     */
    private function is_user_online($user_id) {
        if (!$user_id) {
            return false;
        }
        
        $last_seen = get_user_meta($user_id, 'p9_last_seen', true);
        
        if (!$last_seen) {
            return false;
        }
        
        // User is online if last seen within 5 minutes (300 seconds)
        $online_threshold = 300;
        $current_time = current_time('timestamp');
        $time_diff = $current_time - $last_seen;
        
        $is_online = $time_diff <= $online_threshold;
        
        
        return $is_online;
    }
    
    /**
     * AJAX: Check user online status
     * Returns online/offline status for specified user ID
     */
    private function ajax_p9_check_user_status() {
        $current_user_id = get_current_user_id();
        
        // Update current user's last seen time
        $this->update_user_last_seen($current_user_id);
        
        $user_id = absint($_POST['user_id'] ?? 0);
        
        
        if (!$user_id) {
            wp_send_json_error('User ID is required');
            return;
        }
        
        // Get the other user's last seen time
        $last_seen = get_user_meta($user_id, 'p9_last_seen', true);
        $is_online = $this->is_user_online($user_id);
        
        // Debug info
        $current_time = current_time('timestamp');
        $time_diff = $last_seen ? ($current_time - $last_seen) : 0;
        
        $result = [
            'is_online' => $is_online,
            'user_id' => $user_id,
            'status' => $is_online ? 'online' : 'offline',
            'last_seen' => $last_seen,
            'last_seen_formatted' => $last_seen ? gmdate('Y-m-d H:i:s', $last_seen) : 'Never',
            'current_time' => $current_time,
            'current_time_formatted' => gmdate('Y-m-d H:i:s', $current_time),
            'time_diff_seconds' => $time_diff,
            'time_diff_minutes' => $time_diff ? round($time_diff / 60, 1) : 0,
            'threshold_minutes' => 5,
        ];
        
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Update user heartbeat
     * Simply updates the current user's last_seen timestamp
     */
    private function ajax_p9_update_heartbeat() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('User not logged in');
            return;
        }
        
        $timestamp = current_time('timestamp');
        update_user_meta($user_id, 'p9_last_seen', $timestamp);
        
        
        wp_send_json_success([
            'user_id' => $user_id,
            'timestamp' => $timestamp,
            'formatted_time' => gmdate('Y-m-d H:i:s', $timestamp),
            'message' => 'Heartbeat updated'
        ]);
    }
}

new PortalCloud9_Messaging_Integration();
