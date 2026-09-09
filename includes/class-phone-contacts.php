<?php
/**
 * Portal Cloud 9 - Phone Contacts Manager
 * Tracks when users click phone numbers on products
 */

defined('ABSPATH') || exit;

class PortalCld9_Phone_Contacts // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Legacy class name maintained for compatibility.
{
    private static $instance = null;
    private $table_name;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'portcld9_phone_contacts';
        
        // Create table on init
        add_action('init', [$this, 'maybe_create_table']);
        
        // AJAX handlers
        add_action('wp_ajax_portcld9_track_phone_click', [$this, 'ajax_track_phone_click']);
        add_action('wp_ajax_portcld9_get_phone_contacts', [$this, 'ajax_get_phone_contacts']);
        add_action('wp_ajax_portcld9_get_contact_stats', [$this, 'ajax_get_contact_stats']);
        add_action('wp_ajax_portcld9_send_contact_message', [$this, 'ajax_send_contact_message']);
        add_action('wp_ajax_portcld9_get_contact_conversation', [$this, 'ajax_get_contact_conversation']);
        
        // Add phone display on single product page
        add_action('woocommerce_single_product_summary', [$this, 'display_seller_phone'], 25);
    }

    /**
     * Create database table
     */
    public function maybe_create_table()
    {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
    $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} ( 
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            seller_id bigint(20) NOT NULL,
            phone_number varchar(50) NOT NULL,
            clicked_at datetime NOT NULL,
            user_ip varchar(100) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY product_id (product_id),
            KEY seller_id (seller_id),
            KEY clicked_at (clicked_at)
        ) $charset_collate;";
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    /**
     * Display seller phone on single product page
     */
    public function display_seller_phone()
    {
        if (!is_product()) {
            return;
        }
        
        global $product;
        $product_id = $product->get_id();
        
        // Use the same meta key as the shortcode system
        $seller_phone = get_post_meta($product_id, '_portalcloud9_seller_phone', true);
        
        if (empty($seller_phone)) {
            return;
        }
        
        // Only show to logged-in users
        if (!is_user_logged_in()) {
            echo '<div class="pc9-seller-phone-login">
                    <p><i class="fas fa-phone"></i> <a href="' . esc_url(wp_login_url(get_permalink())) . '">Login to view seller phone</a></p>
                  </div>';
            return;
        }
        
        ?>
        <div class="pc9-seller-phone-wrapper">
            <div class="pc9-seller-phone-box">
                <span class="pc9-phone-icon"><i class="fas fa-phone-alt"></i></span>
                <div class="pc9-phone-details">
                    <span class="pc9-phone-label">Contact Seller</span>
                    <a href="tel:<?php echo esc_attr($seller_phone); ?>" 
                       class="pc9-phone-number" 
                       data-product-id="<?php echo esc_attr($product_id); ?>"
                       data-phone="<?php echo esc_attr($seller_phone); ?>">
                        <?php echo esc_html($seller_phone); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Track phone click via AJAX
     */
    public function ajax_track_phone_click()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to contact sellers.');
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        $phone_number = sanitize_text_field( wp_unslash( $_POST['phone_number'] ?? '') );
        
        if (!$product_id || !$phone_number) {
            wp_send_json_error('Invalid data.');
        }
        
        // Get product and seller info
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found.');
        }
        
        $seller_id = get_post_field('post_author', $product_id);
        $user_id = get_current_user_id();
        
        // Don't track if user is clicking their own product
        if ($user_id === intval($seller_id)) {
            wp_send_json_success([
                'message' => 'Own product - not tracked',
                'tracked' => false
            ]);
            return;
        }
        
        // Insert contact record
        global $wpdb;
        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            $this->table_name,
            [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'seller_id' => $seller_id,
                'phone_number' => $phone_number,
                'clicked_at' => current_time('mysql'),
                'user_ip' => $this->get_user_ip(),
                'user_agent' => substr(sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255)
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );
        
        if ($inserted) {
            // Send notifications
            $this->send_notifications($user_id, $seller_id, $product_id, $phone_number);
            
            wp_send_json_success([
                'message' => 'Contact tracked successfully',
                'tracked' => true,
                'contact_id' => $wpdb->insert_id // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            ]);
        } else {
            wp_send_json_error('Failed to track contact.');
        }
    }

    /**
     * Get phone contacts list (Admin only)
     */
    public function ajax_get_phone_contacts()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');

        $is_admin   = current_user_can( 'manage_options' );
        $is_manager = ! $is_admin && current_user_can( 'manage_woocommerce' );

        if ( ! $is_admin && ! $is_manager ) {
            wp_send_json_error('Unauthorized.');
        }

        $page           = intval( wp_unslash( $_POST['page'] ?? 1 ) );
        $per_page       = 20;
        $search         = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
        $filter_seller  = intval( wp_unslash( $_POST['filter_seller'] ?? 0 ) );
        $filter_product = intval( wp_unslash( $_POST['filter_product'] ?? 0 ) );
        $date_from      = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
        $date_to        = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );

        global $wpdb;

        $where        = [ '1=1' ];
        $where_values = [];

        // Shop managers can only see contacts for their own products
        if ( $is_manager && ! $is_admin ) {
            $where[]        = 'pc.seller_id = %d';
            $where_values[] = get_current_user_id();
        } elseif ( $filter_seller ) {
            $where[]        = 'pc.seller_id = %d';
            $where_values[] = $filter_seller;
        }

        if ( $search ) {
            $where[]      = '(u.display_name LIKE %s OR p.post_title LIKE %s OR pc.phone_number LIKE %s)';
            $st           = '%' . $wpdb->esc_like( $search ) . '%';
            $where_values = array_merge( $where_values, [ $st, $st, $st ] );
        }

        if ( $filter_product ) {
            $where[]        = 'pc.product_id = %d';
            $where_values[] = $filter_product;
        }

        if ( $date_from ) {
            $where[]        = 'DATE(pc.clicked_at) >= %s';
            $where_values[] = $date_from;
        }

        if ( $date_to ) {
            $where[]        = 'DATE(pc.clicked_at) <= %s';
            $where_values[] = $date_to;
        }

        $where_clause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is $wpdb->prefix + hardcoded suffix, safe.
        $safe_tbl = esc_sql( $this->table_name );
        $count_sql = "SELECT COUNT(*) FROM {$safe_tbl} pc
                       LEFT JOIN {$wpdb->users} u ON pc.user_id = u.ID
                       LEFT JOIN {$wpdb->posts} p ON pc.product_id = p.ID
                       WHERE {$where_clause}";

        if ( ! empty( $where_values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $count_sql built from esc_sql(table) + hardcoded WHERE clause strings.
            $total = $wpdb->get_var( $wpdb->prepare( $count_sql, $where_values ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $count_sql built from esc_sql(table) + hardcoded WHERE clause strings.
            $total = $wpdb->get_var( $count_sql );
        }

        $offset = ( $page - 1 ) * $per_page;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $safe_tbl uses esc_sql() above.
        $query  = "SELECT pc.*,
                         u.display_name as user_name,
                         u.user_email as user_email,
                         s.display_name as seller_name,
                         s.user_email as seller_email,
                         p.post_title as product_name,
                         p.guid as product_url
                  FROM {$safe_tbl} pc
                  LEFT JOIN {$wpdb->users} u ON pc.user_id = u.ID
                  LEFT JOIN {$wpdb->users} s ON pc.seller_id = s.ID
                  LEFT JOIN {$wpdb->posts} p ON pc.product_id = p.ID
                  WHERE {$where_clause}
                  ORDER BY pc.clicked_at DESC
                  LIMIT %d OFFSET %d";

        $query_values = array_merge( $where_values, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query built from esc_sql(table) + hardcoded WHERE clause strings.
        $contacts = $wpdb->get_results( $wpdb->prepare( $query, $query_values ) );

        foreach ( $contacts as &$contact ) {
            $contact->formatted_date  = gmdate( 'M j, Y g:i A', strtotime( $contact->clicked_at ) );
            $contact->product_edit_url = get_edit_post_link( $contact->product_id );
            $contact->product_view_url = get_permalink( $contact->product_id );
        }

        wp_send_json_success( [
            'contacts'    => $contacts,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page ),
            'is_manager'  => $is_manager && ! $is_admin,
        ] );
    }

    /**
     * Get statistics (Admin or scoped Shop Manager)
     */
    public function ajax_get_contact_stats()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');

        $is_admin   = current_user_can( 'manage_options' );
        $is_manager = ! $is_admin && current_user_can( 'manage_woocommerce' );

        if ( ! $is_admin && ! $is_manager ) {
            wp_send_json_error('Unauthorized.');
        }

        global $wpdb;
        $tbl = esc_sql( $this->table_name ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- esc_sql applied; table name is $wpdb->prefix + hardcoded string.

        // Scope clause for shop managers
        $scope_sql  = '';
        $scope_args = [];
        if ( $is_manager && ! $is_admin ) {
            $scope_sql  = 'WHERE seller_id = %d';
            $scope_args = [ get_current_user_id() ];
        }

        // Total clicks
        if ( $scope_args ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tbl esc_sql'd; $scope_sql is hardcoded WHERE clause.
            $total_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} {$scope_sql}", $scope_args ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_clicks = $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
        }

        // Today
        $today = current_time( 'Y-m-d' );
        if ( $scope_args ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $today_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE seller_id = %d AND DATE(clicked_at) = %s", get_current_user_id(), $today ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $today_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `" . esc_sql( $tbl ) . "` WHERE DATE(clicked_at) = %s", $today ) );
        }

        // This week
        $week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
        if ( $scope_args ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $week_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE seller_id = %d AND clicked_at >= %s", get_current_user_id(), $week_start ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $week_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `" . esc_sql( $tbl ) . "` WHERE clicked_at >= %s", $week_start ) );
        }

        // This month
        $month_start = gmdate( 'Y-m-01' );
        if ( $scope_args ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $month_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE seller_id = %d AND clicked_at >= %s", get_current_user_id(), $month_start ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $month_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `" . esc_sql( $tbl ) . "` WHERE clicked_at >= %s", $month_start ) );
        }

        // Top products (scoped)
        $product_scope = ( $scope_args ) ? 'WHERE pc.seller_id = ' . absint( get_current_user_id() ) : ''; // Value from absint() — safe.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tbl uses esc_sql(); $product_scope is hardcoded absint() string.
        $top_products = $wpdb->get_results(
            "SELECT product_id, COUNT(*) as click_count, p.post_title as product_name
             FROM {$tbl} pc
             LEFT JOIN {$wpdb->posts} p ON pc.product_id = p.ID
             {$product_scope}
             GROUP BY product_id ORDER BY click_count DESC LIMIT 10"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Top sellers (admin only)
        $top_sellers = [];
        if ( $is_admin ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tbl uses esc_sql().
            $top_sellers = $wpdb->get_results(
                "SELECT seller_id, COUNT(*) as click_count, u.display_name as seller_name
                 FROM {$tbl} pc
                 LEFT JOIN {$wpdb->users} u ON pc.seller_id = u.ID
                 GROUP BY seller_id ORDER BY click_count DESC LIMIT 10"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        // Unique users
        if ( $scope_args ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $unique_users = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$tbl} WHERE seller_id = %d", get_current_user_id() ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $unique_users = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$tbl}" );
        }

        // Unique products
        if ( $scope_args ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $unique_products = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT product_id) FROM {$tbl} WHERE seller_id = %d", get_current_user_id() ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $unique_products = $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$tbl}" );
        }

        // 7-day trend
        $trend_scope = ( $scope_args ) ? 'AND seller_id = ' . absint( get_current_user_id() ) : ''; // Value from absint() — safe.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tbl uses esc_sql(); $trend_scope is absint() string.
        $trend_data = $wpdb->get_results(
            "SELECT DATE(clicked_at) as date, COUNT(*) as count
             FROM {$tbl}
             WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) {$trend_scope}
             GROUP BY DATE(clicked_at) ORDER BY date ASC"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        wp_send_json_success( [
            'total_clicks'    => intval( $total_clicks ),
            'today_clicks'    => intval( $today_clicks ),
            'week_clicks'     => intval( $week_clicks ),
            'month_clicks'    => intval( $month_clicks ),
            'top_products'    => $top_products,
            'top_sellers'     => $top_sellers,
            'unique_users'    => intval( $unique_users ),
            'unique_products' => intval( $unique_products ),
            'trend_data'      => $trend_data,
        ] );
    }

    /**
     * Send message about a contact (Admin or Shop Manager)
     */
    public function ajax_send_contact_message()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');

        $is_admin   = current_user_can( 'manage_options' );
        $is_manager = ! $is_admin && current_user_can( 'manage_woocommerce' );

        if ( ! $is_admin && ! $is_manager ) {
            wp_send_json_error('Unauthorized.');
        }

        $seller_id  = intval( wp_unslash( $_POST['seller_id'] ?? 0 ) );
        $product_id = intval( wp_unslash( $_POST['product_id'] ?? 0 ) );
        $message    = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

        if ( ! $seller_id || ! $product_id || ! $message ) {
            wp_send_json_error('Invalid data. Missing seller_id, product_id, or message.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'portcld9_messages';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $table_exists ) {
            wp_send_json_error('Messaging table does not exist. Please make sure messaging is enabled.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert(
            $table,
            [
                'from_user_id' => get_current_user_id(),
                'to_user_id'   => $seller_id,
                'product_id'   => $product_id,
                'message'      => $message,
                'is_read'      => 0,
                'created_at'   => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%s', '%d', '%s' ]
        );

        if ( $inserted ) {
            wp_send_json_success('Message sent successfully.');
        }

        wp_send_json_error('Failed to send message. Error: ' . $wpdb->last_error);
    }

    /**
     * Get conversation for a specific contact (Admin or Shop Manager)
     */
    public function ajax_get_contact_conversation()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');

        $is_admin   = current_user_can( 'manage_options' );
        $is_manager = ! $is_admin && current_user_can( 'manage_woocommerce' );

        if ( ! $is_admin && ! $is_manager ) {
            wp_send_json_error('Unauthorized.');
        }

        $seller_id  = intval( wp_unslash( $_POST['seller_id'] ?? 0 ) );
        $product_id = intval( wp_unslash( $_POST['product_id'] ?? 0 ) );

        if ( ! $seller_id || ! $product_id ) {
            wp_send_json_error('Invalid data.');
        }

        if ( class_exists('PortalCloud9_Messaging_Integration') ) {
            global $wpdb;
            $table      = $wpdb->prefix . 'portcld9_messages';
            $current_id = get_current_user_id();
            
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is $wpdb->prefix + hardcoded string.
            $messages = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT m.*, 
                        sender.display_name as sender_name,
                        receiver.display_name as receiver_name
                 FROM {$table} m
                 LEFT JOIN {$wpdb->users} sender ON m.from_user_id = sender.ID
                 LEFT JOIN {$wpdb->users} receiver ON m.to_user_id = receiver.ID
                 WHERE m.product_id = %d
                 AND ((m.from_user_id = %d AND m.to_user_id = %d)
                      OR (m.from_user_id = %d AND m.to_user_id = %d))
                 ORDER BY m.created_at ASC",
                $product_id,
                $current_id,
                $seller_id,
                $seller_id,
                $current_id
            ));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            
            // Format dates
            foreach ($messages as &$msg) {
                $msg->formatted_date = gmdate('M j, Y g:i A', strtotime($msg->created_at));
            }
            
            wp_send_json_success([
                'messages' => $messages,
                'product_name' => get_the_title($product_id),
                'seller_name' => get_userdata($seller_id)->display_name
            ]);
        }
        
        wp_send_json_error('Messaging system not available.');
    }

    /**
     * Send notifications to admin and seller
     */
    private function send_notifications($user_id, $seller_id, $product_id, $phone_number)
    {
        $user = get_userdata($user_id);
        $seller = get_userdata($seller_id);
        $product = get_the_title($product_id);
        
        // Notify seller via messaging system
        if (class_exists('PortalCloud9_Messaging_Integration')) {
            global $wpdb;
            $table = $wpdb->prefix . 'portcld9_messages';
            
            $seller_message = sprintf(
                "📞 A customer clicked your phone number!\n\nCustomer: %s\nProduct: %s\nPhone: %s\nTime: %s\n\nThey may contact you soon!",
                $user->display_name,
                $product,
                $phone_number,
                current_time('F j, Y g:i a')
            );
            
            // Send system message to seller
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
                $table,
                [
                    'from_user_id' => 0, // System message
                    'to_user_id' => $seller_id,
                    'product_id' => $product_id,
                    'message' => $seller_message,
                    'is_read' => 0,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%d', '%d', '%s', '%d', '%s']
            );
            
            // Send notification to admin
            $admin_users = get_users(['role' => 'administrator']);
            foreach ($admin_users as $admin) {
                $admin_message = sprintf(
                    "📞 Phone Contact Alert\n\nCustomer: %s (%s)\nSeller: %s (%s)\nProduct: %s\nPhone: %s\nTime: %s",
                    $user->display_name,
                    $user->user_email,
                    $seller->display_name,
                    $seller->user_email,
                    $product,
                    $phone_number,
                    current_time('F j, Y g:i a')
                );
                
                $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
                    $table,
                    [
                        'from_user_id' => 0,
                        'to_user_id' => $admin->ID,
                        'product_id' => $product_id,
                        'message' => $admin_message,
                        'is_read' => 0,
                        'created_at' => current_time('mysql')
                    ],
                    ['%d', '%d', '%d', '%s', '%d', '%s']
                );
            }
        }
        
        // Email notification to admin
        $admin_email = get_option('admin_email');
        if ($admin_email && PortalCloud9_Config::get_option('email_notifications', true)) {
            $subject = '[Phone Contact] New Phone Click - ' . $product;
            $body = sprintf(
                "User: %s (%s)\nSeller: %s (%s)\nProduct: %s\nPhone: %s\nTime: %s\n\nView in dashboard: %s",
                $user->display_name,
                $user->user_email,
                $seller->display_name,
                $seller->user_email,
                $product,
                $phone_number,
                current_time('F j, Y g:i a'),
                home_url('/user-portal/phone-contacts/')
            );
            
            wp_mail($admin_email, $subject, $body);
        }
    }

    /**
     * Get user IP address
     */
    private function get_user_ip()
    {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // X-Forwarded-For can be a comma-separated list — take the first IP.
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ip        = trim( explode( ',', $forwarded )[0] );
        } else {
            $ip = isset( $_SERVER['REMOTE_ADDR'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
                : '';
        }
        return $ip;
    }

    /**
     * Get contacts by product
     */
    public function get_contacts_by_product($product_id, $limit = 10)
    {
        global $wpdb;
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is $wpdb->prefix + hardcoded string.
        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT pc.*, u.display_name as user_name, u.user_email 
             FROM {$this->table_name} pc
             LEFT JOIN {$wpdb->users} u ON pc.user_id = u.ID
             WHERE pc.product_id = %d
             ORDER BY pc.clicked_at DESC
             LIMIT %d",
            $product_id,
            $limit
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Get contacts by seller
     */
    public function get_contacts_by_seller($seller_id, $limit = 10)
    {
        global $wpdb;
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is $wpdb->prefix + hardcoded string.
        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT pc.*, u.display_name as user_name, u.user_email, p.post_title as product_name
             FROM {$this->table_name} pc
             LEFT JOIN {$wpdb->users} u ON pc.user_id = u.ID
             LEFT JOIN {$wpdb->posts} p ON pc.product_id = p.ID
             WHERE pc.seller_id = %d
             ORDER BY pc.clicked_at DESC
             LIMIT %d",
            $seller_id,
            $limit
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }
}
