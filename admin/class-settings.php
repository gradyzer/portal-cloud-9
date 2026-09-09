<?php
/**
 * Portal Cloud 9 - Settings Page with Authentication
 */

defined('ABSPATH') || exit;

final class PortalCloud9_Settings
{
    const MENU_SLUG = 'portalcloud9-settings';
    const OPTION_GROUP = 'portalcloud9_opts';
    const OPTION_NAME = 'portalcloud9_options';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_menu', [$this, 'reorder_submenu'], 999);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_redirect_upgrade']);
        add_action('wp_ajax_portcld9_clear_cache', [$this, 'ajax_clear_cache']);
        add_action('wp_ajax_portcld9_toggle_option', [$this, 'ajax_toggle_option']);
        add_action('wp_ajax_portcld9_purge_data',   [$this, 'ajax_purge_data']);

        // Add plugin action links
        add_filter('plugin_action_links_' . plugin_basename(PORTCLD9_PLUGIN_FILE), [$this, 'add_plugin_action_links']);
    }

    /**
     * Redirect the Upgrade submenu page straight to the Pro website.
     * Fires on admin_init before any HTML is sent, so the redirect works cleanly.
     */
    public function maybe_redirect_upgrade(): void
    {
        if (
            ! empty( $_GET['page'] ) && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            sanitize_key( wp_unslash( $_GET['page'] ) ) === 'portalcloud9-upgrade' && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            current_user_can( 'manage_options' )
        ) {
            wp_safe_redirect( 'https://gradyzer.com/wordpress/portal-cloud-9-pro/' );
            exit;
        }
    }

    public function add_admin_menu(): void
    {
        // Add main menu page (this auto-creates a submenu with same name)
        add_menu_page(
            'Portal Cloud 9 Settings',
            'Portal Cloud 9',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-cloud',
            30
        );

        // add_submenu_page() returns the actual hook suffix WordPress generates.
        // We capture these and wire enqueue actions directly to them — this is the
        // only 100% reliable way to load CSS for submenu pages, since the hook name
        // formula ({parent_menu_title}_page_{slug}) depends on sanitize_title() of
        // the menu title, which is not always predictable.

        // Add Getting Started FIRST (position 0, pushing auto-generated to position 1)
        $gs_hook = add_submenu_page(
            self::MENU_SLUG,
            'Getting Started',
            '🚩 Getting Started',
            'manage_options',
            'portalcloud9-getting-started',
            [$this, 'render_getting_started_page']
        );
        add_action( 'admin_enqueue_scripts', function( $hook ) use ( $gs_hook ) {
            if ( $hook !== $gs_hook ) return;
            $ver = defined('PORTCLD9_VERSION') ? PORTCLD9_VERSION . '.' . time() : '1.0';
            wp_enqueue_style(
                'portalcloud9-getting-started',
                PORTCLD9_PLUGIN_URL . 'assets/css/admin-getting-started.css',
                [],
                $ver
            );
        } );

        // Rename the auto-generated first submenu to "Settings"
        add_submenu_page(
            self::MENU_SLUG,
            'Settings',
            'Settings',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_settings_page']
        );

        // Shortcodes page
        $sc_hook = add_submenu_page(
            self::MENU_SLUG,
            'Shortcodes',
            'Shortcodes',
            'manage_options',
            'portalcloud9-shortcodes',
            [$this, 'render_shortcodes_page']
        );
        add_action( 'admin_enqueue_scripts', function( $hook ) use ( $sc_hook ) {
            if ( $hook !== $sc_hook ) return;
            $ver = defined('PORTCLD9_VERSION') ? PORTCLD9_VERSION . '.' . time() : '1.0';
            wp_enqueue_style(
                'portalcloud9-shortcodes',
                PORTCLD9_PLUGIN_URL . 'assets/css/admin-shortcodes.css',
                [],
                $ver
            );
        } );

        // Upgrade to Pro page (4th menu item, styled orange)
        add_submenu_page(
            self::MENU_SLUG,
            'Upgrade to Pro — Portal Cloud 9',
            '⚡ Upgrade to Pro',
            'manage_options',
            'portalcloud9-upgrade',
            [$this, 'render_upgrade_page']
        );

        // Style the Upgrade menu item orange
        add_action( 'admin_head', function () {
            $screen = get_current_screen();
            if ( ! $screen ) { return; }
            ?>
            <style>
            #adminmenu li.wp-submenu-head ~ li a[href="admin.php?page=portalcloud9-upgrade"],
            #adminmenu a[href="admin.php?page=portalcloud9-upgrade"] {
                color: #ea580c !important;
                font-weight: 700 !important;
            }
            #adminmenu a[href="admin.php?page=portalcloud9-upgrade"]:hover,
            #adminmenu a[href="admin.php?page=portalcloud9-upgrade"]:focus {
                color: #f97316 !important;
            }
            </style>
            <?php
        } );
    }

    /**
     * Reorder submenu items to show Getting Started first
     */
    public function reorder_submenu(): void
    {
        global $submenu;

        if ( ! isset( $submenu[ self::MENU_SLUG ] ) ) {
            return;
        }

        $getting_started = null;
        $settings        = null;
        $shortcodes      = null;
        $upgrade         = null;

        foreach ( $submenu[ self::MENU_SLUG ] as $item ) {
            if ( $item[2] === 'portalcloud9-getting-started' ) {
                $getting_started = $item;
            } elseif ( $item[2] === self::MENU_SLUG ) {
                $settings = $item;
            } elseif ( $item[2] === 'portalcloud9-shortcodes' ) {
                $shortcodes = $item;
            } elseif ( $item[2] === 'portalcloud9-upgrade' ) {
                $upgrade = $item;
            }
        }

        $new_order = [];
        if ( $getting_started ) { $new_order[] = $getting_started; }
        if ( $settings )        { $new_order[] = $settings; }
        if ( $shortcodes )      { $new_order[] = $shortcodes; }
        if ( $upgrade )         { $new_order[] = $upgrade; }

        $submenu[ self::MENU_SLUG ] = $new_order;
    }

    /**
     * Add Settings link to plugin action links in Plugins page
     */
    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
            esc_html__('Settings', 'portal-cloud-9')
        );

        $upgrade_link = sprintf(
            '<a href="%s" target="_blank" rel="noopener" style="color:#c2570a;font-weight:600;" title="Get advanced features, Pro analytics, full Reward Points management, and priority support">%s</a>',
            esc_url('https://gradyzer.com/wordpress/portal-cloud-9-pro/'),
            esc_html__('Upgrade to Pro', 'portal-cloud-9')
        );

        array_unshift($links, $settings_link);
        $links[] = $upgrade_link;

        return $links;
    }

    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            ['sanitize_callback' => [$this, 'sanitize_options']]
        );

        add_settings_section(
            'portalcloud9_main',
            'General',
            '__return_false',
            self::MENU_SLUG
        );

        foreach ($this->fields() as $key => $label) {
            add_settings_field(
                $key,
                $label,
                [$this, 'field_' . $key],
                self::MENU_SLUG,
                'portalcloud9_main'
            );
        }
    }

    private function fields(): array
    {
        return [
            'products_per_page'           => 'Products per page',
            'orders_per_page_manager'     => 'Orders per page (Shop Managers)',
            'orders_per_page_customer'    => 'Orders per page (Customers)',
            'enable_messaging'            => 'Enable messaging',
            'enable_product_inquiry'      => 'Enable product-inquiry chat',
            'enable_phone_contacts'       => 'Enable Contacted by Phone',
            'email_notifications'         => 'Email notifications',
            'admin_email'                 => 'Admin e-mail',
            'remove_data_on_uninstall'    => 'Remove data on uninstall',
            // Image processing
        ];
    }

    public function field_products_per_page(): void
    {
        $val = absint( $this->get_option( 'products_per_page', 15 ) );
        printf(
            '<input type="number" name="%1$s[products_per_page]" value="%2$d" min="5" max="50"><p class="description">Number of products displayed per page in the Products section.</p>',
            esc_attr( self::OPTION_NAME ),
            absint( $val ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint ensures integer.
        );
    }

    public function field_orders_per_page_manager(): void
    {
        $val = absint( $this->get_option( 'orders_per_page_manager', 15 ) );
        printf(
            '<input type="number" name="%1$s[orders_per_page_manager]" value="%2$d" min="5" max="100"><p class="description">Number of orders displayed per page for Shop Managers/Admins.</p>',
            esc_attr( self::OPTION_NAME ),
            absint( $val ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint ensures integer.
        );
    }

    public function field_orders_per_page_customer(): void
    {
        $val = absint( $this->get_option( 'orders_per_page_customer', 10 ) );
        printf(
            '<input type="number" name="%1$s[orders_per_page_customer]" value="%2$d" min="5" max="50"><p class="description">Number of orders displayed per page for Customers.</p>',
            esc_attr( self::OPTION_NAME ),
            absint( $val ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- absint ensures integer.
        );
    }

    public function field_enable_messaging(): void
    {
        $this->checkbox_field('enable_messaging', 'Built-in messaging system');
    }

    public function field_enable_product_inquiry(): void
    {
        $this->checkbox_field('enable_product_inquiry', 'Product-inquiry chat box');
    }

    public function field_enable_phone_contacts(): void
    {
        $this->checkbox_field('enable_phone_contacts', 'Contacted by Phone section');
    }

    /**
     * Helper: renders a ratio/size select using a shared options list.
     */

    private function image_size_options(): array
    {
        return [
            700  => '700 px — Compact (fast loading)',
            800  => '800 px — Small',
            900  => '900 px — Medium-small',
            1000 => '1000 px — Medium',
            1200 => '1200 px — Large',
            1400 => '1400 px — Extra large',
            1600 => '1600 px — High resolution',
        ];
    }

    private function select_field(string $key, array $options, string $description = ''): void
    {
        $current = $this->get_option($key, array_key_first($options));
        printf('<select name="%1$s[%2$s]">', esc_attr(self::OPTION_NAME), esc_attr($key));
        foreach ($options as $value => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr((string)$value),
                selected($current, (string)$value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }

    public function field_email_notifications(): void
    {
        $this->checkbox_field('email_notifications', 'Send e-mails on new messages');
    }

    public function field_admin_email(): void
    {
        $val = $this->get_option('admin_email', get_option('admin_email'));
        printf(
            '<input type="email" name="%1$s[admin_email]" value="%2$s" class="regular-text">',
            esc_attr(self::OPTION_NAME),
            esc_attr($val)
        );
    }

    public function field_remove_data_on_uninstall(): void
    {
        $this->checkbox_field('remove_data_on_uninstall', 'Remove ALL plugin data on uninstall (irreversible)');
    }

    public function sanitize_options($in): array
    {
        // Allowed ratio and size values for image processing fields

        $prev_armed = !empty($this->get_option('remove_data_on_uninstall'));
        $out = [];
        foreach ($this->fields() as $key => $label) {
            switch ($key) {
                case 'products_per_page':
                    $out[$key] = isset($in[$key]) ? min(50, max(5, (int)$in[$key])) : 15;
                    break;
                case 'orders_per_page_manager':
                    $out[$key] = isset($in[$key]) ? min(100, max(5, (int)$in[$key])) : 15;
                    break;
                case 'orders_per_page_customer':
                    $out[$key] = isset($in[$key]) ? min(50, max(5, (int)$in[$key])) : 10;
                    break;
                case 'admin_email':
                    $out[$key] = sanitize_email($in[$key] ?? '');
                    break;                default:
                    $out[$key] = !empty($in[$key]) ? 1 : 0;
            }
        }
        delete_transient('portalcloud9_stats');

        if (!$prev_armed && !empty($out['remove_data_on_uninstall']) && class_exists('PortalCld9_Audit_Log')) {
            PortalCld9_Audit_Log::record(get_current_user_id(), 'data_wipe_armed', 0, 'system', []);
        }

        return $out;
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading display tab, no state change.
        ?>
        <div class="wrap">
            <!-- Gradient hero -->
            <div class="portalcloud9-intro-card">
                <div class="portalcloud9-intro-left">
                    <?php
                    // Get plugin version from file header
                    if (!function_exists('get_plugin_data')) {
                        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
                    }
                    $plugin_data = get_plugin_data(PORTCLD9_PLUGIN_FILE);
                    $version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '8.1.5';
                    ?>
                    <h1 class="pc9-settings-title">
                        👋 Welcome to <b>Portal Cloud 9</b>
                        <span class="pc9-settings-version">v<?php echo esc_html($version); ?></span>
                    </h1>
                    <p>Your all-in-one user portal, messaging & product-inquiry suite for WooCommerce.</p>
                    <p class="portalcloud9-shortcode-hint">
                        <strong>📄 Dashboard:</strong> Portal Cloud 9 automatically creates a <code>user-portal</code> page at <code><?php echo esc_url(home_url('/user-portal/')); ?></code>
                    </p>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=portalcloud9-settings&tab=settings')); ?>" 
                   class="nav-tab <?php echo esc_attr( $current_tab === 'settings' ? 'nav-tab-active' : '' ); ?>">
                    Settings
                </a>

            </h2>

            <!-- Tab Content -->
            <?php $this->render_settings_tab(); ?>
        </div>
        <?php
    }

    private function render_settings_tab(): void
    {
        ?>
        <!-- Two-column layout: Settings (left) | Quick cards (right) -->
        <div class="portalcloud9-settings-columns">
            <!-- LEFT: Segmented Glassmorphic Settings Cards -->
            <div class="portalcloud9-settings-left">
                <form method="post" action="options.php">
                    <?php settings_fields(self::OPTION_GROUP); ?>
                    
                    <!-- Display Settings Glass Card -->
                    <div class="pc9-settings-glass-card">
                        <div class="pc9-card-header">
                            <span class="pc9-card-icon">📊</span>
                            <h3>Display Settings</h3>
                        </div>
                        <div class="pc9-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Products per page</th>
                                    <td><?php $this->field_products_per_page(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Orders per page (Shop Managers)</th>
                                    <td><?php $this->field_orders_per_page_manager(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Orders per page (Customers)</th>
                                    <td><?php $this->field_orders_per_page_customer(); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Features Glass Card -->
                    <div class="pc9-settings-glass-card">
                        <div class="pc9-card-header">
                            <span class="pc9-card-icon">⚡</span>
                            <h3>Feature Toggles</h3>
                        </div>
                        <div class="pc9-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Messaging System</th>
                                    <td><?php $this->field_enable_messaging(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Product Inquiry Chat</th>
                                    <td><?php $this->field_enable_product_inquiry(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Contacted by Phone</th>
                                    <td><?php $this->field_enable_phone_contacts(); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Notifications                    <!-- Notifications Glass Card -->
                    <div class="pc9-settings-glass-card">
                        <div class="pc9-card-header">
                            <span class="pc9-card-icon">📧</span>
                            <h3>Email Notifications</h3>
                        </div>
                        <div class="pc9-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Enable Email Notifications</th>
                                    <td><?php $this->field_email_notifications(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Admin Email Address</th>
                                    <td><?php $this->field_admin_email(); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Data Management Glass Card (Danger) -->
                    <div class="pc9-settings-glass-card pc9-danger-card">
                        <div class="pc9-card-header">
                            <span class="pc9-card-icon">🗑️</span>
                            <h3>Data Management</h3>
                        </div>
                        <div class="pc9-card-body">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Remove Data on Uninstall</th>
                                    <td><?php $this->field_remove_data_on_uninstall(); ?>
                                        <p class="description pc9-desc-mt">This plugin owns the database lifecycle for the whole Portal Cloud 9 ecosystem, the free plugin and Portal Cloud 9 Pro alike. When enabled, deleting the plugin will permanently erase all data created by both plugins from this site. If Portal Cloud 9 Pro is installed, deactivate it first so the wipe can drop its tables cleanly. WooCommerce data such as orders and products, and WordPress data such as user accounts, are never touched.</p>
                                    </td>
                                </tr>
                                <tr id="pc9-purge-row" style="<?php echo esc_attr( !empty($this->get_option('remove_data_on_uninstall')) ? '' : 'display:none' ); ?>">
                                    <th scope="row">Delete All Data Now</th>
                                    <td>
                                        <button type="button" id="pc9-purge-btn" class="button pc9-purge-btn">
                                            🗑️ Delete All Data Immediately
                                        </button>
                                        <p class="description pc9-purge-desc">This is irreversible. All settings, user data, messages, and database tables will be permanently deleted right now — without uninstalling the plugin.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Purge Confirmation Modal -->
                    <div id="pc9-purge-modal" class="pc9-purge-modal">
                        <div class="pc9-purge-modal-inner">
                            <div class="pc9-purge-modal-header">
                                <div class="pc9-purge-modal-icon">⚠️</div>
                                <h2 class="pc9-purge-modal-title">Delete All Portal Cloud 9 Data?</h2>
                                <p class="pc9-purge-modal-intro">This will permanently and immediately delete:</p>
                            </div>
                            <ul class="pc9-purge-modal-list">
                                <li>All plugin settings and configuration, free and Pro</li>
                                <li>All user meta (avatars, preferences, notifications)</li>
                                <li>All messages and inbox data</li>
                                <li>All phone contact and WhatsApp click records</li>
                                <li>All Visitor Analytics data and its tables, visits, form submissions, logins, campaigns</li>
                                <li>All shipments, sessions, reward point history, and two factor data</li>
                                <li>All favourites / wishlists</li>
                                <li>Every custom database table created by either plugin</li>
                                <li>All uploaded files (avatars, attachments)</li>
                                <li>License and activation data, the key must be entered again after reactivating Pro</li>
                                <li>Plugin roles, their users are safely moved to Subscriber</li>
                            </ul>
                            <p class="pc9-purge-warning">
                                ⛔ This cannot be undone. There is no recovery after this action. WooCommerce orders, products, and customers, and WordPress user accounts, are not affected. Reactivating Portal Cloud 9 Pro afterwards rebuilds a completely fresh database.
                            </p>
                            <p class="pc9-purge-confirm-label">Type <strong>DELETE</strong> to confirm:</p>
                            <input type="text" id="pc9-purge-confirm-input" class="pc9-purge-confirm-input" placeholder="Type DELETE to confirm">
                            <div class="pc9-purge-modal-actions">
                                <button type="button" id="pc9-purge-cancel" class="button button-large pc9-btn-cancel">Cancel</button>
                                <button type="button" id="pc9-purge-confirm" class="button button-large pc9-btn-confirm" disabled>
                                    🗑️ Yes, Delete Everything
                                </button>
                            </div>
                            <div id="pc9-purge-status" class="pc9-purge-status"></div>
                        </div>
                    </div>


                    <!-- Submit Button Card -->
                    <div class="pc9-submit-card">
                        <?php submit_button('Save All Settings', 'primary large'); ?>
                    </div>
                </form>
            </div>

            <!-- RIGHT: stacked glass cards -->
            <div class="portalcloud9-settings-right">
                <!-- Plugin Status Card -->
                <div class="portalcloud9-glass-card">
                    <h3>✅ Plugin Status</h3>
                    <p style="margin:0;color:#10b981;font-weight:600;">All features active</p>
                    <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Portal Cloud 9 is fully functional with no restrictions.</p>
                </div>

                <!-- Quick Shortcodes -->
                <div class="portalcloud9-glass-card">
                    <h3>⚡ Quick Shortcodes</h3>
                    <ul class="portalcloud9-list">
                        <li><code>[portalcloud9_product_inquiry]</code> – Product chat box</li>
                        <li><code>[portalcloud9_seller_phone]</code> – Seller phone</li>
                        <li><code>[portalcloud9_favourite_button]</code> – Heart button</li>
                    </ul>
                </div>

                <!-- Quick Actions -->
                <div class="portalcloud9-glass-card">
                    <h3>Quick Actions</h3>
                    <div class="portalcloud9-action-buttons">
                        <a class="portalcloud9-btn" href="<?php echo esc_url(home_url('/user-portal/')); ?>" target="_blank">
                            👁️ View Dashboard
                        </a>
                        <a class="portalcloud9-btn" href="<?php echo esc_url(home_url('/user-portal/products/')); ?>" target="_blank">
                            📦 Manage Products
                        </a>
                        <a class="portalcloud9-btn" href="<?php echo esc_url(home_url('/user-portal/inbox/')); ?>" target="_blank">
                            📨 Check Messages
                        </a>
                        <button class="portalcloud9-btn" onclick="pc9ClearCache()" id="pc9-clear-cache-btn">
                            🗑️ Clear Cache
                        </button>
                    </div>
                </div>
            </div>
        </div>




        <?php
    }


    private function get_option(string $key, $default = '')
    {
        $opts = get_option(self::OPTION_NAME, []);
        return $opts[$key] ?? $default;
    }

    private function checkbox_field(string $key, string $label): void
    {
        $val = $this->get_option($key, 1);
        printf(
            '<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s data-ajax-toggle="1" data-key="%2$s"> %4$s</label>',
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            checked($val, 1, false),
            esc_html($label)
        );
    }

    private function glass_card(array $sc): void
    {
        $is_hero = !empty($sc['hero']);
        ?>
        <div class="portalcloud9-glass-card <?php echo esc_attr( $is_hero ? 'portalcloud9-hero-card' : '' ); ?>">
            <h3><?php echo esc_html($sc['title']); ?></h3>
            <p><?php echo esc_html($sc['desc']); ?></p>

            <?php if (!empty($sc['code'])): ?>
            <div class="portalcloud9-code-block">
                <?php echo esc_html($sc['code']); ?>
                <button class="portalcloud9-copy-btn" 
                        onclick="navigator.clipboard.writeText('<?php echo esc_js($sc['code']); ?>');alert('Copied!')">
                    Copy
                </button>
            </div>
            <?php endif; ?>

            <?php
            if (!empty($sc['extras'])) {
                echo '<details class="portalcloud9-extras"><summary>More examples</summary><ul class="portalcloud9-extra-list">';
                foreach ($sc['extras'] as $label => $code) {
                    printf(
                        '<li>
                            <span class="portalcloud9-extra-label">%1$s</span>
                            <div class="portalcloud9-code-block">
                                %2$s
                                <button class="portalcloud9-copy-btn" onclick="navigator.clipboard.writeText(\'%3$s\');alert(\'Copied!\')">Copy</button>
                            </div>
                        </li>',
                        esc_html($label),
                        esc_html($code),
                        esc_js($code)
                    );
                }
                echo '</ul></details>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * AJAX handler to clear cache and flush rewrite rules
     */
    public function ajax_clear_cache(): void
    {
        // Security check
        check_ajax_referer('portcld9_clear_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Flush rewrite rules (like permalinks do)
        flush_rewrite_rules(false);
        
        // Clear WordPress transients related to Portal Cloud 9
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pc9_%' OR option_name LIKE '_transient_timeout_pc9_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional transient cleanup.
        
        // Clear any Portal Cloud 9 specific caches
        wp_cache_delete('portalcloud9_settings');
        
        // Clear object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        wp_send_json_success('Cache cleared and rewrite rules flushed successfully');
    }

    /**
     * AJAX: instantly toggle a single boolean option without a full form save.
     * Only accepts keys that are registered boolean fields (default case in
     * sanitize_options). Display Settings and Admin Email
     * are intentionally excluded — they are not boolean and must use the
     * full form save.
     */
    public function ajax_toggle_option(): void
    {
        check_ajax_referer('portcld9_toggle_option', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $key   = isset($_POST['key']) ? sanitize_key($_POST['key']) : '';
        $value = !empty($_POST['value']) ? 1 : 0;

        // Whitelist: only boolean toggle fields are accepted here.
        // Numeric, text, and select fields must go through the full form save.
        $boolean_fields = [
            'enable_messaging',
            'enable_product_inquiry',
            'enable_phone_contacts',
            'email_notifications',
            'remove_data_on_uninstall',
        ];

        if (!in_array($key, $boolean_fields, true)) {
            wp_send_json_error('Invalid or non-toggleable option key: ' . esc_html($key));
            return;
        }

        // Merge the single changed key into the existing saved options
        $opts         = get_option(self::OPTION_NAME, []);
        $was_armed    = !empty($opts[$key]) && $key === 'remove_data_on_uninstall';
        $opts[$key]   = $value;
        update_option(self::OPTION_NAME, $opts);

        // Security audit trail — arming the full data wipe is worth a
        // record on its own, separate from the wipe actually running
        // (which can't log to Pro's audit table since that table is
        // itself one of the things the wipe destroys). Only fires on the
        // off-to-on transition, not every time the settings page saves.
        if ($key === 'remove_data_on_uninstall' && $value && !$was_armed && class_exists('PortalCld9_Audit_Log')) {
            PortalCld9_Audit_Log::record(get_current_user_id(), 'data_wipe_armed', 0, 'system', []);
        }

        // Bust the stats transient so counts refresh
        delete_transient('portalcloud9_stats');

        wp_send_json_success([
            'key'   => $key,
            'value' => $value,
        ]);
    }

    /**
     * AJAX: immediately purge all Portal Cloud 9 data.
     * Requires the remove_data_on_uninstall toggle to be ON and
     * a valid nonce. This is intentionally destructive and irreversible.
     */
    public function ajax_purge_data(): void
    {
        check_ajax_referer('portcld9_purge_data', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Double-check toggle is on — never purge silently
        $opts = get_option(self::OPTION_NAME, []);
        if (empty($opts['remove_data_on_uninstall'])) {
            wp_send_json_error('Remove Data on Uninstall toggle is not enabled.');
            return;
        }

        // Pro must be deactivated first so its tables can be dropped
        // cleanly without breaking the running plugin mid request.
        if ( function_exists( 'portcld9_pro_is_active' ) && portcld9_pro_is_active() ) {
            wp_send_json_error( 'Portal Cloud 9 Pro is still active. Deactivate it on the Plugins screen first, then run the wipe.' );
            return;
        }

        require_once PORTCLD9_PLUGIN_DIR . 'includes/class-purge.php';
        PortalCloud9_Purge::run();

        wp_send_json_success('All Portal Cloud 9 data has been permanently deleted. Reactivating Portal Cloud 9 Pro will rebuild a fresh database.');
    }

    /**
     * Render callback for the Upgrade submenu page.
     * In practice this never runs because maybe_redirect_upgrade() fires first on
     * admin_init and sends the visitor directly to the Pro website. This stub is
     * required because add_submenu_page() expects a valid callable.
     */
    public function render_upgrade_page(): void
    {
        // Redirect already fired in maybe_redirect_upgrade().
        // This fallback is a safety net only.
        wp_safe_redirect( 'https://gradyzer.com/wordpress/portal-cloud-9-pro/' );
        exit;
    }

    /**
     * Render Getting Started Page
     */
    public function render_getting_started_page(): void
    {
        $dashboard_url = esc_url( home_url( '/user-portal/' ) );
        $settings_url  = esc_url( admin_url( 'admin.php?page=portalcloud9-settings' ) );
        $users_url     = esc_url( admin_url( 'users.php' ) );
        ?>
        <div class="wrap">
            <h1 style="font-size:24px;font-weight:800;color:#1e293b;margin-bottom:4px;">
                📖 Getting Started with Portal Cloud 9
            </h1>
            <p style="color:#64748b;font-size:14px;margin-bottom:28px;">
                Your WooCommerce customer dashboard is live at
                <strong><a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank"><?php echo esc_url( $dashboard_url ); ?></a></strong>
            </p>

            <div class="pc9-getting-started">

                <!-- ── WELCOME ─────────────────────────────────────── -->
                <div class="pc9-section">
                    <h2>👋 Welcome to Portal Cloud 9!</h2>
                    <p style="font-size:15px;line-height:1.8;color:#475569;">
                        Portal Cloud 9 is a full-featured WooCommerce customer dashboard that automatically adapts to every user role.
                        Customers track orders and earn reward points. Shop Managers run their entire business from the front-end.
                        Administrators manage everything from a single control centre — all without touching WordPress admin.
                        All features are fully unlocked. No license required.
                    </p>
                </div>

                <!-- ── QUICK START ─────────────────────────────────── -->
                <div class="pc9-section">
                    <h2>▶️ Quick Start — 4 Steps</h2>
                    <div class="pc9-feature-grid">
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-admin-settings"></span> Step 1 — Configure Settings</h3>
                            <p>Go to <a href="<?php echo esc_url( $settings_url ); ?>"><strong>Portal Cloud 9 → Settings</strong></a> and review:</p>
                            <ul class="pc9-feature-list">
                                <li>Dashboard pagination limits</li>
                                <li>Feature toggles per role</li>
                                <li>Email notification preferences</li>
                            </ul>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-awards"></span> Step 2 — Set Up Reward Points</h3>
                            <p>Visit your dashboard → <strong>Rewards → Settings</strong> tab and configure:</p>
                            <ul class="pc9-feature-list">
                                <li>Points earned per $1 spent</li>
                                <li>Redemption rate (points → coupon value)</li>
                                <li>Coupon expiry days (default: 7)</li>
                                <li>Seller withdrawal minimum and rate</li>
                            </ul>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-admin-users"></span> Step 3 — Assign User Roles</h3>
                            <p>Go to <a href="<?php echo esc_url( $users_url ); ?>"><strong>WordPress Admin → Users</strong></a>, edit each user, and set their role:</p>
                            <ul class="pc9-feature-list">
                                <li><strong>Administrator</strong> — Full control</li>
                                <li><strong>Shop Manager</strong> — Seller portal</li>
                                <li><strong>Customer</strong> — Buyer portal</li>
                            </ul>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-edit-page"></span> Step 4 — Add Shortcodes</h3>
                            <p>Enhance product pages by adding shortcodes via Elementor, Gutenberg, or functions.php:</p>
                            <ul class="pc9-feature-list">
                                <li><code>[portalcloud9_favourite_button]</code></li>
                                <li><code>[portalcloud9_seller_phone]</code></li>
                                <li><code>[portalcloud9_product_inquiry]</code></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- ── USER ROLES ──────────────────────────────────── -->
                <div class="pc9-section">
                    <h2>👥 User Roles — Who Sees What</h2>
                    <p style="font-size:14px;color:#64748b;margin-bottom:20px;">
                        Portal Cloud 9 automatically detects the logged-in user's WordPress role and renders the correct dashboard.
                        No extra configuration needed. To assign a role: <strong>WordPress Admin → Users → Edit User → Role → Save</strong>.
                    </p>

                    <!-- Administrator -->
                    <div class="pc9-feature-card" style="border-left:4px solid #f59e0b;margin-bottom:16px;">
                        <h3 style="color:#b45309;">🛡️ Administrator</h3>
                        <p style="color:#64748b;font-size:13px;margin-bottom:10px;">
                            Full unrestricted access to every section and every user's data across the entire store.
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <ul class="pc9-feature-list">
                                <li>📊 Overview — store-wide stats</li>
                                <li>📦 Orders — all orders, all sellers</li>
                                <li>🏪 Products — all products</li>
                                <li>📈 Visitor Analytics — full store</li>
                                <li>📞 Phone Contacts — all sellers</li>
                            </ul>
                            <ul class="pc9-feature-list">
                                <li>💬 Inbox — all conversations</li>
                                <li>🎁 Rewards — full admin console:<br>
                                    &nbsp;&nbsp;• Manage all user balances<br>
                                    &nbsp;&nbsp;• Bulk point adjustments<br>
                                    &nbsp;&nbsp;• Approve / reject withdrawals<br>
                                    &nbsp;&nbsp;• Configure rates and expiry<br>
                                    &nbsp;&nbsp;• CSV import & expiry trigger</li>
                                <li>👤 Account — profile management</li>
                            </ul>
                        </div>
                        <div class="pc9-info-box" style="margin-top:14px;">
                            <strong>How to assign:</strong> WordPress Admin → Users → Edit User → Role → <em>Administrator</em> → Save
                        </div>
                    </div>

                    <!-- Shop Manager -->
                    <div class="pc9-feature-card" style="border-left:4px solid #8b5cf6;margin-bottom:16px;">
                        <h3 style="color:#6d28d9;">🏪 Shop Manager</h3>
                        <p style="color:#64748b;font-size:13px;margin-bottom:10px;">
                            Scoped seller portal — sees only their own products, orders, and customers.
                            Earns reward points from sales and can request cash withdrawals.
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <ul class="pc9-feature-list">
                                <li>📊 Overview — own sales stats</li>
                                <li>📦 Orders — own orders only</li>
                                <li>🏪 Products — own products only</li>
                                <li>➕ Add New Product</li>
                            </ul>
                            <ul class="pc9-feature-list">
                                <li>📞 Contacted by Phone — own products</li>
                                <li>💬 Inbox — own conversations</li>
                                <li>🎁 Rewards — seller view:<br>
                                    &nbsp;&nbsp;• Points balance & cash value<br>
                                    &nbsp;&nbsp;• Request cash withdrawal<br>
                                    &nbsp;&nbsp;• Top products by sales<br>
                                    &nbsp;&nbsp;• Customer points activity</li>
                                <li>👤 Account</li>
                            </ul>
                        </div>
                        <div class="pc9-info-box" style="margin-top:14px;">
                            <strong>How to assign:</strong> WordPress Admin → Users → Edit User → Role → <em>Shop Manager</em> → Save<br>
                            <strong>Note:</strong> Product saves go to Draft status and notify Administrators for review.
                        </div>
                    </div>

                    <!-- Customer -->
                    <div class="pc9-feature-card" style="border-left:4px solid #0ea5e9;margin-bottom:16px;">
                        <h3 style="color:#0369a1;">🛒 Customer</h3>
                        <p style="color:#64748b;font-size:13px;margin-bottom:10px;">
                            Buyer-focused portal for order tracking, wishlist management, messaging, and reward points redemption.
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <ul class="pc9-feature-list">
                                <li>📊 Overview — purchase activity</li>
                                <li>📦 Orders — own orders only</li>
                                <li>🛒 Cart — AJAX cart management</li>
                                <li>❤️ Favourites — saved products</li>
                            </ul>
                            <ul class="pc9-feature-list">
                                <li>💬 Inbox — own conversations</li>
                                <li>🎁 Rewards — customer view:<br>
                                    &nbsp;&nbsp;• Points balance + progress bar<br>
                                    &nbsp;&nbsp;• Generate coupon codes<br>
                                    &nbsp;&nbsp;• Transaction history</li>
                                <li>👤 Account — profile &amp; settings</li>
                            </ul>
                        </div>
                        <div class="pc9-info-box" style="margin-top:14px;">
                            <strong>How to assign:</strong> WordPress Admin → Users → Edit User → Role → <em>Customer</em> → Save<br>
                            <strong>Note:</strong> Subscriber role also gets the customer dashboard view.
                        </div>
                    </div>
                </div>

                <!-- ── REWARD POINTS GUIDE ─────────────────────────── -->
                <div class="pc9-section">
                    <h2>🎁 Reward Points — Full Setup Guide</h2>
                    <p style="font-size:14px;color:#64748b;margin-bottom:20px;">
                        The Reward Points system is built into the dashboard and works automatically once configured.
                        Access it at <strong><?php echo esc_url( $dashboard_url ); ?>rewards/</strong> — the view adapts to the logged-in user's role.
                    </p>

                    <div class="pc9-feature-grid">
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-awards"></span> Customer Rewards</h3>
                            <ul class="pc9-feature-list">
                                <li>Points awarded automatically on <strong>Completed</strong> orders</li>
                                <li>Configurable earn rate (e.g. 10 pts per $1)</li>
                                <li>Live progress bar toward redemption threshold</li>
                                <li>Convert points to WooCommerce coupon codes</li>
                                <li>Coupon locked to customer email, single use</li>
                                <li>Configurable coupon expiry (default 7 days)</li>
                                <li>Full transaction history with type icons</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-store"></span> Seller Rewards</h3>
                            <ul class="pc9-feature-list">
                                <li>Points earned per item sold (configurable)</li>
                                <li>Cash value display (pts ÷ withdrawal rate)</li>
                                <li>Request withdrawal — Bank, M-Pesa, PayPal, Other</li>
                                <li>Points held pending admin approval</li>
                                <li>Points refunded if request is rejected</li>
                                <li>Email notification on approval or rejection</li>
                                <li>Customer activity and top products view</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-admin-network"></span> Admin Console</h3>
                            <ul class="pc9-feature-list">
                                <li>Search users by name or email</li>
                                <li>Filter by Shop Manager or Customer role</li>
                                <li>Add, Deduct, or Set balance per user</li>
                                <li>Bulk adjust multiple users at once</li>
                                <li>Import adjustments via CSV upload</li>
                                <li>Approve or reject withdrawal requests</li>
                                <li>Lifetime stats: Issued / Redeemed / Expired</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-admin-tools"></span> Settings & Expiry</h3>
                            <ul class="pc9-feature-list">
                                <li>Points per $1 spent (customer earn rate)</li>
                                <li>Points per $1 coupon (redemption rate)</li>
                                <li>Minimum points to redeem</li>
                                <li>Maximum discount % per order</li>
                                <li>Coupon expiry days (default: 7)</li>
                                <li>Seller points per item sold</li>
                                <li>Points per $1 payout (withdrawal rate)</li>
                                <li>Points expiry in days (0 = never expire)</li>
                                <li>Manual expiry trigger with last-run display</li>
                            </ul>
                        </div>
                    </div>

                    <div class="pc9-info-box" style="margin-top:16px;">
                        <h4>📋 CSV Import Format</h4>
                        <p style="margin:8px 0 4px;">Upload a CSV file from <strong>Rewards → Settings → Bulk Import</strong>. Format:</p>
                        <code style="display:block;background:#f1f5f9;padding:10px;border-radius:8px;font-size:13px;margin-top:8px;">
                            email_or_username, points, action, note<br>
                            customer@example.com, 500, add, Welcome bonus<br>
                            janedoe, 200, deduct, Coupon abuse<br>
                            seller@example.com, 1000, set, Migration from old system
                        </code>
                        <p style="margin-top:8px;color:#64748b;font-size:12px;">
                            Actions: <strong>add</strong> (increases balance), <strong>deduct</strong> (reduces balance), <strong>set</strong> (sets exact balance).
                            First row is the header and is always skipped.
                        </p>
                    </div>
                </div>

                <!-- ── ALL FEATURES ─────────────────────────────────── -->
                <div class="pc9-section">
                    <h2>🎁 All Dashboard Features</h2>
                    <div class="pc9-feature-grid">

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-dashboard"></span> Overview</h3>
                            <ul class="pc9-feature-list">
                                <li>Role-specific metrics and stats</li>
                                <li>7-day revenue chart for sellers</li>
                                <li>Recent orders and activity</li>
                                <li>Quick navigation to all sections</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-cart"></span> Orders</h3>
                            <ul class="pc9-feature-list">
                                <li>Filter by status and date range</li>
                                <li>Search by customer or product</li>
                                <li>Detailed order modals</li>
                                <li>Bulk status updates and export</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-products"></span> Products</h3>
                            <ul class="pc9-feature-list">
                                <li>Add and edit without WP admin</li>
                                <li>Auto WebP conversion at 700×700px</li>
                                <li>Grid and list view toggle</li>
                                <li>Inventory, pricing, and variants</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-email"></span> Messaging</h3>
                            <ul class="pc9-feature-list">
                                <li>Product-referenced conversations</li>
                                <li>Real-time notifications</li>
                                <li>Read/unread status tracking</li>
                                <li>Full-text search and archive</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-chart-bar"></span> Visitor Analytics</h3>
                            <ul class="pc9-feature-list">
                                <li>Page views and unique visitors</li>
                                <li>Traffic source and referrer breakdown</li>
                                <li>Date range filter</li>
                                <li>All data stored locally — no external services</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-phone"></span> Phone Contacts</h3>
                            <ul class="pc9-feature-list">
                                <li>Track every phone click by product</li>
                                <li>Daily, weekly, monthly summary cards</li>
                                <li>Leaderboards for top products and sellers</li>
                                <li>Message inquirers directly from the view</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-heart"></span> Favourites</h3>
                            <ul class="pc9-feature-list">
                                <li>Save products to wishlist</li>
                                <li>Works for guests — merged on login</li>
                                <li>Quick add-to-cart from favourites</li>
                                <li>Bulk remove operations</li>
                            </ul>
                        </div>

                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-admin-users"></span> Account</h3>
                            <ul class="pc9-feature-list">
                                <li>Profile with avatar upload</li>
                                <li>Password management</li>
                                <li>WooCommerce address integration</li>
                                <li>Notification and privacy preferences</li>
                            </ul>
                        </div>

                    </div>
                </div>

                <!-- ── SHORTCODES ───────────────────────────────────── -->
                <div class="pc9-section">
                    <h2>🎨 Shortcodes</h2>
                    <div class="pc9-feature-grid">
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-email-alt"></span> Product Inquiry Button</h3>
                            <code>[portalcloud9_product_inquiry]</code>
                            <p style="color:#64748b;font-size:13px;margin-top:8px;">Opens a messaging form so customers can contact the seller. Place on Single Product pages.</p>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-phone"></span> Seller Phone Button</h3>
                            <code>[portalcloud9_seller_phone]</code>
                            <p style="color:#64748b;font-size:13px;margin-top:8px;">Shows seller phone with click-to-call and automatic tracking. Place on Single Product pages.</p>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-heart"></span> Favourite Button</h3>
                            <code>[portalcloud9_favourite_button]</code>
                            <p style="color:#64748b;font-size:13px;margin-top:8px;">Heart icon for saving products. Works for guests. Place on product loop or single product pages.</p>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-heart"></span> Favourites Counter</h3>
                            <code>[portalcloud9_favourites_count]</code>
                            <p style="color:#64748b;font-size:13px;margin-top:8px;">Live count with link to favourites page. Place in navigation or header widget area.</p>
                        </div>
                    </div>
                </div>

                <!-- ── LICENSE ─────────────────────────────────────── -->
                <div class="pc9-section">
                    <h2>📄 License</h2>
                    <div class="pc9-info-box">
                        <p style="font-size:14px;line-height:1.8;color:#475569;margin:0;">
                            <strong>Portal Cloud 9 — Customer Dashboard &amp; Rewards for WooCommerce</strong><br>
                            Copyright &copy; 2025&ndash;2026 <strong>Gradyzer</strong> (Brian Agoi &mdash; brian@gradyzer.com)<br><br>
                            This plugin is free software released under the <strong>GNU General Public License version 2 or later (GPLv2+)</strong>.
                            You are free to use, modify, and distribute it under the same license.<br><br>
                            <strong>License URI:</strong> <a href="https://www.gnu.org/licenses/gpl-2.0.html" target="_blank" rel="noopener">https://www.gnu.org/licenses/gpl-2.0.html</a><br><br>
                            <strong>Bundled assets:</strong> Font Awesome Free 6 is included under the Font Awesome Free License
                            (Icons: CC BY 4.0 &bull; Fonts: SIL OFL 1.1 &bull; Code: MIT).
                            Full license text in <code>assets/font-awesome/LICENSE.txt</code>.<br><br>
                            This plugin is provided without warranty. The author is not liable for any damages arising from its use.
                            All features are free and no premium tier or external service is required to use any part of this plugin.
                        </p>
                    </div>
                </div>

                <!-- ── SUPPORT ─────────────────────────────────────── -->
                <div class="pc9-section">
                    <h2>💬 Support &amp; Resources</h2>
                    <div class="pc9-feature-grid">
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-book"></span> Documentation</h3>
                            <p>Full guides, shortcode reference, and configuration walkthroughs.</p>
                            <p><a href="https://gradyzer.com/docs" target="_blank" rel="noopener" class="button button-primary">View Documentation</a></p>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-sos"></span> Support</h3>
                            <p>Open a ticket or email us directly at support@gradyzer.com</p>
                            <p><a href="https://gradyzer.com/support" target="_blank" rel="noopener" class="button button-primary">Get Support</a></p>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-star-filled"></span> Rate the Plugin</h3>
                            <p>Enjoying Portal Cloud 9? A 5-star review on WordPress.org helps more store owners find it.</p>
                            <p><a href="https://wordpress.org/support/plugin/portal-cloud-9/reviews/#new-post" target="_blank" rel="noopener" class="button">Leave a Review ⭐</a></p>
                        </div>
                        <div class="pc9-feature-card">
                            <h3><span class="dashicons dashicons-admin-home"></span> Website</h3>
                            <p>Plugin page, changelog, and Pro add-on information.</p>
                            <p><a href="https://gradyzer.com/portal-cloud-9/" target="_blank" rel="noopener" class="button">Visit Gradyzer.com</a></p>
                        </div>
                    </div>
                </div>

            </div><!-- .pc9-getting-started -->
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Render the Shortcodes admin page.
     */
    public function render_shortcodes_page(): void
    {
        ?>
        <div class="wrap">

            <div class="portalcloud9-shortcodes-grid">

                <!-- Product Inquiry Button -->
                <div class="portalcloud9-glass-card">
                    <h3>💬 Product Inquiry Button</h3>
                    <p>A messaging button that opens an inquiry form for the product.</p>
                    <p><strong>Basic:</strong><br><code>[portalcloud9_inquiry_button]</code></p>
                    <p><strong>With product ID:</strong><br><code>[portalcloud9_inquiry_button product_id="123"]</code></p>
                    <p><strong>Custom text:</strong><br><code>[portalcloud9_inquiry_button text="Ask a Question"]</code></p>
                    <p><strong>All options:</strong><br><code>[portalcloud9_inquiry_button product_id="123" text="Contact Seller" class="custom-class"]</code></p>
                    <p style="color:#666;font-size:13px;margin-top:12px;">
                        <strong>📍 Where to add:</strong> Single Product Page<br>
                        <strong>Best position:</strong> Below the Add to Cart button
                    </p>
                </div>

                <!-- Seller Phone Button -->
                <div class="portalcloud9-glass-card">
                    <h3>📞 Seller Phone Button</h3>
                    <p>Displays the seller's phone number with click-to-call support.</p>
                    <p><strong>Basic:</strong><br><code>[portalcloud9_seller_phone]</code></p>
                    <p><strong>With product ID:</strong><br><code>[portalcloud9_seller_phone product_id="123"]</code></p>
                    <p><strong>Show label only:</strong><br><code>[portalcloud9_seller_phone show_label="true"]</code></p>
                    <p><strong>Custom styling:</strong><br><code>[portalcloud9_seller_phone style="button" class="phone-btn"]</code></p>
                    <p style="color:#666;font-size:13px;margin-top:12px;">
                        <strong>📍 Where to add:</strong> Single Product Page<br>
                        <strong>Features:</strong> Mobile click-to-call, phone contact tracking
                    </p>
                </div>

                <!-- Favourite Button -->
                <div class="portalcloud9-glass-card">
                    <h3>❤️ Favourite Button</h3>
                    <p>Heart button that lets customers save products to their favourites list.</p>
                    <p><strong>Basic:</strong><br><code>[portalcloud9_favourite_button]</code></p>
                    <p><strong>With product ID:</strong><br><code>[portalcloud9_favourite_button product_id="123"]</code></p>
                    <p><strong>Minimal style:</strong><br><code>[portalcloud9_favourite_button style="minimal"]</code></p>
                    <p><strong>Show count:</strong><br><code>[portalcloud9_favourite_button show_count="true"]</code></p>
                    <p><strong>All options:</strong><br><code>[portalcloud9_favourite_button product_id="123" style="glass" show_count="true" class="my-fav-btn"]</code></p>
                    <p style="color:#666;font-size:13px;margin-top:12px;">
                        <strong>📍 Where to add:</strong> Product Loop or Single Product Page<br>
                        <strong>Works for:</strong> Logged-in and logged-out users
                    </p>
                </div>

                <!-- Favourites Counter -->
                <div class="portalcloud9-glass-card">
                    <h3>🔢 Favourites Counter</h3>
                    <p>Displays the current user's favourites count with a link to their favourites page.</p>
                    <p><strong>Basic:</strong><br><code>[portalcloud9_favourites_count]</code></p>
                    <p><strong>Badge format:</strong><br><code>[portalcloud9_favourites_count format="badge"]</code></p>
                    <p><strong>Link format:</strong><br><code>[portalcloud9_favourites_count format="link"]</code></p>
                    <p style="color:#666;font-size:13px;margin-top:12px;">
                        <strong>📍 Where to add:</strong> Navigation Menu or header area<br>
                        <strong>Updates:</strong> Automatically refreshes when favourites change
                    </p>
                </div>

            </div><!-- .portalcloud9-shortcodes-grid -->

            <div class="portalcloud9-shortcodes-cta" style="margin-top:40px;padding:24px;background:rgba(30,144,255,0.06);border:1px solid rgba(30,144,255,0.2);border-radius:16px;">
                <h3 style="color:#94a3b8;">💡 How to Add Shortcodes</h3>
                <ul style="line-height:1.9;margin:10px 0 0 20px;">
                    <li><strong>Gutenberg:</strong> Add a Shortcode block and paste the shortcode</li>
                    <li><strong>Page Builders:</strong> Use Elementor Shortcode widget or WPBakery Text Block</li>
                    <li><strong>Theme functions.php:</strong> Use WooCommerce action hooks to inject shortcodes programmatically</li>
                    <li><strong>Widgets:</strong> Use a Text/HTML widget in any sidebar or widget area</li>
                    <li><strong>Auto-detection:</strong> Product shortcodes detect the product ID automatically on product pages</li>
                </ul>
            </div>

        </div><!-- .wrap -->
        <?php
    }
}

new PortalCloud9_Settings();