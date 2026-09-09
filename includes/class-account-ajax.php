<?php
/**
 * Account AJAX Handler
 * Handles user account updates, avatar uploads, and data export/deletion
 * 
 * @package Portal_Cloud_9
 */

defined('ABSPATH') || exit;

/**
 * Portal Cloud 9 Account AJAX Handler
 */
class PortalCloud9_Account_Ajax
{
    /**
     * Constructor - Register AJAX hooks
     */
    public function __construct()
    {
        add_action('wp_ajax_portalcloud9_update_account', [$this, 'update_account']);
        add_action('wp_ajax_portalcloud9_upload_avatar', [$this, 'upload_avatar']);
        add_action('wp_ajax_portalcloud9_export_user_data', [$this, 'export_user_data']);
        add_action('wp_ajax_portalcloud9_delete_user_account', [$this, 'delete_user_account']);
    }
    
    /**
     * Update user account information via AJAX
     */
    public function update_account()
    {
        // Verify nonce
        check_ajax_referer( 'portalcloud9_nonce', 'nonce' );
        
        // Check user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to update your account.');
        }
        
        $user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        
        // Sanitize input fields
        $display_name = isset($_POST['display_name']) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $email = isset($_POST['email']) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $bio = isset($_POST['bio']) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '';
        
        // Validate required fields
        if (empty($display_name)) {
            wp_send_json_error('Display name is required.');
        }
        
        if (empty($email) || !is_email($email)) {
            wp_send_json_error('Valid email address is required.');
        }
        
        // Check if email already exists
        if ($email !== $current_user->user_email) {
            $email_exists = email_exists($email);
            if ($email_exists && $email_exists !== $user_id) {
                wp_send_json_error('This email address is already in use.');
            }
        }
        
        // Validate bio length
        if (strlen($bio) > 500) {
            wp_send_json_error('Bio must not exceed 500 characters.');
        }
        
        // Prepare user data
        $userdata = [
            'ID' => $user_id,
            'display_name' => $display_name,
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];
        
        // Handle password change
        $current_password = isset( $_POST['current_password'] ) ? wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password not sanitized intentionally.
        $new_password = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password not sanitized intentionally.
        $confirm_password = isset( $_POST['confirm_password'] ) ? wp_unslash( $_POST['confirm_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password not sanitized intentionally.
        
        if (!empty($new_password) || !empty($confirm_password)) {
            if (empty($current_password)) {
                wp_send_json_error('Current password is required to change your password.');
            }
            
            if (!wp_check_password($current_password, $current_user->user_pass, $user_id)) {
                wp_send_json_error('Current password is incorrect.');
            }
            
            if ($new_password !== $confirm_password) {
                wp_send_json_error('New passwords do not match.');
            }
            
            if (strlen($new_password) < 8) {
                wp_send_json_error('New password must be at least 8 characters long.');
            }
            
            $userdata['user_pass'] = $new_password;
        }
        
        // Update user
        $result = wp_update_user($userdata);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Update user meta
        update_user_meta($user_id, 'billing_phone', $phone);
        update_user_meta($user_id, 'description', $bio);
        
        // Update WooCommerce addresses if available
        if (class_exists('WooCommerce')) {
            $this->update_addresses($user_id);
        }
        
        // Update preferences
        $this->update_preferences($user_id);
        
        // Prepare response
        $response_data = [
            'message' => 'Account updated successfully!',
            'display_name' => $display_name,
        ];
        
        if (!empty($new_password)) {
            $response_data['message'] = 'Account and password updated successfully!';
        }
        
        wp_send_json_success($response_data);
    }
    
    /**
     * Update user billing and shipping addresses
     * 
     * @param int $user_id User ID
     */
    private function update_addresses($user_id)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() in the public caller update_account().
        // Update billing address
        $billing_fields = [
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
        ];
        
        foreach ($billing_fields as $field) {
            if (isset($_POST[$field])) {
                update_user_meta($user_id, $field, sanitize_text_field( wp_unslash( $_POST[$field] ) ));
            }
        }
        
        // Check if shipping address is same as billing
        $same_as_billing = isset($_POST['same_as_billing']) && $_POST['same_as_billing'] === 'on';
        
        if ($same_as_billing) {
            // Copy billing to shipping
            update_user_meta($user_id, 'shipping_address_1', get_user_meta($user_id, 'billing_address_1', true));
            update_user_meta($user_id, 'shipping_address_2', get_user_meta($user_id, 'billing_address_2', true));
            update_user_meta($user_id, 'shipping_city', get_user_meta($user_id, 'billing_city', true));
            update_user_meta($user_id, 'shipping_state', get_user_meta($user_id, 'billing_state', true));
            update_user_meta($user_id, 'shipping_postcode', get_user_meta($user_id, 'billing_postcode', true));
            update_user_meta($user_id, 'shipping_country', get_user_meta($user_id, 'billing_country', true));
        } else {
            // Update shipping address separately
            $shipping_fields = [
                'shipping_address_1',
                'shipping_address_2',
                'shipping_city',
                'shipping_state',
                'shipping_postcode',
                'shipping_country',
            ];
            
            foreach ($shipping_fields as $field) {
                if (isset($_POST[$field])) {
                    update_user_meta($user_id, $field, sanitize_text_field( wp_unslash( $_POST[$field] ) ));
                }
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }
    
    /**
     * Update user notification preferences
     * 
     * @param int $user_id User ID
     */
    private function update_preferences($user_id)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() in the public caller update_account().
        $preferences = [
            'email_notifications',
            'order_notifications',
            'message_notifications',
            'marketing_emails',
        ];
        
        foreach ($preferences as $pref) {
            $value = isset($_POST[$pref]) && $_POST[$pref] === 'on' ? '1' : '0';
            update_user_meta($user_id, 'portcld9_' . $pref, $value);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }
    
    /**
     * Upload user avatar via AJAX
     */
    public function upload_avatar()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in.');
        }
        
        $user_id = get_current_user_id();
        
        if (empty($_FILES['avatar'])) {
            wp_send_json_error('No file uploaded.');
        }
        
        $file = $_FILES['avatar']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File validated via wp_handle_upload().
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            wp_send_json_error('Invalid file type. Please upload a valid image.');
        }
        
        // Validate file size (2MB max)
        if ($file['size'] > 2 * 1024 * 1024) {
            wp_send_json_error('File size must be less than 2MB.');
        }
        
        // Upload file
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        
        $upload_overrides = [
            'test_form' => false,
        ];
        
        $uploaded_file = wp_handle_upload($file, $upload_overrides);
        
        if (isset($uploaded_file['error'])) {
            wp_send_json_error($uploaded_file['error']);
        }
        
        // Create attachment
        $attachment = [
            'post_mime_type' => $uploaded_file['type'],
            'post_title' => sanitize_file_name($file['name']),
            'post_content' => '',
            'post_status' => 'inherit',
        ];
        
        $attach_id = wp_insert_attachment($attachment, $uploaded_file['file']);
        
        if (is_wp_error($attach_id)) {
            wp_send_json_error('Failed to create attachment.');
        }
        
        // Generate metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $uploaded_file['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Save avatar ID to user meta
        update_user_meta($user_id, 'portcld9_custom_avatar', $attach_id);
        
        wp_send_json_success([
            'url' => wp_get_attachment_url($attach_id),
            'message' => 'Avatar updated successfully!',
        ]);
    }
    
    /**
     * Export user data via AJAX
     */
    public function export_user_data()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in.');
        }
        
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        
        // Compile user data
        $export_data = [
            'user_info' => [
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'first_name' => get_user_meta($user_id, 'first_name', true),
                'last_name' => get_user_meta($user_id, 'last_name', true),
                'bio' => get_user_meta($user_id, 'description', true),
                'registered_date' => $user->user_registered,
                'role' => !empty($user->roles) ? $user->roles[0] : '',
            ],
            'contact_info' => [
                'phone' => get_user_meta($user_id, 'billing_phone', true),
            ],
            'preferences' => [
                'email_notifications' => get_user_meta($user_id, 'portcld9_email_notifications', true),
                'order_notifications' => get_user_meta($user_id, 'portcld9_order_notifications', true),
                'message_notifications' => get_user_meta($user_id, 'portcld9_message_notifications', true),
                'marketing_emails' => get_user_meta($user_id, 'portcld9_marketing_emails', true),
            ],
        ];
        
        // Add WooCommerce data if available
        if (class_exists('WooCommerce')) {
            $export_data['billing_address'] = [
                'address_1' => get_user_meta($user_id, 'billing_address_1', true),
                'address_2' => get_user_meta($user_id, 'billing_address_2', true),
                'city' => get_user_meta($user_id, 'billing_city', true),
                'state' => get_user_meta($user_id, 'billing_state', true),
                'postcode' => get_user_meta($user_id, 'billing_postcode', true),
                'country' => get_user_meta($user_id, 'billing_country', true),
            ];
            
            $export_data['shipping_address'] = [
                'address_1' => get_user_meta($user_id, 'shipping_address_1', true),
                'address_2' => get_user_meta($user_id, 'shipping_address_2', true),
                'city' => get_user_meta($user_id, 'shipping_city', true),
                'state' => get_user_meta($user_id, 'shipping_state', true),
                'postcode' => get_user_meta($user_id, 'shipping_postcode', true),
                'country' => get_user_meta($user_id, 'shipping_country', true),
            ];
            
            $customer = new WC_Customer($user_id);
            $export_data['woocommerce'] = [
                'total_orders' => $customer->get_order_count(),
                'total_spent' => $customer->get_total_spent(),
            ];
        }
        
        // Add export metadata
        $export_data['export_info'] = [
            'exported_at' => current_time('mysql'),
            'timezone' => get_option('timezone_string'),
            'site_url' => get_site_url(),
        ];
        
        // Allow filtering of export data
        $export_data = apply_filters('portalcloud9_user_export_data', $export_data, $user_id);
        
        wp_send_json_success($export_data);
    }
    
    /**
     * Request account deletion via AJAX
     */
    public function delete_user_account()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in.');
        }
        
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        
        // Prevent administrators from deleting their own account
        if (in_array('administrator', $user->roles)) {
            wp_send_json_error('Administrators cannot delete their own account. Please contact another administrator.');
        }
        
        // Mark account for deletion
        update_user_meta($user_id, 'portcld9_deletion_requested', current_time('mysql'));
        
        // Send notification to admin
        $admin_email = get_option('admin_email');
        $subject = sprintf('[%s] Account Deletion Request', get_bloginfo('name'));
        $message = sprintf(
            "User %s (%s) has requested account deletion.\n\nUser ID: %d\nEmail: %s\nRequested at: %s\n\nPlease review and process this request in the WordPress admin panel.",
            $user->display_name,
            $user->user_login,
            $user_id,
            $user->user_email,
            current_time('mysql')
        );
        
        wp_mail($admin_email, $subject, $message);
        
        // Send confirmation to user
        $user_subject = sprintf('[%s] Account Deletion Request Received', get_bloginfo('name'));
        $user_message = sprintf(
            "Hello %s,\n\nWe have received your account deletion request. An administrator will review your request and contact you shortly.\n\nIf you did not request this, please contact us immediately.\n\nBest regards,\n%s",
            $user->display_name,
            get_bloginfo('name')
        );
        
        wp_mail($user->user_email, $user_subject, $user_message);
        
        wp_send_json_success('Your account deletion request has been submitted. An administrator will review your request and contact you shortly.');
    }
}

// Initialize
new PortalCloud9_Account_Ajax();
