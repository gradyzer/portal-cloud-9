<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
/**
 * Portal Cloud 9 - Account Tab Template
 * Fixed version with proper role checking
 */
defined('ABSPATH') || exit;

$current_user = wp_get_current_user();
$user_id = get_current_user_id();
$first_name = get_user_meta($user_id, 'first_name', true);
$last_name = get_user_meta($user_id, 'last_name', true);
$phone = get_user_meta($user_id, 'billing_phone', true);
$bio = get_user_meta($user_id, 'description', true);

// Custom avatar handling
$custom_avatar_id = get_user_meta($user_id, 'portcld9_custom_avatar', true);
if ($custom_avatar_id) {
    $avatar_url = wp_get_attachment_url($custom_avatar_id);
}
if (empty($avatar_url)) {
    $avatar_url = get_avatar_url($user_id, ['size' => 120]);
}

// Address arrays for WooCommerce
$billing_address = [];
$shipping_address = [];

if (class_exists('WooCommerce')) {
    $billing_address = [
        'address_1' => get_user_meta($user_id, 'billing_address_1', true),
        'address_2' => get_user_meta($user_id, 'billing_address_2', true),
        'city' => get_user_meta($user_id, 'billing_city', true),
        'state' => get_user_meta($user_id, 'billing_state', true),
        'postcode' => get_user_meta($user_id, 'billing_postcode', true),
        'country' => get_user_meta($user_id, 'billing_country', true),
    ];
    $shipping_address = [
        'address_1' => get_user_meta($user_id, 'shipping_address_1', true),
        'address_2' => get_user_meta($user_id, 'shipping_address_2', true),
        'city' => get_user_meta($user_id, 'shipping_city', true),
        'state' => get_user_meta($user_id, 'shipping_state', true),
        'postcode' => get_user_meta($user_id, 'shipping_postcode', true),
        'country' => get_user_meta($user_id, 'shipping_country', true),
    ];
}

// FIX: Properly handle empty or missing roles
$user_role = 'User'; // Default fallback
if (is_object($current_user) && isset($current_user->roles) && is_array($current_user->roles) && !empty($current_user->roles)) {
    $first_role = reset($current_user->roles); // Get first role safely
    if (!empty($first_role) && is_string($first_role)) {
        $user_role = ucfirst($first_role);
    }
}
?>
<?php // NOTE: account.css is enqueued via wp_enqueue_scripts in portal-cloud-9.php ?>

<div class="p9-account-wrapper">
    <!-- Hidden SVG gradient defs for password eye icons (red→black diagonal) -->
    <svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden;">
        <defs>
            <linearGradient id="p9-eye-gradient" x1="0" x2="1" y1="0" y2="1">
                <stop offset="0%" stop-color="#ff0000"/>
                <stop offset="100%" stop-color="#000000"/>
            </linearGradient>
        </defs>
    </svg>
    <div class="p9-messages-container" id="p9-account-messages"></div>

    <div class="p9-account-header">
        <div class="p9-account-header-content">
            <div class="p9-avatar-section">
                <div class="p9-avatar-container">
                    <img alt="<?php echo esc_attr($current_user->display_name); ?>" 
                         class="p9-avatar-image" 
                         id="p9-avatar-preview" 
                         src="<?php echo esc_url($avatar_url); ?>">
                    <button class="p9-avatar-upload" type="button" id="p9-avatar-upload-btn" title="Change Avatar">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                            <circle cx="12" cy="13" r="4"></circle>
                        </svg>
                    </button>
                    <input id="p9-avatar-input" type="file" accept="image/*" style="display:none">
                </div>
            </div>
            <div class="p9-account-header-info">
                <h1><?php echo esc_html($current_user->display_name); ?></h1>
                <p class="p9-account-email"><?php echo esc_html($current_user->user_email); ?></p>
                <span class="p9-account-role"><?php echo esc_html($user_role); ?></span>
            </div>
        </div>
    </div>

    <div class="p9-account-tabs-wrapper">
        <div class="p9-account-tabs">
            <button class="p9-tab-btn active" data-tab="profile">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                <span>Profile</span>
            </button>
            <button class="p9-tab-btn" data-tab="security">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect height="11" rx="2" ry="2" width="18" x="3" y="11"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <span>Security</span>
            </button>
            <?php if (class_exists('WooCommerce')): ?>
            <button class="p9-tab-btn" data-tab="addresses">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                    <circle cx="12" cy="10" r="3"></circle>
                </svg>
                <span>Addresses</span>
            </button>
            <?php endif; ?>
            <button class="p9-tab-btn" data-tab="preferences">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M12 1v6m0 6v6m8.66-15.66l-4.24 4.24m-4.24 4.24L7.93 16.07m13.07-.07l-6-6m-6 6l-4.24 4.24M23 12h-6m-6 0H1"></path>
                </svg>
                <span>Preferences</span>
            </button>
            <button class="p9-tab-btn" data-tab="privacy">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                </svg>
                <span>Privacy</span>
            </button>
        </div>
    </div>

    <form class="p9-account-content" id="p9-account-form">
        <input name="action" value="portalcloud9_update_account" type="hidden">
        <input name="nonce" value="<?php echo esc_attr( wp_create_nonce('portalcloud9_nonce') ); ?>" type="hidden">

        <!-- Profile Tab -->
        <div class="p9-tab-content active" data-content="profile">
            <div class="p9-section">
                <h2 class="p9-section-title">Personal Information</h2>
                <p class="p9-section-description">Update your personal details and profile information</p>

                <div class="p9-form-grid">
                    <div class="p9-form-group">
                        <label for="p9-display-name">Display Name <span class="p9-required">*</span></label>
                        <input name="display_name" id="p9-display-name" class="p9-input" value="<?php echo esc_attr($current_user->display_name); ?>" required>
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-email">Email Address <span class="p9-required">*</span></label>
                        <input name="email" id="p9-email" class="p9-input" value="<?php echo esc_attr($current_user->user_email); ?>" required type="email">
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-first-name">First Name</label>
                        <input name="first_name" id="p9-first-name" class="p9-input" value="<?php echo esc_attr($first_name); ?>">
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-last-name">Last Name</label>
                        <input name="last_name" id="p9-last-name" class="p9-input" value="<?php echo esc_attr($last_name); ?>">
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-phone">Phone Number</label>
                        <input name="phone" id="p9-phone" class="p9-input" value="<?php echo esc_attr($phone); ?>" type="tel" placeholder="+1 (555) 000-0000">
                    </div>
                    <div class="p9-form-group p9-full-width">
                        <label for="p9-bio">Bio</label>
                        <textarea class="p9-textarea" id="p9-bio" name="bio" placeholder="Tell us about yourself..." rows="4"><?php echo esc_textarea($bio); ?></textarea>
                        <span class="p9-hint">Brief description for your profile. Maximum 500 characters.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Tab -->
        <div class="p9-tab-content" data-content="security">
            <div class="p9-section">
                <h2 class="p9-section-title">Password & Security</h2>
                <p class="p9-section-description">Manage your password and account security settings</p>

                <div class="p9-form-grid">
                    <div class="p9-form-group p9-full-width">
                        <label for="p9-current-password">Current Password</label>
                        <div class="p9-password-input">
                            <input name="current_password" id="p9-current-password" class="p9-input" type="password" autocomplete="current-password">
                            <button class="p9-password-toggle" type="button" data-target="p9-current-password">
                                <svg fill="none" stroke="url(#p9-eye-gradient)" stroke-width="2" viewBox="0 0 24 24" class="p9-eye-open">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg fill="none" stroke="url(#p9-eye-gradient)" stroke-width="2" viewBox="0 0 24 24" class="p9-eye-closed" style="display:none">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                    <line x1="1" x2="23" y1="1" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                        <span class="p9-hint">Required to change password</span>
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-new-password">New Password</label>
                        <div class="p9-password-input">
                            <input name="new_password" id="p9-new-password" class="p9-input" type="password" autocomplete="new-password">
                            <button class="p9-password-toggle" type="button" data-target="p9-new-password">
                                <svg fill="none" stroke="url(#p9-eye-gradient)" stroke-width="2" viewBox="0 0 24 24" class="p9-eye-open">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg fill="none" stroke="url(#p9-eye-gradient)" stroke-width="2" viewBox="0 0 24 24" class="p9-eye-closed" style="display:none">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                    <line x1="1" x2="23" y1="1" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-confirm-password">Confirm New Password</label>
                        <div class="p9-password-input">
                            <input name="confirm_password" id="p9-confirm-password" class="p9-input" type="password" autocomplete="new-password">
                            <button class="p9-password-toggle" type="button" data-target="p9-confirm-password">
                                <svg fill="none" stroke="url(#p9-eye-gradient)" stroke-width="2" viewBox="0 0 24 24" class="p9-eye-open">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg fill="none" stroke="url(#p9-eye-gradient)" stroke-width="2" viewBox="0 0 24 24" class="p9-eye-closed" style="display:none">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                    <line x1="1" x2="23" y1="1" y2="23"></line>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="p9-info-box">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" x2="12" y1="16" y2="12"></line>
                        <line x1="12" x2="12.01" y1="8" y2="8"></line>
                    </svg>
                    <div>
                        <strong>Password Requirements:</strong>
                        <ul>
                            <li>At least 8 characters long</li>
                            <li>Include uppercase and lowercase letters</li>
                            <li>Include at least one number</li>
                            <li>Special characters recommended for extra security</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <?php if (class_exists('WooCommerce')): ?>
        <!-- Addresses Tab -->
        <div class="p9-tab-content" data-content="addresses">
            <div class="p9-section">
                <h2 class="p9-section-title">Billing Address</h2>
                <p class="p9-section-description">Manage your billing address for orders</p>

                <div class="p9-form-grid">
                    <div class="p9-form-group p9-full-width">
                        <label for="p9-billing-address-1">Street Address</label>
                        <input name="billing_address_1" id="p9-billing-address-1" class="p9-input" value="<?php echo esc_attr($billing_address['address_1'] ?? ''); ?>">
                    </div>
                    <div class="p9-form-group p9-full-width">
                        <label for="p9-billing-address-2">Apartment, suite, etc. (optional)</label>
                        <input name="billing_address_2" id="p9-billing-address-2" class="p9-input" value="<?php echo esc_attr($billing_address['address_2'] ?? ''); ?>">
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-billing-city">City</label>
                        <input name="billing_city" id="p9-billing-city" class="p9-input" value="<?php echo esc_attr($billing_address['city'] ?? ''); ?>">
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-billing-state">State / Province</label>
                        <input name="billing_state" id="p9-billing-state" class="p9-input" value="<?php echo esc_attr($billing_address['state'] ?? ''); ?>">
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-billing-postcode">Postal Code</label>
                        <input name="billing_postcode" id="p9-billing-postcode" class="p9-input" value="<?php echo esc_attr($billing_address['postcode'] ?? ''); ?>">
                    </div>
                    <div class="p9-form-group">
                        <label for="p9-billing-country">Country</label>
                        <input name="billing_country" id="p9-billing-country" class="p9-input" value="<?php echo esc_attr($billing_address['country'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="p9-section">
                <h2 class="p9-section-title">Shipping Address</h2>
                <p class="p9-section-description">Manage your default shipping address</p>

                <div class="p9-form-grid">
                    <div class="p9-form-group p9-full-width">
                        <label class="p9-checkbox">
                            <input name="same_as_billing" id="p9-same-as-billing" type="checkbox">
                            <span>Same as billing address</span>
                        </label>
                    </div>
                    <div id="p9-shipping-fields">
                        <div class="p9-form-grid">
                            <div class="p9-form-group p9-full-width">
                                <label for="p9-shipping-address-1">Street Address</label>
                                <input name="shipping_address_1" id="p9-shipping-address-1" class="p9-input" value="<?php echo esc_attr($shipping_address['address_1'] ?? ''); ?>">
                            </div>
                            <div class="p9-form-group p9-full-width">
                                <label for="p9-shipping-address-2">Apartment, suite, etc. (optional)</label>
                                <input name="shipping_address_2" id="p9-shipping-address-2" class="p9-input" value="<?php echo esc_attr($shipping_address['address_2'] ?? ''); ?>">
                            </div>
                            <div class="p9-form-group">
                                <label for="p9-shipping-city">City</label>
                                <input name="shipping_city" id="p9-shipping-city" class="p9-input" value="<?php echo esc_attr($shipping_address['city'] ?? ''); ?>">
                            </div>
                            <div class="p9-form-group">
                                <label for="p9-shipping-state">State / Province</label>
                                <input name="shipping_state" id="p9-shipping-state" class="p9-input" value="<?php echo esc_attr($shipping_address['state'] ?? ''); ?>">
                            </div>
                            <div class="p9-form-group">
                                <label for="p9-shipping-postcode">Postal Code</label>
                                <input name="shipping_postcode" id="p9-shipping-postcode" class="p9-input" value="<?php echo esc_attr($shipping_address['postcode'] ?? ''); ?>">
                            </div>
                            <div class="p9-form-group">
                                <label for="p9-shipping-country">Country</label>
                                <input name="shipping_country" id="p9-shipping-country" class="p9-input" value="<?php echo esc_attr($shipping_address['country'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Preferences Tab -->
        <div class="p9-tab-content" data-content="preferences">
            <div class="p9-section">
                <h2 class="p9-section-title">Notification Preferences</h2>
                <p class="p9-section-description">Choose what updates you want to receive</p>

                <div class="p9-preferences-list">
                    <div class="p9-preference-item">
                        <div class="p9-preference-info">
                            <h4>Email Notifications</h4>
                            <p>Receive email updates about your account activity</p>
                        </div>
                        <label class="p9-switch">
                            <input name="email_notifications" type="checkbox" <?php checked(get_user_meta($user_id, 'portcld9_email_notifications', true), '1'); ?>>
                            <span class="p9-switch-slider"></span>
                        </label>
                    </div>
                    <div class="p9-preference-item">
                        <div class="p9-preference-info">
                            <h4>Order Updates</h4>
                            <p>Get notified about order status changes</p>
                        </div>
                        <label class="p9-switch">
                            <input name="order_notifications" type="checkbox" <?php checked(get_user_meta($user_id, 'portcld9_order_notifications', true), '1'); ?>>
                            <span class="p9-switch-slider"></span>
                        </label>
                    </div>
                    <div class="p9-preference-item">
                        <div class="p9-preference-info">
                            <h4>Message Notifications</h4>
                            <p>Receive alerts for new messages</p>
                        </div>
                        <label class="p9-switch">
                            <input name="message_notifications" type="checkbox" <?php checked(get_user_meta($user_id, 'portcld9_message_notifications', true), '1'); ?>>
                            <span class="p9-switch-slider"></span>
                        </label>
                    </div>
                    <div class="p9-preference-item">
                        <div class="p9-preference-info">
                            <h4>Marketing Communications</h4>
                            <p>Receive promotional emails and special offers</p>
                        </div>
                        <label class="p9-switch">
                            <input name="marketing_emails" type="checkbox" <?php checked(get_user_meta($user_id, 'portcld9_marketing_emails', true), '1'); ?>>
                            <span class="p9-switch-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Privacy Tab -->
        <div class="p9-tab-content" data-content="privacy">
            <div class="p9-section">
                <h2 class="p9-section-title">Data & Privacy</h2>
                <p class="p9-section-description">Manage your data and privacy settings</p>

                <div class="p9-privacy-actions">
                    <div class="p9-privacy-card">
                        <div class="p9-privacy-icon">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" x2="12" y1="15" y2="3"></line>
                            </svg>
                        </div>
                        <div class="p9-privacy-content">
                            <h4>Download Your Data</h4>
                            <p>Export all your account data in JSON format</p>
                            <button class="p9-btn p9-btn-secondary" type="button" id="p9-export-data">Download Data</button>
                        </div>
                    </div>
                    <div class="p9-privacy-card p9-privacy-danger">
                        <div class="p9-privacy-icon">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="15" x2="9" y1="9" y2="15"></line>
                                <line x1="9" x2="15" y1="9" y2="15"></line>
                            </svg>
                        </div>
                        <div class="p9-privacy-content">
                            <h4>Delete Account</h4>
                            <p>Permanently delete your account and all associated data</p>
                            <button class="p9-btn p9-btn-danger" type="button" id="p9-delete-account">Delete Account</button>
                        </div>
                    </div>
                </div>

                <div class="p9-info-box p9-info-warning">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" x2="12" y1="9" y2="13"></line>
                        <line x1="12" x2="12.01" y1="17" y2="17"></line>
                    </svg>
                    <div>
                        <strong>Important:</strong> Account deletion is permanent and cannot be undone. All your data, orders, and messages will be permanently removed.
                    </div>
                </div>
            </div>
        </div>

        <div class="p9-form-actions">
            <button class="p9-btn p9-btn-secondary" type="button" id="p9-reset-form">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="1 4 1 10 7 10"></polyline>
                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                </svg>
                Reset Changes
            </button>
            <button class="p9-btn p9-btn-primary" type="submit" id="p9-save-account">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                    <polyline points="7 3 7 8 15 8"></polyline>
                </svg>
                Save Changes
            </button>
        </div>
    </form>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>
