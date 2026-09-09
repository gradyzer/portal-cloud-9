<?php
/**
 * Portal Cloud 9 - Reward Points System
 *
 * Handles reward points for Customers, Shop Managers, and Administrators.
 * - Customers earn points on purchases and can redeem them as coupon codes.
 * - Shop Managers earn points based on sales volume and can request cash withdrawals.
 * - Administrators manage all points, settings, and withdrawal approvals.
 *
 * All direct DB queries intentionally skip object caching and use direct queries —
 * they serve real-time AJAX requests where stale values would cause incorrect
 * balances or double-spend bugs. All queries use $wpdb->prepare() or operate on
 * trusted table names ($wpdb->prefix + hardcoded string).
 *
 * @package Portal_Cloud_9
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'PortalCloud9_Rewards' ) ) {
    return;
}

class PortalCloud9_Rewards {

    /** Database table name (without prefix). */
    const TABLE_SUFFIX = 'portcld9_reward_log';

    /** Option key for reward settings. */
    const SETTINGS_KEY = 'portcld9_rewards_settings';

    /** User meta key for points balance. */
    const META_BALANCE = 'portcld9_reward_points';

    /** User meta key for pending withdrawals. */
    const META_WITHDRAWALS = 'portcld9_reward_withdrawals';

    public function __construct() {
        add_action( 'wp_ajax_portcld9_rewards_get_data',           [ $this, 'ajax_get_data' ] );
        add_action( 'wp_ajax_portcld9_rewards_admin_adjust',       [ $this, 'ajax_admin_adjust' ] );
        add_action( 'wp_ajax_portcld9_rewards_admin_get_users',    [ $this, 'ajax_admin_get_users' ] );
        add_action( 'wp_ajax_portcld9_rewards_convert_coupon',     [ $this, 'ajax_convert_coupon' ] );
        add_action( 'wp_ajax_portcld9_rewards_request_withdrawal', [ $this, 'ajax_request_withdrawal' ] );
        add_action( 'wp_ajax_portcld9_rewards_approve_withdrawal', [ $this, 'ajax_approve_withdrawal' ] );
        add_action( 'wp_ajax_portcld9_rewards_reject_withdrawal',  [ $this, 'ajax_reject_withdrawal' ] );
        add_action( 'wp_ajax_portcld9_rewards_save_settings',      [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_portcld9_rewards_get_log',            [ $this, 'ajax_get_log' ] );
        add_action( 'wp_ajax_portcld9_rewards_bulk_adjust',        [ $this, 'ajax_bulk_adjust' ] );
        add_action( 'wp_ajax_portcld9_rewards_trigger_expiry',     [ $this, 'ajax_trigger_expiry' ] );
        add_action( 'wp_ajax_portcld9_rewards_import_csv',         [ $this, 'ajax_import_csv' ] );

        // Return a clean JSON error for logged-out users instead of WordPress's "-1"
        $nopriv_actions = [
            'portcld9_rewards_get_data', 'portcld9_rewards_admin_adjust',
            'portcld9_rewards_admin_get_users', 'portcld9_rewards_convert_coupon',
            'portcld9_rewards_request_withdrawal', 'portcld9_rewards_approve_withdrawal',
            'portcld9_rewards_reject_withdrawal', 'portcld9_rewards_save_settings',
            'portcld9_rewards_get_log', 'portcld9_rewards_bulk_adjust',
            'portcld9_rewards_trigger_expiry', 'portcld9_rewards_import_csv',
        ];
        foreach ( $nopriv_actions as $action ) {
            add_action( 'wp_ajax_nopriv_' . $action, [ $this, 'ajax_require_login' ] );
        }

        // Award points when order completes
        add_action( 'woocommerce_order_status_completed', [ $this, 'award_on_order_complete' ] );

        // Points expiry cron
        add_action( 'portcld9_rewards_expiry_cron', [ $this, 'run_points_expiry' ] );
    }

    // ------------------------------------------------------------------
    // DB Setup
    // ------------------------------------------------------------------

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            points int(11) NOT NULL,
            type varchar(20) NOT NULL DEFAULT 'earn',
            source_type varchar(30) NOT NULL DEFAULT 'manual',
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            note text NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    // ------------------------------------------------------------------
    // Cron — Schedule / Clear / Run expiry
    // ------------------------------------------------------------------

    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( 'portcld9_rewards_expiry_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'portcld9_rewards_expiry_cron' );
        }
    }

    public static function clear_cron(): void {
        wp_clear_scheduled_hook( 'portcld9_rewards_expiry_cron' );
    }

    public function run_points_expiry(): void {
        $settings    = self::get_settings();
        $expiry_days = intval( $settings['points_expiry_days'] );
        if ( $expiry_days <= 0 ) {
            return; // 0 = never expire
        }

        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_SUFFIX; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $expiry_days . ' days' ) );

        $users = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            "SELECT user_id, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = 'portcld9_reward_points'
             AND CAST(meta_value AS UNSIGNED) > 0",
            ARRAY_A
        ) ?: [];

        foreach ( $users as $row ) {
            $user_id = absint( $row['user_id'] );

            $last_activity = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                "SELECT MAX(created_at) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $user_id
            ) );

            if ( ! $last_activity || $last_activity > $cutoff ) {
                continue; // Still within the active window
            }

            $balance = absint( $row['meta_value'] );
            update_user_meta( $user_id, self::META_BALANCE, 0 );
            self::log_transaction( $user_id, -$balance, 'deduct', 'expiry', 0,
                'Points expired after ' . $expiry_days . ' days of inactivity' );

            $user = get_user_by( 'id', $user_id );
            if ( $user ) {
                self::send_email(
                    $user->user_email,
                    'Your reward points have expired',
                    sprintf(
                        "Hi %s,\n\nYour %s reward points have expired after %d days of inactivity.\n\nVisit the shop to earn new points:\n%s",
                        $user->display_name,
                        number_format( $balance ),
                        $expiry_days,
                        home_url()
                    )
                );
            }
        }

        update_option( 'portcld9_rewards_last_expiry', gmdate( 'Y-m-d H:i:s' ) );
    }

    // ------------------------------------------------------------------
    // Nopriv — logged-out fallback
    // ------------------------------------------------------------------

    public function ajax_require_login(): void {
        wp_send_json_error( [ 'message' => 'Please log in to continue.' ] );
    }

    // ------------------------------------------------------------------
    // Email helper
    // ------------------------------------------------------------------

    private static function send_email( string $to, string $subject, string $body ): void {
        $site  = get_bloginfo( 'name' );
        $from  = get_bloginfo( 'admin_email' );
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site . ' <' . $from . '>',
        ];
        wp_mail( $to, '[' . $site . '] ' . $subject, $body, $headers );
    }

    // ------------------------------------------------------------------
    // Points Balance Helpers
    // ------------------------------------------------------------------

    public static function get_balance( int $user_id ): int {
        return absint( get_user_meta( $user_id, self::META_BALANCE, true ) );
    }

    private static function update_balance( int $user_id, int $delta ): int {
        $current = self::get_balance( $user_id );
        $new     = max( 0, $current + $delta );
        update_user_meta( $user_id, self::META_BALANCE, $new );
        return $new;
    }

    private static function log_transaction( int $user_id, int $points, string $type, string $source_type, int $source_id = 0, string $note = '' ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'user_id'     => $user_id,
                'points'      => $points,
                'type'        => sanitize_text_field( $type ),
                'source_type' => sanitize_text_field( $source_type ),
                'source_id'   => absint( $source_id ),
                'note'        => sanitize_text_field( $note ),
                'created_at'  => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public static function get_settings(): array {
        $defaults = [
            'points_per_dollar'         => 10,
            'redemption_rate'           => 100,
            'min_redemption'            => 50,
            'max_redemption_percent'    => 50,
            'coupon_expiry_days'        => 7,
            'manager_points_per_sale'   => 5,
            'withdrawal_rate'           => 100,
            'min_withdrawal'            => 500,
            'points_expiry_days'        => 365,
            'enabled'                   => true,
        ];
        $saved = get_option( self::SETTINGS_KEY, [] );
        return wp_parse_args( $saved, $defaults );
    }

    // ------------------------------------------------------------------
    // Award points on order completion
    // ------------------------------------------------------------------

    public function award_on_order_complete( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $settings = self::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $customer_id = absint( $order->get_customer_id() );
        $total       = floatval( $order->get_subtotal() );

        // --- Award customer points ---
        if ( $customer_id > 0 ) {
            $pts = absint( floor( $total * intval( $settings['points_per_dollar'] ) ) );
            if ( $pts > 0 ) {
                self::update_balance( $customer_id, $pts );
                self::log_transaction( $customer_id, $pts, 'earn', 'order', $order_id, 'Points for order #' . $order_id );

                $customer = get_user_by( 'id', $customer_id );
                if ( $customer ) {
                    self::send_email(
                        $customer->user_email,
                        'You earned reward points!',
                        sprintf(
                            "Hi %s,\n\nYou earned %s reward points on order #%d.\n\nYour new balance: %s points.\n\nRedeem your points here:\n%s",
                            $customer->display_name,
                            number_format( $pts ),
                            $order_id,
                            number_format( self::get_balance( $customer_id ) ),
                            home_url( '/user-portal/rewards/' )
                        )
                    );
                }
            }
        }

        // --- Award shop manager points for their products ---
        foreach ( $order->get_items() as $item ) {
            $product_id  = absint( $item->get_product_id() );
            $product_obj = get_post( $product_id );
            if ( ! $product_obj ) {
                continue;
            }
            $manager_id = absint( $product_obj->post_author );
            if ( $manager_id <= 0 ) {
                continue;
            }
            $manager = get_user_by( 'id', $manager_id );
            if ( ! $manager ) {
                continue;
            }
            // Only award if user is a shop_manager / editor / author
            if ( ! in_array( 'shop_manager', (array) $manager->roles, true )
                && ! in_array( 'editor', (array) $manager->roles, true )
                && ! in_array( 'author', (array) $manager->roles, true ) ) {
                continue;
            }
            $mgr_pts = absint( intval( $settings['manager_points_per_sale'] ) * absint( $item->get_quantity() ) );
            if ( $mgr_pts > 0 ) {
                self::update_balance( $manager_id, $mgr_pts );
                self::log_transaction( $manager_id, $mgr_pts, 'earn', 'sale', $order_id, 'Points from product sale #' . $order_id );
            }
        }
    }

    // ------------------------------------------------------------------
    // AJAX: Get dashboard data (role-aware)
    // ------------------------------------------------------------------

    public function ajax_get_data(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        }

        $user     = wp_get_current_user();
        $user_id  = absint( $user->ID );
        $roles    = (array) $user->roles;
        $settings = self::get_settings();

        if ( in_array( 'administrator', $roles, true ) ) {
            wp_send_json_success( $this->admin_dashboard_data( $settings ) );
        } elseif ( in_array( 'shop_manager', $roles, true ) ) {
            wp_send_json_success( $this->manager_dashboard_data( $user_id, $settings ) );
        } else {
            wp_send_json_success( $this->customer_dashboard_data( $user_id, $settings ) );
        }
    }

    private function admin_dashboard_data( array $settings ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        // Total points in circulation
        $total_points = absint( $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT SUM(meta_value) FROM {$wpdb->usermeta} WHERE meta_key = 'portcld9_reward_points'"
        ) );

        // Recent log entries
        $log = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            "SELECT l.*, u.display_name FROM {$table} l INNER JOIN {$wpdb->users} u ON l.user_id = u.ID ORDER BY l.created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            20
        ), ARRAY_A );

        // Top earners
        $top_earners = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            "SELECT u.ID, u.display_name, um.meta_value as points FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'portcld9_reward_points' ORDER BY CAST(um.meta_value AS UNSIGNED) DESC LIMIT 10" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        , ARRAY_A );

        // Pending withdrawals
        $pending = $this->get_all_pending_withdrawals();

        // Monthly stats for chart
        $monthly = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month, SUM(points) as total, type FROM {$table} WHERE created_at >= %s GROUP BY month, type ORDER BY month ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate( 'Y-m-d', strtotime( '-6 months' ) )
        ), ARRAY_A );

        return [
            'role'         => 'administrator',
            'total_points' => $total_points,
            'log'          => $log,
            'top_earners'  => $top_earners,
            'withdrawals'  => $pending,
            'monthly'      => $monthly,
            'settings'     => $settings,
        ];
    }

    private function manager_dashboard_data( int $user_id, array $settings ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $balance = self::get_balance( $user_id );

        // Manager's own log
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $log = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 30",
            $user_id
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Customers who bought manager's products and their points earned
        $product_ids = $this->get_manager_product_ids( $user_id );
        $customer_pts = [];
        if ( ! empty( $product_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

            // Detect WooCommerce HPOS vs traditional post-based storage
            $use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

            // Get the order IDs that contain the manager's products
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            if ( $use_hpos ) {
                // HPOS: wc_orders table exists
                $order_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT oi.order_id
                         FROM {$wpdb->prefix}woocommerce_order_items oi
                         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                             ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
                         INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
                         WHERE CAST(oim.meta_value AS UNSIGNED) IN ($placeholders)",
                        $product_ids
                    )
                ) ?: [];
            } else {
                // Traditional: orders stored as posts
                $order_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT oi.order_id
                         FROM {$wpdb->prefix}woocommerce_order_items oi
                         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                             ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
                         WHERE CAST(oim.meta_value AS UNSIGNED) IN ($placeholders)",
                        $product_ids
                    )
                ) ?: [];
            }

            if ( ! empty( $order_ids ) ) {
                $order_placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
                $args               = array_merge( array_map( 'absint', $order_ids ), [ 50 ] );
                $customer_pts       = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT l.user_id, u.display_name,
                                SUM(CASE WHEN l.type='earn'   THEN l.points      ELSE 0 END) as earned,
                                SUM(CASE WHEN l.type='redeem' THEN ABS(l.points) ELSE 0 END) as redeemed
                         FROM {$table} l
                         INNER JOIN {$wpdb->users} u ON l.user_id = u.ID
                         WHERE l.source_type = 'order'
                           AND l.source_id IN ($order_placeholders)
                         GROUP BY l.user_id
                         ORDER BY earned DESC
                         LIMIT %d",
                        $args
                    ),
                    ARRAY_A
                ) ?: [];
            }
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        }

        // Top selling products
        $top_products = $this->get_manager_top_products( $user_id );

        // Pending withdrawal
        $withdrawals = get_user_meta( $user_id, self::META_WITHDRAWALS, true );
        if ( ! is_array( $withdrawals ) ) {
            $withdrawals = [];
        }

        $cash_value = intval( $settings['withdrawal_rate'] ) > 0
            ? round( $balance / intval( $settings['withdrawal_rate'] ), 2 )
            : 0;

        return [
            'role'          => 'shop_manager',
            'balance'       => $balance,
            'log'           => $log,
            'customer_pts'  => $customer_pts,
            'top_products'  => $top_products,
            'withdrawals'   => $withdrawals,
            'cash_value'    => $cash_value,
            'settings'      => $settings,
        ];
    }

    private function customer_dashboard_data( int $user_id, array $settings ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $balance = self::get_balance( $user_id );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $log = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 30",
            $user_id
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $rate = intval( $settings['redemption_rate'] );
        $coupon_value = ( $rate > 0 && $balance >= intval( $settings['min_redemption'] ) )
            ? round( $balance / $rate, 2 )
            : 0;

        return [
            'role'         => 'customer',
            'balance'      => $balance,
            'log'          => $log,
            'coupon_value' => $coupon_value,
            'settings'     => $settings,
        ];
    }

    // ------------------------------------------------------------------
    // AJAX: Admin adjust points
    // ------------------------------------------------------------------

    public function ajax_admin_adjust(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $target_user = absint( wp_unslash( $_POST['target_user'] ?? 0 ) );
        $points      = intval( wp_unslash( $_POST['points'] ?? 0 ) );
        $action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ?? 'add' ) );
        $note        = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );

        // Whitelist — JS normalises 'set' and 'reset' before sending, so only these two should arrive
        if ( ! in_array( $action_type, [ 'add', 'deduct' ], true ) ) {
            $action_type = 'add';
        }

        if ( $target_user <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid user.' ] );
        }
        if ( $points <= 0 ) {
            wp_send_json_error( [ 'message' => 'Points must be greater than zero.' ] );
        }

        $delta = ( $action_type === 'deduct' ) ? -$points : $points;
        $type  = ( $action_type === 'deduct' ) ? 'deduct' : 'adjust';
        $new_balance = self::update_balance( $target_user, $delta );
        self::log_transaction( $target_user, $delta, $type, 'admin', 0, $note ?: 'Admin adjustment' );

        wp_send_json_success( [
            'message'     => 'Points updated successfully.',
            'new_balance' => $new_balance,
        ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Admin get users list
    // ------------------------------------------------------------------

    public function ajax_admin_get_users(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        $is_admin   = current_user_can( 'manage_options' );
        $is_manager = ! $is_admin && current_user_can( 'manage_woocommerce' );

        if ( ! $is_admin && ! $is_manager ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $search      = sanitize_text_field( wp_unslash( $_POST['search']      ?? '' ) );
        $role_filter = sanitize_key( wp_unslash( $_POST['role_filter']        ?? '' ) );

        $args = [
            'number'       => 50,
            'orderby'      => 'display_name',
            'fields'       => 'all',
            'role__not_in' => [ 'administrator' ], // Admins are never managed via Rewards
        ];

        if ( $search ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'display_name', 'user_email', 'user_login' ];
        }

        // Role filter — admins can filter by any role; managers always scoped to customers
        if ( $is_manager && ! $is_admin ) {
            // Shop managers can only see customers
            $args['role__in'] = [ 'customer', 'subscriber' ];
        } elseif ( $role_filter === 'shop_manager' ) {
            $args['role'] = 'shop_manager';
        } elseif ( $role_filter === 'customer' ) {
            $args['role__in'] = [ 'customer', 'subscriber' ];
        }
        // else: no role filter — return all roles for admin

        $users = get_users( $args );
        $out   = [];
        foreach ( $users as $u ) {
            $roles    = (array) $u->roles;
            $is_adm_u = in_array( 'administrator', $roles, true );
            $is_mgr_u = in_array( 'shop_manager', $roles, true );
            $role_key = $is_adm_u ? 'administrator' : ( $is_mgr_u ? 'shop_manager' : 'customer' );

            $out[] = [
                'id'       => absint( $u->ID ),
                'name'     => esc_html( $u->display_name ),
                'email'    => esc_html( $u->user_email ),
                'balance'  => self::get_balance( absint( $u->ID ) ),
                'role'     => $role_key,
                'role_lbl' => $is_adm_u ? 'Administrator' : ( $is_mgr_u ? 'Shop Manager' : 'Customer' ),
            ];
        }

        wp_send_json_success( $out );
    }

    // ------------------------------------------------------------------
    // AJAX: Customer converts points to coupon
    // ------------------------------------------------------------------

    public function ajax_convert_coupon(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        }

        $user_id  = absint( get_current_user_id() );
        $points   = absint( wp_unslash( $_POST['points'] ?? 0 ) );
        $settings = self::get_settings();

        $min  = intval( $settings['min_redemption'] );
        $rate = intval( $settings['redemption_rate'] );
        $balance = self::get_balance( $user_id );

        if ( $points < $min ) {
            wp_send_json_error( [ 'message' => 'Minimum ' . $min . ' points required to redeem.' ] );
        }
        if ( $points > $balance ) {
            wp_send_json_error( [ 'message' => 'You do not have enough points.' ] );
        }

        $discount = ( $rate > 0 ) ? round( $points / $rate, 2 ) : 0;
        if ( $discount <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid redemption rate configured.' ] );
        }

        // Create WooCommerce coupon — buffer any stray output (deprecation notices etc.)
        // that would corrupt the JSON response
        ob_start();
        $code        = 'RWD-' . strtoupper( wp_generate_password( 8, false ) );
        $expiry_days = max( 1, intval( $settings['coupon_expiry_days'] ?? 7 ) );
        $coupon_id   = 0;

        try {
            $coupon = new WC_Coupon();
            $coupon->set_code( $code );
            $coupon->set_discount_type( 'fixed_cart' );
            $coupon->set_amount( $discount );
            $coupon->set_usage_limit( 1 );
            $coupon->set_individual_use( true );

            // set_date_expires() is the non-deprecated WC 3.x+ API.
            // Pass a Unix timestamp — accepted by all WC versions.
            $coupon->set_date_expires( strtotime( '+' . $expiry_days . ' days' ) );

            $user_data = get_userdata( $user_id );
            if ( $user_data ) {
                $coupon->set_email_restrictions( [ $user_data->user_email ] );
            }
            $coupon->add_meta_data( '_portcld9_reward_coupon', '1', true );
            $coupon_id = $coupon->save();
        } catch ( Exception $e ) {
            ob_end_clean();
            wp_send_json_error( [ 'message' => 'Could not create coupon. Please try again.' ] );
        }

        ob_end_clean();

        if ( ! $coupon_id ) {
            wp_send_json_error( [ 'message' => 'Could not save coupon. Please try again.' ] );
        }

        // Deduct points
        self::update_balance( $user_id, -$points );
        self::log_transaction( $user_id, -$points, 'redeem', 'coupon', 0, 'Redeemed for coupon ' . $code );

        wp_send_json_success( [
            'message'     => 'Coupon created successfully.',
            'code'        => $code,
            'discount'    => $discount,
            'new_balance' => self::get_balance( $user_id ),
        ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Shop manager requests withdrawal
    // ------------------------------------------------------------------

    public function ajax_request_withdrawal(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        }

        $user_id  = absint( get_current_user_id() );
        $user     = wp_get_current_user();
        $points   = absint( wp_unslash( $_POST['points'] ?? 0 ) );
        $method   = sanitize_text_field( wp_unslash( $_POST['method'] ?? 'bank' ) );
        $details  = sanitize_textarea_field( wp_unslash( $_POST['details'] ?? '' ) );
        $settings = self::get_settings();

        $min = intval( $settings['min_withdrawal'] );
        $rate = intval( $settings['withdrawal_rate'] );
        $balance = self::get_balance( $user_id );

        if ( ! in_array( 'shop_manager', (array) $user->roles, true )
            && ! in_array( 'editor', (array) $user->roles, true )
            && ! in_array( 'author', (array) $user->roles, true ) ) {
            wp_send_json_error( [ 'message' => 'Only sellers can request withdrawals.' ] );
        }
        if ( $points < $min ) {
            wp_send_json_error( [ 'message' => 'Minimum ' . $min . ' points required for withdrawal.' ] );
        }
        if ( $points > $balance ) {
            wp_send_json_error( [ 'message' => 'Insufficient points balance.' ] );
        }

        $cash = ( $rate > 0 ) ? round( $points / $rate, 2 ) : 0;

        $withdrawals = get_user_meta( $user_id, self::META_WITHDRAWALS, true );
        if ( ! is_array( $withdrawals ) ) {
            $withdrawals = [];
        }

        $withdrawals[] = [
            'id'         => uniqid( 'wd_', true ),
            'points'     => $points,
            'cash'       => $cash,
            'method'     => $method,
            'details'    => $details,
            'status'     => 'pending',
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ];
        update_user_meta( $user_id, self::META_WITHDRAWALS, $withdrawals );

        // Hold the points
        self::update_balance( $user_id, -$points );
        self::log_transaction( $user_id, -$points, 'withdrawal', 'request', 0, 'Withdrawal request pending approval' );

        wp_send_json_success( [
            'message'     => 'Withdrawal request submitted. Pending admin approval.',
            'cash'        => $cash,
            'new_balance' => self::get_balance( $user_id ),
        ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Admin approves withdrawal
    // ------------------------------------------------------------------

    public function ajax_approve_withdrawal(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $target_user = absint( wp_unslash( $_POST['target_user'] ?? 0 ) );
        $wd_id       = sanitize_text_field( wp_unslash( $_POST['wd_id'] ?? '' ) );

        $withdrawals = get_user_meta( $target_user, self::META_WITHDRAWALS, true );
        if ( ! is_array( $withdrawals ) ) {
            wp_send_json_error( [ 'message' => 'No withdrawals found.' ] );
        }

        $found = false;
        foreach ( $withdrawals as &$wd ) {
            if ( $wd['id'] === $wd_id && $wd['status'] === 'pending' ) {
                $wd['status']      = 'approved';
                $wd['approved_at'] = gmdate( 'Y-m-d H:i:s' );
                $found             = true;
                break;
            }
        }
        unset( $wd );

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => 'Withdrawal not found or already processed.' ] );
        }

        update_user_meta( $target_user, self::META_WITHDRAWALS, $withdrawals );
        self::log_transaction( $target_user, 0, 'withdrawal_approved', 'admin', 0, 'Withdrawal approved by admin' );

        $mgr = get_user_by( 'id', $target_user );
        if ( $mgr ) {
            $approved_wd = array_values( array_filter( $withdrawals, fn( $w ) => $w['id'] === $wd_id ) )[0] ?? [];
            self::send_email(
                $mgr->user_email,
                'Your withdrawal request has been approved',
                sprintf(
                    "Hi %s,\n\nYour withdrawal request of %s pts (cash value: $%s) has been approved.\n\nYou should receive payment via your specified method shortly.\n\n%s",
                    $mgr->display_name,
                    number_format( absint( $approved_wd['points'] ?? 0 ) ),
                    number_format( floatval( $approved_wd['cash'] ?? 0 ), 2 ),
                    home_url( '/user-portal/rewards/' )
                )
            );
        }

        wp_send_json_success( [ 'message' => 'Withdrawal approved.' ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Admin rejects withdrawal
    // ------------------------------------------------------------------

    public function ajax_reject_withdrawal(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $target_user = absint( wp_unslash( $_POST['target_user'] ?? 0 ) );
        $wd_id       = sanitize_text_field( wp_unslash( $_POST['wd_id'] ?? '' ) );
        $reason      = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );

        $withdrawals = get_user_meta( $target_user, self::META_WITHDRAWALS, true );
        if ( ! is_array( $withdrawals ) ) {
            wp_send_json_error( [ 'message' => 'No withdrawals found.' ] );
        }

        $found  = false;
        $points = 0;
        foreach ( $withdrawals as &$wd ) {
            if ( $wd['id'] === $wd_id && $wd['status'] === 'pending' ) {
                $wd['status']      = 'rejected';
                $wd['rejected_at'] = gmdate( 'Y-m-d H:i:s' );
                $wd['reason']      = $reason;
                $points            = absint( $wd['points'] );
                $found             = true;
                break;
            }
        }
        unset( $wd );

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => 'Withdrawal not found or already processed.' ] );
        }

        update_user_meta( $target_user, self::META_WITHDRAWALS, $withdrawals );

        // Refund the held points
        if ( $points > 0 ) {
            self::update_balance( $target_user, $points );
            self::log_transaction( $target_user, $points, 'refund', 'admin', 0, 'Withdrawal rejected, points restored' );
        }

        $mgr = get_user_by( 'id', $target_user );
        if ( $mgr ) {
            $reason_line = $reason ? "\n\nReason given: " . $reason : '';
            self::send_email(
                $mgr->user_email,
                'Your withdrawal request was not approved',
                sprintf(
                    "Hi %s,\n\nYour withdrawal request of %s pts was not approved.%s\n\nYour points have been returned to your balance.\n\n%s",
                    $mgr->display_name,
                    number_format( $points ),
                    $reason_line,
                    home_url( '/user-portal/rewards/' )
                )
            );
        }

        wp_send_json_success( [ 'message' => 'Withdrawal rejected and points refunded.' ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Save settings (admin only)
    // ------------------------------------------------------------------

    public function ajax_save_settings(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $settings = [
            'points_per_dollar'       => absint( wp_unslash( $_POST['points_per_dollar'] ?? 10 ) ),
            'redemption_rate'         => absint( wp_unslash( $_POST['redemption_rate'] ?? 100 ) ),
            'min_redemption'          => absint( wp_unslash( $_POST['min_redemption'] ?? 50 ) ),
            'max_redemption_percent'  => absint( wp_unslash( $_POST['max_redemption_percent'] ?? 50 ) ),
            'coupon_expiry_days'      => absint( wp_unslash( $_POST['coupon_expiry_days'] ?? 7 ) ),
            'manager_points_per_sale' => absint( wp_unslash( $_POST['manager_points_per_sale'] ?? 5 ) ),
            'withdrawal_rate'         => absint( wp_unslash( $_POST['withdrawal_rate'] ?? 100 ) ),
            'min_withdrawal'          => absint( wp_unslash( $_POST['min_withdrawal'] ?? 500 ) ),
            'points_expiry_days'      => absint( wp_unslash( $_POST['points_expiry_days'] ?? 365 ) ),
            'enabled'                 => (bool) absint( sanitize_text_field( wp_unslash( $_POST['enabled'] ?? '0' ) ) ),
        ];

        update_option( self::SETTINGS_KEY, $settings );
        wp_send_json_success( [ 'message' => 'Settings saved successfully.' ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Get transaction log
    // ------------------------------------------------------------------

    public function ajax_get_log(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in.' ] );
        }

        global $wpdb;
        $current_id    = absint( get_current_user_id() );
        $requested_uid = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
        $page          = absint( wp_unslash( $_POST['page'] ?? 1 ) );
        $per           = 25;
        $offset        = ( $page - 1 ) * $per;
        $table         = $wpdb->prefix . self::TABLE_SUFFIX; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        $user   = wp_get_current_user();
        $is_adm = in_array( 'administrator', (array) $user->roles, true );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + hardcoded string, safe for interpolation.
        // Admin can request history for any specific user (used by history drawer)
        if ( $is_adm && $requested_uid > 0 ) {
            $log = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $requested_uid, $per, $offset
            ), ARRAY_A );
        } elseif ( $is_adm ) {
            $log = $wpdb->get_results( $wpdb->prepare(
                "SELECT l.*, u.display_name FROM {$table} l INNER JOIN {$wpdb->users} u ON l.user_id = u.ID ORDER BY l.created_at DESC LIMIT %d OFFSET %d",
                $per, $offset
            ), ARRAY_A );
        } else {
            $log = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $current_id, $per, $offset
            ), ARRAY_A );
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        wp_send_json_success( $log ?: [] );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function get_manager_product_ids( int $user_id ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'product' AND post_status = 'publish'",
            $user_id
        ) );
        return array_map( 'absint', $ids );
    }

    private function get_manager_top_products( int $user_id ): array {
        $product_ids = $this->get_manager_product_ids( $user_id );
        $out         = [];
        foreach ( array_slice( $product_ids, 0, 10 ) as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }
            $out[] = [
                'id'    => $pid,
                'name'  => $product->get_name(),
                'sales' => absint( $product->get_total_sales() ),
                'price' => wc_price( $product->get_price() ),
            ];
        }
        usort( $out, function ( $a, $b ) {
            return $b['sales'] - $a['sales'];
        } );
        return array_slice( $out, 0, 10 );
    }

    private function get_all_pending_withdrawals(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $users = $wpdb->get_results(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'portcld9_reward_withdrawals'"
        , ARRAY_A );

        $pending = [];
        foreach ( $users as $row ) {
            $wds = maybe_unserialize( $row['meta_value'] );
            if ( ! is_array( $wds ) ) {
                continue;
            }
            $user = get_user_by( 'id', absint( $row['user_id'] ) );
            foreach ( $wds as $wd ) {
                if ( $wd['status'] === 'pending' ) {
                    $wd['user_id']   = absint( $row['user_id'] );
                    $wd['user_name'] = $user ? esc_html( $user->display_name ) : 'Unknown';
                    $pending[]       = $wd;
                }
            }
        }
        return $pending;
    }

    // ------------------------------------------------------------------
    // AJAX: Bulk adjust points (admin only)
    // ------------------------------------------------------------------

    public function ajax_bulk_adjust(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $raw_ids     = sanitize_text_field( wp_unslash( $_POST['user_ids'] ?? '[]' ) );
        $user_ids    = array_filter( array_map( 'absint', (array) json_decode( $raw_ids, true ) ) );
        $points      = absint( wp_unslash( $_POST['points'] ?? 0 ) );
        $action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ?? 'add' ) );
        $note        = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );

        if ( ! in_array( $action_type, [ 'add', 'deduct' ], true ) ) {
            $action_type = 'add';
        }
        if ( empty( $user_ids ) ) {
            wp_send_json_error( [ 'message' => 'No users selected.' ] );
        }
        if ( $points <= 0 ) {
            wp_send_json_error( [ 'message' => 'Points must be greater than zero.' ] );
        }

        $updated = 0;
        $results = [];
        foreach ( $user_ids as $uid ) {
            $delta       = ( $action_type === 'deduct' ) ? -$points : $points;
            $type        = ( $action_type === 'deduct' ) ? 'deduct' : 'adjust';
            $new_balance = self::update_balance( $uid, $delta );
            self::log_transaction( $uid, $delta, $type, 'admin', 0, $note ?: 'Bulk adjustment' );
            $results[ $uid ] = $new_balance;
            $updated++;
        }

        wp_send_json_success( [
            'message'  => 'Updated ' . $updated . ' user' . ( $updated !== 1 ? 's' : '' ) . '.',
            'count'    => $updated,
            'balances' => $results,
        ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Manually trigger points expiry check (admin only)
    // ------------------------------------------------------------------

    public function ajax_trigger_expiry(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $this->run_points_expiry();
        $ran_at = gmdate( 'Y-m-d H:i:s' );
        update_option( 'portcld9_rewards_last_expiry', $ran_at );

        wp_send_json_success( [
            'message' => 'Expiry check complete.',
            'ran_at'  => $ran_at,
        ] );
    }

    // ------------------------------------------------------------------
    // AJAX: Import points from CSV (admin only)
    // Format: email_or_username, points, action (add/deduct/set), note
    // ------------------------------------------------------------------

    public function ajax_import_csv(): void {
        if ( ! check_ajax_referer( 'portcld9_rewards_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $file = $_FILES['csv_file']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( empty( $file ) || ! is_uploaded_file( $file ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
        }

        $handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $handle ) {
            wp_send_json_error( [ 'message' => 'Could not read file.' ] );
        }

        $rows   = 0;
        $errors = [];
        fgetcsv( $handle ); // skip header row

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            [ $identifier, $points_raw, $action_raw, $note_raw ] = array_pad( $row, 4, '' );
            $identifier = sanitize_text_field( trim( $identifier ) );
            $pts        = absint( trim( $points_raw ) );
            $action     = sanitize_text_field( strtolower( trim( $action_raw ) ) );
            $note       = sanitize_text_field( trim( $note_raw ) );

            if ( empty( $identifier ) ) { continue; }

            $user = get_user_by( 'email', $identifier ) ?: get_user_by( 'login', $identifier );
            if ( ! $user ) {
                $errors[] = 'User not found: ' . $identifier;
                continue;
            }
            if ( $pts <= 0 ) {
                $errors[] = 'Invalid points for: ' . $identifier;
                continue;
            }
            if ( ! in_array( $action, [ 'add', 'deduct', 'set' ], true ) ) {
                $action = 'add';
            }

            $uid = absint( $user->ID );
            if ( $action === 'set' ) {
                $current = self::get_balance( $uid );
                $delta   = $pts - $current;
                $type    = $delta >= 0 ? 'adjust' : 'deduct';
                update_user_meta( $uid, self::META_BALANCE, max( 0, $pts ) );
                self::log_transaction( $uid, $delta, $type, 'import', 0, $note ?: 'CSV import (set)' );
            } else {
                $delta = ( $action === 'deduct' ) ? -$pts : $pts;
                $type  = ( $action === 'deduct' ) ? 'deduct' : 'adjust';
                self::update_balance( $uid, $delta );
                self::log_transaction( $uid, $delta, $type, 'import', 0, $note ?: 'CSV import' );
            }
            $rows++;
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        wp_send_json_success( [
            'message' => 'Imported ' . $rows . ' row' . ( $rows !== 1 ? 's' : '' ) . '.',
            'rows'    => $rows,
            'errors'  => $errors,
        ] );
    }
}
