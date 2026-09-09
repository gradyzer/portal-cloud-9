<?php
/**
 * Portal Cloud 9 — Data Purge
 *
 * Single source of truth for full data deletion.
 * Called from:
 *  - uninstall.php  (when plugin is deleted with toggle ON)
 *  - AJAX handler   (instant delete from Settings > Data Management)
 *
 * @package Portal_Cloud_9
 */

defined( 'ABSPATH' ) || exit;

class PortalCloud9_Purge {

    /**
     * Run the full purge. Deletes every trace of Portal Cloud 9
     * from the WordPress database and filesystem.
     */
    public static function run(): void {
        global $wpdb;

        // The wipe destroys the security audit table along with everything
        // else, so a database record of this event can't survive it — the
        // very table it would live in is gone a few lines from now. An
        // email to the site admin is the only durable trail available for
        // an action that erases its own log.
        self::notify_admin_before_wipe();

        // ── 1. PLUGIN OPTIONS ────────────────────────────────────────
        // Explicit inventory covering both the free plugin and Portal
        // Cloud 9 Pro. The free plugin owns the database lifecycle for
        // the whole ecosystem, so Pro's keys are listed here even though
        // Pro is deactivated when this runs.
        $options = [
            // Free
            'portalcloud9_options',            // Main settings array
            'portcld9_analytics_db_version',   // Analytics DB version flag
            'portalcloud9_fav_db_ver',         // Favourites DB version flag
            'portcld9_remove_data_on_uninstall', // Legacy standalone key
            // Pro
            'portcld9_settings',
            'portcld9_license_data',
            'portcld9_status_notice',
            'portcld9_wa_click_salt',
            'portcld9_webhook_secret',
            'portcld9_whatsapp',
            'portcld9_caps_assigned_v8',
            'portcld9_ref_col_v2',
            'portcld9_ref_url_col_v1',
            'portcld9_rewards_last_expiry',
            'portcld9pro_activation_notice',
        ];

        foreach ( $options as $key ) {
            delete_option( $key );
            delete_site_option( $key ); // Multisite
        }

        // Prefix sweep — catches any option either plugin added after
        // this list was written, so future versions cannot orphan keys.
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'portcld9\_%'
             OR option_name LIKE 'portcld9pro\_%'
             OR option_name LIKE 'portalcloud9\_%'"
        );

        // ── 2. TRANSIENTS ────────────────────────────────────────────
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_pc9_%'
             OR option_name LIKE '_transient_timeout_pc9_%'
             OR option_name LIKE '_transient_portcld9_%'
             OR option_name LIKE '_transient_timeout_portcld9_%'
             OR option_name LIKE '_transient_portalcloud9_%'
             OR option_name LIKE '_transient_timeout_portalcloud9_%'"
        );

        if ( is_multisite() ) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            "DELETE FROM {$wpdb->sitemeta}
                 WHERE meta_key LIKE '_site_transient_portcld9_%'
                 OR meta_key LIKE '_site_transient_timeout_portcld9_%'"
            );
        }

        // ── 3. USER META ─────────────────────────────────────────────
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            "DELETE FROM {$wpdb->usermeta}
             WHERE meta_key LIKE 'portcld9_%'
             OR meta_key LIKE 'portalcloud9_%'"
        );

        // ── 4. POST META ─────────────────────────────────────────────
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key LIKE 'portcld9_%'
             OR meta_key LIKE 'portalcloud9_%'"
        );

        // ── 5. COMMENT META ──────────────────────────────────────────
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for plugin data cleanup.
            "DELETE FROM {$wpdb->commentmeta}
             WHERE meta_key LIKE 'portcld9_%'
             OR meta_key LIKE 'portalcloud9_%'"
        );

        // ── 6. CUSTOM TABLES ─────────────────────────────────────────
        // Every table either plugin creates. Dropped outright so a later
        // reactivation of Pro rebuilds a completely fresh schema.
        $tables = [
            // Free
            $wpdb->prefix . 'portcld9_phone_contacts',  // Phone contact tracking
            $wpdb->prefix . 'portcld9_visitor_log',     // Visitor analytics log
            $wpdb->prefix . 'portcld9_presence',        // Visitor presence tracking
            $wpdb->prefix . 'portalcloud9_favourites',  // User favourites/wishlist
            // Pro
            $wpdb->prefix . 'portcld9_form_submissions', // Visitor analytics form capture
            $wpdb->prefix . 'portcld9_login_log',        // Visitor analytics login log
            $wpdb->prefix . 'portcld9_sessions',         // Session tracking
            $wpdb->prefix . 'portcld9_messages',         // Inbox conversations
            $wpdb->prefix . 'portcld9_shipments',        // Shipment tracking
            $wpdb->prefix . 'portcld9_shipment_stages',  // Shipment stage history
            $wpdb->prefix . 'portcld9_reward_log',       // Reward point ledger
            $wpdb->prefix . 'portcld9_whatsapp_clicks',  // WhatsApp click tracking
            $wpdb->prefix . 'portcld9_login_attempts',   // Login attempt limiter
            $wpdb->prefix . 'portcld9_2fa',              // Two factor authentication
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `" . esc_sql( $table ) . "`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- DDL cannot use prepare(); table names built from $wpdb->prefix only.
        }

        // ── 7. CRON EVENTS ───────────────────────────────────────────
        $cron_hooks = [
            'portcld9_daily_cleanup',
            'portcld9_clear_expired_sessions',
            'portcld9_cache_cleanup',
            'portcld9_analytics_update',
            // Pro
            'portcld9_visitor_cleanup',
            'portcld9_heartbeat_compact',
            'portcld9_license_check_event',
            'portcld9_rewards_expiry_cron',
            'portcld9_twenty_minutes',
        ];

        foreach ( $cron_hooks as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }

        // ── 7b. ROLES ────────────────────────────────────────────────
        // Users holding a plugin created role are moved to Subscriber
        // first so nobody is left role less, then the roles are removed.
        $plugin_roles = [ 'portcld9_seller', 'portcld9_general_manager', 'portcld9_general_staff' ];
        foreach ( $plugin_roles as $role_key ) {
            $holders = get_users( [ 'role' => $role_key, 'fields' => 'ID', 'number' => -1 ] );
            foreach ( $holders as $uid ) {
                $u = new WP_User( (int) $uid );
                $u->remove_role( $role_key );
                if ( empty( $u->roles ) ) {
                    $u->set_role( 'subscriber' );
                }
            }
            remove_role( $role_key );
        }

        // ── 8. UPLOADED FILES ────────────────────────────────────────
        $upload_dir = wp_upload_dir();
        $dirs = [
            $upload_dir['basedir'] . '/pc9-uploads',
            $upload_dir['basedir'] . '/pc9-avatars',
            $upload_dir['basedir'] . '/pc9-messages',
        ];

        foreach ( $dirs as $dir ) {
            if ( is_dir( $dir ) ) {
                self::recursive_delete( $dir );
            }
        }

        // ── 9. REWRITE RULES ─────────────────────────────────────────
        delete_option( 'rewrite_rules' );

        // ── 10. OBJECT CACHE ─────────────────────────────────────────
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }

    /**
     * A durable, out-of-band record of the wipe, since the wipe destroys
     * the database record that would normally cover it. Sent to the site
     * admin email, which lives in wp_options — deliberately not touched
     * by this purge — so the notice arrives independent of everything
     * being deleted below.
     */
    private static function notify_admin_before_wipe(): void {
        $site  = get_bloginfo( 'name' );
        $admin = get_option( 'admin_email' );
        $who   = wp_get_current_user();
        $actor = $who && $who->ID ? ( $who->display_name . ' (' . $who->user_email . ')' ) : 'Unknown / automated (uninstall.php)';
        $ip    = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        $when  = current_time( 'mysql' );

        $subject = '[' . $site . '] Full Portal Cloud 9 data wipe just ran';
        $body    = "A complete Portal Cloud 9 data wipe was just triggered on {$site}.\n\n"
            . "Triggered by: {$actor}\n"
            . "IP address:   {$ip}\n"
            . "Time:         {$when}\n\n"
            . "Every table, option, role, scheduled task, and uploaded file created by "
            . "Portal Cloud 9 and Portal Cloud 9 Pro has been permanently deleted, including "
            . "the security audit log itself, which is why this email is the only remaining "
            . "record of this event. WooCommerce and WordPress core data were not affected.\n\n"
            . "If you did not expect this, the account used above should be reviewed immediately.";

        wp_mail( $admin, $subject, $body );
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private static function recursive_delete( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
                rmdir( $item->getRealPath() );
            } else {
                wp_delete_file( $item->getRealPath() );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        rmdir( $dir );
    }
}
