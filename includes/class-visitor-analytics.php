<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- All table names in this file are $wpdb->prefix + hardcoded string; no user input reaches any table name.
/**
 * Portal Cloud 9 – Visitor Analytics
 *
 * Two tables:
 *   portcld9_visitor_log  – historical page-view log (deduped per 30-min window)
 *   portcld9_presence     – real-time heartbeats (upserted every 20 s per visitor)
 *
 * "Online now" = rows in presence where last_seen >= UTC_TIMESTAMP() - INTERVAL 15 SECOND
 *
 * All timestamps stored and compared in UTC (current_time('mysql',true) vs
 * UTC_TIMESTAMP()) to avoid MySQL server timezone mismatches.
 *
 * The heartbeat uses a static HMAC token instead of a WP nonce so that
 * page-caching doesn't break it (WP nonces expire; a cached page would hand
 * every visitor the same expired nonce, silently killing all heartbeats).
 *
 * @package PortalCloud9
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Legacy class name.
class PortalCld9_Visitor_Analytics {

    private static $instance   = null;
    private        $log_table  = '';
    private        $pres_table = '';

    /* ------------------------------------------------------------------ */
    /*  Singleton                                                           */
    /* ------------------------------------------------------------------ */

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ------------------------------------------------------------------ */
    /*  Constructor – hooks                                                 */
    /* ------------------------------------------------------------------ */

    private function __construct() {
        global $wpdb;
        $this->log_table  = $wpdb->prefix . 'portcld9_visitor_log';
        $this->pres_table = $wpdb->prefix . 'portcld9_presence';

        // Create / upgrade tables only when the plugin version changes, not on every request
        add_action( 'init', [ $this, 'maybe_create_tables' ] );

        // Track historical page views
        add_action( 'wp', [ $this, 'track_visit' ] );

        // Heartbeat – open to ALL visitors, no WP nonce (see above)
        add_action( 'wp_ajax_nopriv_portcld9_heartbeat', [ $this, 'ajax_heartbeat' ] );
        add_action( 'wp_ajax_portcld9_heartbeat',        [ $this, 'ajax_heartbeat' ] );

        // Leave signal – deletes the visitor's presence row immediately on tab close / navigation
        add_action( 'wp_ajax_nopriv_portcld9_leave', [ $this, 'ajax_leave' ] );
        add_action( 'wp_ajax_portcld9_leave',        [ $this, 'ajax_leave' ] );

        // Stats endpoints – admin only, WP nonce protected
        add_action( 'wp_ajax_portcld9_visitor_stats',        [ $this, 'ajax_get_stats' ] );
        add_action( 'wp_ajax_portcld9_visitor_chart',        [ $this, 'ajax_get_chart' ] );
        add_action( 'wp_ajax_portcld9_visitor_range',        [ $this, 'ajax_get_range' ] );
        add_action( 'wp_ajax_portcld9_visitor_online',       [ $this, 'ajax_get_online' ] );
        add_action( 'wp_ajax_portcld9_visitor_peaks',        [ $this, 'ajax_get_peaks' ] );
        add_action( 'wp_ajax_portcld9_visitor_daily',        [ $this, 'ajax_get_daily' ] );
        add_action( 'wp_ajax_portcld9_visitor_weekly_chart', [ $this, 'ajax_get_weekly_chart' ] );
        add_action( 'wp_ajax_portcld9_visitor_peaks_range',  [ $this, 'ajax_get_peaks_range' ] );

        // Enqueue heartbeat script on every front-end page
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_heartbeat_script' ] );

        // Weekly cron cleanup
        add_action( 'init',                     [ $this, 'maybe_schedule_cleanup' ] );
        add_action( 'portcld9_visitor_cleanup', [ $this, 'cleanup_old_records' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Static token for heartbeat (cache-safe, never expires)             */
    /* ------------------------------------------------------------------ */

    /**
     * Returns a site-specific static token for the heartbeat endpoint.
     * Generated once from AUTH_KEY — stable across page loads and cache hits.
     * Not a security gate (heartbeat writes no sensitive data), just basic
     * obfuscation to prevent accidental external pings.
     */
    private static function heartbeat_token(): string {
        return substr( hash_hmac( 'sha256', 'portcld9_heartbeat', defined('AUTH_KEY') ? AUTH_KEY : 'portcld9' ), 0, 16 );
    }

    /* ------------------------------------------------------------------ */
    /*  Enqueue heartbeat on ALL front-end pages                           */
    /* ------------------------------------------------------------------ */

    public function enqueue_heartbeat_script() {
        if ( is_admin() ) { return; }

        // Use the same domain-based AJAX URL the rest of the plugin uses,
        // avoiding any discrepancy admin_url() can cause on some hosts.
        $ajax_url = admin_url( 'admin-ajax.php' );

        wp_enqueue_script(
            'portcld9-presence',
            PORTCLD9_PLUGIN_URL . 'assets/js/visitor-presence.js',
            [],
            PORTCLD9_VERSION,
            true  // footer
        );

        wp_localize_script( 'portcld9-presence', 'portcld9_presence', [
            'ajax_url' => $ajax_url,
            'token'    => self::heartbeat_token(), // static – cache-safe
            'interval' => 10, // seconds — tighter interval means faster crash detection
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  DB tables                                                           */
    /* ------------------------------------------------------------------ */

    public function maybe_create_tables() {
        // Only run dbDelta when the stored DB version doesn't match the current
        // plugin version. This prevents expensive DB introspection on every single
        // WordPress request — heartbeats, page loads, cron, REST, etc.
        $db_ver_key = 'portcld9_analytics_db_version';
        if ( get_option( $db_ver_key ) === PORTCLD9_VERSION ) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        // Historical visitor log
        dbDelta( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
        "CREATE TABLE {$this->log_table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_key varchar(80) NOT NULL DEFAULT '',
  page_url varchar(512) NOT NULL DEFAULT '',
  visited_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_visited_at (visited_at),
  KEY idx_visitor_key (visitor_key),
  KEY idx_key_time (visitor_key,visited_at)
) $charset;" );

        // Real-time presence – one row per visitor, upserted on heartbeat
        dbDelta( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
        "CREATE TABLE {$this->pres_table} (
  visitor_key varchar(80) NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (visitor_key),
  KEY idx_last_seen (last_seen)
) $charset;" );

        // Mark this version as done so we skip dbDelta on the next request
        update_option( 'portcld9_analytics_db_version', PORTCLD9_VERSION, false );
    }

    /* ------------------------------------------------------------------ */
    /*  Historical page-view tracker                                       */
    /* ------------------------------------------------------------------ */

    public function track_visit() {
        if ( is_admin() || wp_doing_ajax() ) { return; }
        if ( defined( 'DOING_CRON' )   && DOING_CRON )   { return; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST )  { return; }

        global $wpdb;

        $key = $this->get_visitor_key();
        $url = substr( esc_url_raw(
            ( is_ssl() ? 'https' : 'http' ) . '://' . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' )
        ), 0, 512 );
        $now = current_time( 'mysql', true );

        $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
            "SELECT id FROM {$this->log_table}
             WHERE visitor_key = %s AND page_url = %s
               AND visited_at >= DATE_SUB( %s, INTERVAL 30 MINUTE )
             LIMIT 1",
            $key, $url, $now
        ) );

        if ( ! $exists ) {
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
                $this->log_table,
                [ 'visitor_key' => $key, 'page_url' => $url, 'visited_at' => $now ],
                [ '%s', '%s', '%s' ]
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – heartbeat (nopriv, all visitors)                            */
    /* ------------------------------------------------------------------ */

    public function ajax_heartbeat() {
        // Validate static token (cache-safe, not a WP nonce)
        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nopriv endpoint uses static HMAC token instead of WP nonce (cache-safe design).
        if ( $token !== self::heartbeat_token() ) {
            wp_send_json_error( 'bad_token', 403 );
        }

        global $wpdb;

        $key = $this->get_visitor_key();
        $now = current_time( 'mysql', true );

        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
            "INSERT INTO {$this->pres_table} (visitor_key, last_seen)
             VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE last_seen = %s",
            $key, $now, $now
        ) );

        wp_send_json_success( [ 'ok' => true ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – leave signal (nopriv, all visitors)                        */
    /* ------------------------------------------------------------------ */

    /**
     * Fired by visitor-presence.js via sendBeacon on pagehide.
     * Deletes the visitor's presence row immediately so the online count
     * drops the moment they close the tab or navigate away, rather than
     * waiting for the 90-second heartbeat timeout to expire.
     */
    public function ajax_leave() {
        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nopriv endpoint uses static HMAC token instead of WP nonce (cache-safe design).
        if ( $token !== self::heartbeat_token() ) {
            wp_send_json_error( 'bad_token', 403 );
        }

        global $wpdb;

        $key = $this->get_visitor_key();

        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            $this->pres_table,
            [ 'visitor_key' => $key ],
            [ '%s' ]
        );

        wp_send_json_success( [ 'ok' => true ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – online-now count (admin only, polled every 10 s)            */
    /* ------------------------------------------------------------------ */

    public function ajax_get_online() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        wp_send_json_success( [ 'online' => $this->count_online() ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – stat cards                                                   */
    /* ------------------------------------------------------------------ */

    public function ajax_get_stats() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        wp_send_json_success( [
            'today'  => $this->count_today(),
            'week'   => $this->count_unique_log( '-7 days' ),
            'month'  => $this->count_unique_log( '-30 days' ),
            'year'   => $this->count_unique_log( '-365 days' ),
            'online' => $this->count_online(),
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – 30-day chart                                                 */
    /* ------------------------------------------------------------------ */

    public function ajax_get_chart() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT DATE(CONVERT_TZ(visited_at, '+00:00', @@session.time_zone)) AS day,
                    COUNT(DISTINCT visitor_key) AS visitors
             FROM {$this->log_table}
             WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY day ORDER BY day ASC",
            ARRAY_A
        );

        $map = [];
        foreach ( $rows as $r ) { $map[ $r['day'] ] = (int) $r['visitors']; }

        $labels = [];
        $data   = [];
        for ( $i = 29; $i >= 0; $i-- ) {
            $d        = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
            $labels[] = gmdate( 'M j', strtotime( $d ) );
            $data[]   = $map[ $d ] ?? 0;
        }

        wp_send_json_success( [ 'labels' => $labels, 'data' => $data ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – custom date range                                            */
    /* ------------------------------------------------------------------ */

    public function ajax_get_range() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        $from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
        $to   = sanitize_text_field( wp_unslash( $_POST['to']   ?? '' ) );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ||
             ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            wp_send_json_error( 'Invalid date format' );
        }
        if ( strtotime( $from ) > strtotime( $to ) ) {
            wp_send_json_error( 'From date must be before To date' );
        }

        global $wpdb;

        $unique = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
            "SELECT COUNT(DISTINCT visitor_key) FROM {$this->log_table}
             WHERE DATE(CONVERT_TZ(visited_at,'+00:00',@@session.time_zone)) BETWEEN %s AND %s",
            $from, $to
        ) );

        $total = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
            "SELECT COUNT(*) FROM {$this->log_table}
             WHERE DATE(CONVERT_TZ(visited_at,'+00:00',@@session.time_zone)) BETWEEN %s AND %s",
            $from, $to
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT DATE(CONVERT_TZ(visited_at,'+00:00',@@session.time_zone)) AS day,
                    COUNT(DISTINCT visitor_key) AS visitors
             FROM {$this->log_table}
             WHERE DATE(CONVERT_TZ(visited_at,'+00:00',@@session.time_zone)) BETWEEN %s AND %s
             GROUP BY day ORDER BY day ASC",
            $from, $to
        ), ARRAY_A );

        $map = [];
        foreach ( $rows as $r ) { $map[ $r['day'] ] = (int) $r['visitors']; }

        $labels = $data = [];
        for ( $cur = strtotime( $from ), $end = strtotime( $to ); $cur <= $end; $cur = strtotime( '+1 day', $cur ) ) {
            $d        = gmdate( 'Y-m-d', $cur );
            $labels[] = gmdate( 'M j', $cur );
            $data[]   = $map[ $d ] ?? 0;
        }

        wp_send_json_success( [ 'unique' => $unique, 'total' => $total, 'labels' => $labels, 'data' => $data ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – peak hours and peak days (last 30 days)                    */
    /* ------------------------------------------------------------------ */

    public function ajax_get_peaks() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        global $wpdb;

        // Accept ?period=7 or ?period=30 (default 30)
        $period_raw = isset( $_POST['period'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 30;
        $period = in_array( $period_raw, [ 7, 30 ], true ) ? $period_raw : 30;

        // Site UTC offset in seconds — convert stored UTC timestamps to local time
        $offset_sec = (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

        // ---- Peak hours: unique visitors per hour of day ----
        $hour_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT HOUR( DATE_ADD( visited_at, INTERVAL %d SECOND ) ) AS hr,
                    COUNT(DISTINCT visitor_key) AS visitors
             FROM {$this->log_table}
             WHERE visited_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
             GROUP BY hr
             ORDER BY hr ASC",
            $offset_sec, $period
        ), ARRAY_A );

        // Fill all 24 slots so the chart always has a complete array
        $hours = array_fill( 0, 24, 0 );
        foreach ( $hour_rows as $r ) {
            $hours[ (int) $r['hr'] ] = (int) $r['visitors'];
        }

        // ---- Peak days: unique visitors per day of week (last 30 days) -----
        // DAYOFWEEK returns 1=Sun, 2=Mon … 7=Sat — we reorder to Mon-first
        $day_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT DAYOFWEEK( DATE_ADD( visited_at, INTERVAL %d SECOND ) ) AS dow,
                    COUNT(DISTINCT visitor_key) AS visitors
             FROM {$this->log_table}
             WHERE visited_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
             GROUP BY dow
             ORDER BY dow ASC",
            $offset_sec, $period
        ), ARRAY_A );

        // Map MySQL DOW (1=Sun…7=Sat) → Mon-first index (0=Mon…6=Sun)
        $dow_map = [ 1 => 6, 2 => 0, 3 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5 ];
        $days = array_fill( 0, 7, 0 );
        foreach ( $day_rows as $r ) {
            $idx = $dow_map[ (int) $r['dow'] ] ?? null;
            if ( $idx !== null ) { $days[ $idx ] = (int) $r['visitors']; }
        }

        // ---- Peak highlights -----------------------------------------------
        $peak_hour_idx = array_search( max( $hours ), $hours );
        $next_hour     = ( $peak_hour_idx + 1 ) % 24;
        $fmt_hour      = function ( $h ) {
            $ampm = $h >= 12 ? 'PM' : 'AM';
            $h12  = $h % 12 ?: 12;
            return $h12 . ':00 ' . $ampm;
        };
        $peak_hour_label = $fmt_hour( $peak_hour_idx ) . ' – ' . $fmt_hour( $next_hour );

        $day_names       = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
        $peak_day_idx    = array_search( max( $days ), $days );
        $peak_day_label  = $day_names[ $peak_day_idx ] ?? '—';

        wp_send_json_success( [
            'hours'            => $hours,
            'days'             => $days,
            'peak_hour_label'  => $peak_hour_label,
            'peak_hour_count'  => max( $hours ),
            'peak_hour_idx'    => $peak_hour_idx,
            'peak_day_label'   => $peak_day_label,
            'peak_day_count'   => max( $days ),
            'period'           => $period,
        ] );
    }


    /* ------------------------------------------------------------------ */
    /*  AJAX – today's hourly visitor trend (Daily Visitor Trend)          */
    /* ------------------------------------------------------------------ */

    public function ajax_get_daily() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        global $wpdb;

        $offset_sec = (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

        $hour_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT HOUR( DATE_ADD( visited_at, INTERVAL %d SECOND ) ) AS hr,
                    COUNT(DISTINCT visitor_key) AS visitors
             FROM {$this->log_table}
             WHERE DATE( DATE_ADD( visited_at, INTERVAL %d SECOND ) ) = CURDATE()
             GROUP BY hr ORDER BY hr ASC",
            $offset_sec, $offset_sec
        ), ARRAY_A );

        $hours = array_fill( 0, 24, 0 );
        foreach ( $hour_rows as $r ) {
            $hours[ (int) $r['hr'] ] = (int) $r['visitors'];
        }

        wp_send_json_success( [ 'hours' => $hours ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – last-7-days daily chart (Weekly Visitor Trend)              */
    /* ------------------------------------------------------------------ */

    public function ajax_get_weekly_chart() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT DATE(CONVERT_TZ(visited_at, '+00:00', @@session.time_zone)) AS day,
                    COUNT(DISTINCT visitor_key) AS visitors
             FROM {$this->log_table}
             WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY day ORDER BY day ASC",
            ARRAY_A
        );

        $map = [];
        foreach ( $rows as $r ) { $map[ $r['day'] ] = (int) $r['visitors']; }

        $labels = [];
        $data   = [];
        for ( $i = 6; $i >= 0; $i-- ) {
            $d        = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
            $labels[] = gmdate( 'D, M j', strtotime( $d ) );
            $data[]   = $map[ $d ] ?? 0;
        }

        wp_send_json_success( [ 'labels' => $labels, 'data' => $data ] );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX – peak hours for a custom date range                          */
    /* ------------------------------------------------------------------ */

    public function ajax_get_peaks_range() {
        check_ajax_referer( 'portcld9_visitor_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        $from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
        $to   = sanitize_text_field( wp_unslash( $_POST['to']   ?? '' ) );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ||
             ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            wp_send_json_error( 'Invalid date format' );
        }

        global $wpdb;

        $offset_sec = (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

        $hour_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            "SELECT HOUR( DATE_ADD( visited_at, INTERVAL %d SECOND ) ) AS hr,
                    COUNT(DISTINCT visitor_key) AS visitors
             FROM {$this->log_table}
             WHERE DATE( DATE_ADD( visited_at, INTERVAL %d SECOND ) ) BETWEEN %s AND %s
             GROUP BY hr ORDER BY hr ASC",
            $offset_sec, $offset_sec, $from, $to
        ), ARRAY_A );

        $hours = array_fill( 0, 24, 0 );
        foreach ( $hour_rows as $r ) {
            $hours[ (int) $r['hr'] ] = (int) $r['visitors'];
        }

        wp_send_json_success( [ 'hours' => $hours ] );
    }


    /* ------------------------------------------------------------------ */
    /* ------------------------------------------------------------------ */

    /**
     * Count visitors with a heartbeat in the last 15 seconds.
     *
     * WHY 15 s:
     * Heartbeat fires every 10 s. The window is 1.5 × the interval, so
     * missing just ONE heartbeat is enough to drop the visitor from the
     * online count. This means phone-killed-by-OS, network drops, and
     * browser crashes all register within 15 seconds — as close to
     * immediate as a polling architecture allows.
     *
     * WHY UTC_TIMESTAMP() instead of NOW():
     * Heartbeats are stored as UTC via current_time('mysql', true).
     * MySQL's NOW() follows the DB server's session/local timezone, which
     * is often NOT UTC. UTC_TIMESTAMP() keeps both sides of the comparison
     * in the same timezone, preventing the counter from reading 0.
     */
    private function count_online(): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
            "SELECT COUNT(*) FROM {$this->pres_table}
             WHERE last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 SECOND)"
        );
    }

    private function count_unique_log( string $since_str ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
            "SELECT COUNT(DISTINCT visitor_key) FROM {$this->log_table}
             WHERE visited_at >= %s",
            gmdate( 'Y-m-d H:i:s', strtotime( $since_str ) )
        ) );
    }

    /**
     * Count unique visitors from midnight of the current calendar day in the
     * site's configured timezone (Settings → General → Timezone), not a
     * rolling 24-hour window.
     *
     * visited_at is stored in UTC, so we convert the site's local midnight
     * back to UTC before comparing.
     */
    private function count_today(): int {
        global $wpdb;

        // Get the site's UTC offset in seconds from WordPress settings
        $offset_seconds = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;

        // Today's date string in the site's local timezone
        $local_today = gmdate( 'Y-m-d', time() + (int) $offset_seconds );

        // Midnight of that date in UTC — what we compare against the DB
        $midnight_utc = gmdate( 'Y-m-d H:i:s', strtotime( $local_today . ' 00:00:00' ) - (int) $offset_seconds );

        return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot use placeholder.
            "SELECT COUNT(DISTINCT visitor_key) FROM {$this->log_table}
             WHERE visited_at >= %s",
            $midnight_utc
        ) );
    }

    private function get_visitor_key(): string {
        $uid = get_current_user_id();
        if ( $uid ) { return 'u_' . $uid; }
        return hash( 'sha256', $this->get_ip() . sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
    }

    private function get_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $raw = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
                $ip  = trim( explode( ',', $raw )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) { return $ip; }
            }
        }
        return '0.0.0.0';
    }

    /* ------------------------------------------------------------------ */
    /*  Cron cleanup                                                        */
    /* ------------------------------------------------------------------ */

    public function maybe_schedule_cleanup() {
        if ( ! wp_next_scheduled( 'portcld9_visitor_cleanup' ) ) {
            wp_schedule_event( time(), 'weekly', 'portcld9_visitor_cleanup' );
        }
    }

    public function cleanup_old_records() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->log_table}  WHERE visited_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 YEAR)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
        $wpdb->query( "DELETE FROM {$this->pres_table} WHERE last_seen  < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom table.
    }
}

add_action( 'plugins_loaded', [ 'PortalCld9_Visitor_Analytics', 'get_instance' ] );
