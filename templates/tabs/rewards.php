<?php
/**
 * Portal Cloud 9 – Rewards Tab (Server-Side Rendered)
 * @package Portal_Cloud_9
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
defined( 'ABSPATH' ) || exit;

/* ── Role ───────────────────────────────────────────────────────── */
$p9rw_me  = wp_get_current_user();
$p9rw_uid = absint( $p9rw_me->ID );
$p9rw_adm = in_array( 'administrator', (array) $p9rw_me->roles, true );
$p9rw_mgr = ! $p9rw_adm && in_array( 'shop_manager', (array) $p9rw_me->roles, true );

/* ── Settings ───────────────────────────────────────────────────── */
$p9rw_def = [ 'points_per_dollar'=>10,'redemption_rate'=>100,'min_redemption'=>50,
              'max_redemption_percent'=>50,'coupon_expiry_days'=>7,'manager_points_per_sale'=>5,
              'withdrawal_rate'=>100,'min_withdrawal'=>500,'points_expiry_days'=>365,'enabled'=>1 ];
$p9rw_s = class_exists('PortalCloud9_Rewards')
    ? wp_parse_args( PortalCloud9_Rewards::get_settings(), $p9rw_def ) : $p9rw_def;

/* ── DB ─────────────────────────────────────────────────────────── */
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$p9rw_tbl = esc_sql( $wpdb->prefix . 'portcld9_reward_log' ); // esc_sql: table name from trusted prefix, safe for interpolation
if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p9rw_tbl ) ) && class_exists('PortalCloud9_Rewards') ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    PortalCloud9_Rewards::create_table();
}
$p9rw_tbl_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p9rw_tbl ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$p9rw_bal = class_exists('PortalCloud9_Rewards') ? PortalCloud9_Rewards::get_balance( $p9rw_uid ) : 0;

/* ── Shared render helpers ──────────────────────────────────────── */
function p9rw_icon( $type ) {
    return [ 'earn'=>'⬆️','redeem'=>'🎟️','adjust'=>'✏️','deduct'=>'⬇️',
             'withdrawal'=>'💸','withdrawal_approved'=>'✅','refund'=>'↩️','sale'=>'🛒' ][ $type ] ?? '•';
}
function p9rw_type_color( $type ) {
    return [ 'earn'=>'emerald','redeem'=>'rose','adjust'=>'sky','deduct'=>'rose',
             'withdrawal'=>'violet','refund'=>'amber','sale'=>'emerald' ][ $type ] ?? 'sky';
}
function p9rw_render_log( $log, $show_user = false ) {
    if ( empty( $log ) ) {
        echo '<div class="p9rw-empty"><div class="p9rw-empty-icon">📋</div><p>No transactions yet.</p></div>';
        return;
    }
    echo '<div class="p9rw-log">';
    foreach ( $log as $e ) {
        $pts = intval( $e['points'] );
        $pos = $pts >= 0;
        $col = p9rw_type_color( $e['type'] );
        echo '<div class="p9rw-log-row">';
        echo '<div class="p9rw-log-dot ' . esc_attr($col) . '">' . p9rw_icon( $e['type'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- p9rw_icon() returns hardcoded emoji strings.
        echo '<div class="p9rw-log-body">';
        echo '<div class="p9rw-log-note">' . esc_html( $e['note'] ?: ucfirst($e['type']) );
        if ( $show_user && !empty($e['display_name']) ) echo ' &mdash; <strong>' . esc_html($e['display_name']) . '</strong>';
        if ( !empty($e['source_type']) && $e['source_type'] === 'order' && !empty($e['source_id']) ) {
            $order_url = wc_get_endpoint_url( 'view-order', absint($e['source_id']), wc_get_page_permalink('myaccount') );
            echo ' <a href="' . esc_url($order_url) . '" target="_blank" class="p9rw-order-link" title="View order #' . absint($e['source_id']) . '">#' . absint($e['source_id']) . '&nbsp;↗</a>';
        }
        echo '</div>';
        echo '<div class="p9rw-log-time">' . esc_html( $e['created_at'] ) . '</div>';
        echo '</div>';
        echo '<div class="p9rw-log-pts ' . ($pos?'pos':'neg') . '">' . ($pos?'+':'') . esc_html($pts) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

/* avatar helper */
function p9rw_avatar( $name, $size = 40 ) {
    $letter = mb_strtoupper( mb_substr( $name, 0, 1 ) );
    $hue    = ( crc32( $name ) % 360 + 360 ) % 360;
    echo '<div class="p9rw-ucard-av" style="--av-hue:' . absint($hue) . ';width:' . absint($size) . 'px;height:' . absint($size) . 'px;">' . esc_html($letter) . '</div>';
}

/* ================================================================ */
if ( $p9rw_adm ) :
/* ── ADMIN DATA ─────────────────────────────────────────────────── */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

$p9rw_total_pts = absint( $wpdb->get_var(
    "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)),0) FROM {$wpdb->usermeta} WHERE meta_key='portcld9_reward_points' AND CAST(meta_value AS UNSIGNED)>0"
) );

/* Fetch ALL users, merge in their points balance */
$_all_wp_users = get_users( [
    'number'  => 100,
    'orderby' => 'display_name',
    'fields'  => 'all',
] );

$p9rw_all_users = [];
foreach ( $_all_wp_users as $_u ) {
    $roles     = (array) $_u->roles;
    $is_adm_u  = in_array( 'administrator', $roles, true );
    $is_mgr_u  = in_array( 'shop_manager',  $roles, true );
    $bal       = class_exists('PortalCloud9_Rewards') ? PortalCloud9_Rewards::get_balance( absint($_u->ID) ) : 0;

    /* Fetch lifetime stats from log */
    $lifetime_e = 0; $lifetime_r = 0;
    if ( $p9rw_tbl_exists ) {
        $lrow = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(CASE WHEN type IN ('earn','adjust') THEN points ELSE 0 END) AS earned,
                    SUM(CASE WHEN type='redeem' THEN ABS(points) ELSE 0 END) AS redeemed
             FROM {$p9rw_tbl} WHERE user_id = %d",
            absint($_u->ID)
        ), ARRAY_A );
        $lifetime_e = absint($lrow['earned']   ?? 0);
        $lifetime_r = absint($lrow['redeemed'] ?? 0);
    }

    $p9rw_all_users[] = [
        'ID'           => absint($_u->ID),
        'display_name' => $_u->display_name,
        'user_email'   => $_u->user_email,
        'pts'          => $bal,
        'roles_list'   => $roles,
        'lifetime'     => [ 'lifetime_earned' => $lifetime_e, 'lifetime_redeemed' => $lifetime_r ],
        'is_admin'     => $is_adm_u,
        'is_mgr'       => $is_mgr_u,
    ];
}

/* Separate by role */
$p9rw_managers  = array_values( array_filter( $p9rw_all_users, fn($u) => $u['is_mgr'] && !$u['is_admin'] ) );
$p9rw_customers = array_values( array_filter( $p9rw_all_users, fn($u) => !$u['is_mgr'] && !$u['is_admin'] ) );
$p9rw_admins    = array_values( array_filter( $p9rw_all_users, fn($u) => $u['is_admin'] ) );

/* Log + withdrawals */
$p9rw_log_adm = $p9rw_tbl_exists
    ? $wpdb->get_results("SELECT l.*,u.display_name FROM {$p9rw_tbl} l INNER JOIN {$wpdb->users} u ON l.user_id=u.ID ORDER BY l.created_at DESC LIMIT 25", ARRAY_A) ?: []
    : [];

$p9rw_wdpend = [];
foreach ( $wpdb->get_results("SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key='portcld9_reward_withdrawals'", ARRAY_A) ?: [] as $_r ) {
    $wds = maybe_unserialize($_r['meta_value']);
    if ( ! is_array($wds) ) continue;
    $ui = get_user_by('id', absint($_r['user_id']));
    foreach ( $wds as $wd ) {
        if ( isset($wd['status']) && $wd['status'] === 'pending' ) {
            $wd['user_id']   = absint($_r['user_id']);
            $wd['user_name'] = $ui ? esc_html($ui->display_name) : 'Unknown';
            $p9rw_wdpend[]   = $wd;
        }
    }
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

/* ── Lifetime stats ────────────────────────────────────────────── */
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
$p9rw_lifetime = ['issued'=>0,'redeemed'=>0,'expired'=>0];
if ( $p9rw_tbl_exists ) {
    $lrow = $wpdb->get_row(
        "SELECT SUM(CASE WHEN points>0 THEN points ELSE 0 END) AS issued,
                SUM(CASE WHEN type='redeem' THEN ABS(points) ELSE 0 END) AS redeemed,
                SUM(CASE WHEN source_type='expiry' THEN ABS(points) ELSE 0 END) AS expired
         FROM {$p9rw_tbl}",
        ARRAY_A
    );
    $p9rw_lifetime = [
        'issued'   => absint($lrow['issued']   ?? 0),
        'redeemed' => absint($lrow['redeemed'] ?? 0),
        'expired'  => absint($lrow['expired']  ?? 0),
    ];
}
$p9rw_last_expiry = get_option('portcld9_rewards_last_expiry', '');
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

/* ── Render user card (admin) ───────────────────────────────────── */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Template function; p9rw_ is the established template prefix for this file.
function p9rw_admin_user_card( $u ) {
    $uid       = absint($u['ID']);
    $pts       = absint($u['pts']);
    $is_adm_u  = $u['is_admin'] ?? false;
    $is_mgr    = $u['is_mgr']   ?? false;
    $role_key  = $is_adm_u ? 'admin' : ( $is_mgr ? 'mgr' : 'cust' );
    $role_lbl  = $is_adm_u ? 'Administrator' : ( $is_mgr ? 'Shop Manager' : 'Customer' );
    $lifetime_e = absint($u['lifetime']['lifetime_earned']   ?? 0);
    $lifetime_r = absint($u['lifetime']['lifetime_redeemed'] ?? 0);
    $hue        = ( crc32($u['display_name']) % 360 + 360 ) % 360;
    $zero_cls   = $pts === 0 ? ' zero-pts' : '';
    ?>
    <div class="p9rw-acard<?php echo esc_attr($zero_cls); ?>"
         data-uid="<?php echo absint($uid); ?>"
         data-name="<?php echo esc_attr($u['display_name']); ?>"
         data-pts="<?php echo absint($pts); ?>"
         data-role="<?php echo esc_attr($role_key); ?>">
      <div class="p9rw-acard-top">
        <div class="p9rw-acard-av" style="--av-hue:<?php echo absint($hue); ?>"><?php echo esc_html(mb_strtoupper(mb_substr($u['display_name'],0,1))); ?></div>
        <div class="p9rw-acard-meta">
          <div class="p9rw-acard-name"><?php echo esc_html($u['display_name']); ?></div>
          <div class="p9rw-acard-email"><?php echo esc_html($u['user_email']); ?></div>
          <span class="p9rw-role-tag <?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_lbl); ?></span>
        </div>
        <div class="p9rw-acard-balance">
          <div class="p9rw-acard-bal-num<?php echo $pts===0?' zero':''; ?>"><?php echo number_format($pts); ?></div>
          <div class="p9rw-acard-bal-lbl">points</div>
        </div>
      </div>
      <div class="p9rw-acard-stats">
        <div class="p9rw-acard-stat" title="Total points ever earned"><span><?php echo number_format($lifetime_e); ?></span><label>Earned</label></div>
        <div class="p9rw-acard-stat" title="Points spent on coupons/withdrawals"><span><?php echo number_format($lifetime_r); ?></span><label>Redeemed</label></div>
        <div class="p9rw-acard-stat" title="Current balance"><span><?php echo number_format($pts); ?></span><label>Balance</label></div>
      </div>
      <div class="p9rw-acard-sel">
        <input type="checkbox" class="p9rw-card-cb" data-uid="<?php echo absint($uid); ?>" title="Select <?php echo esc_attr($u['display_name']); ?>">
      </div>
      <div class="p9rw-acard-actions">
        <button class="p9rw-action-btn add p9rw-adjust"
                data-uid="<?php echo absint($uid); ?>" data-name="<?php echo esc_attr($u['display_name']); ?>"
                data-pts="<?php echo absint($pts); ?>" data-type="add"
                title="Add points to <?php echo esc_attr($u['display_name']); ?>">➕ Add Points</button>
        <?php if($pts > 0): ?>
        <button class="p9rw-action-btn sub p9rw-adjust"
                data-uid="<?php echo absint($uid); ?>" data-name="<?php echo esc_attr($u['display_name']); ?>"
                data-pts="<?php echo absint($pts); ?>" data-type="deduct"
                title="Deduct from <?php echo esc_attr($u['display_name']); ?>">➖ Deduct</button>
        <button class="p9rw-action-btn set p9rw-adjust"
                data-uid="<?php echo absint($uid); ?>" data-name="<?php echo esc_attr($u['display_name']); ?>"
                data-pts="<?php echo absint($pts); ?>" data-type="set"
                title="Set exact balance for <?php echo esc_attr($u['display_name']); ?>">✏️ Set</button>
        <?php endif; ?>
        <button class="p9rw-action-btn hist p9rw-view-hist"
                data-uid="<?php echo absint($uid); ?>" data-name="<?php echo esc_attr($u['display_name']); ?>"
                title="View transaction history for <?php echo esc_attr($u['display_name']); ?>">📋 History</button>
      </div>
    </div>
    <?php
}
?>

<div class="p9rw-wrap"
     data-role="administrator"
     data-ajax="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>"
     data-nonce="<?php echo esc_attr( wp_create_nonce('portcld9_rewards_nonce') ); ?>"
     data-order-url="<?php echo esc_url( home_url('/user-portal/orders/') ); ?>">

  <div class="p9rw-role-pill admin">🛡️ Admin — Rewards Control Centre</div>

  <!-- Hero strip -->
  <div class="p9rw-admin-hero">
    <div class="p9rw-admin-hero-orb"></div>
    <div class="p9rw-ah-col">
      <div class="p9rw-ah-num"><?php echo number_format($p9rw_total_pts); ?></div>
      <div class="p9rw-ah-lbl">Points in Circulation</div>
    </div>
    <div class="p9rw-ah-divider"></div>
    <div class="p9rw-ah-col">
      <div class="p9rw-ah-num mgr-col"><?php echo count($p9rw_managers); ?></div>
      <div class="p9rw-ah-lbl">Shop Managers</div>
    </div>
    <div class="p9rw-ah-divider"></div>
    <div class="p9rw-ah-col">
      <div class="p9rw-ah-num active-col"><?php echo count($p9rw_customers); ?></div>
      <div class="p9rw-ah-lbl">Customers</div>
    </div>
    <div class="p9rw-ah-divider"></div>
    <div class="p9rw-ah-col">
      <div class="p9rw-ah-num pending-col"><?php echo count($p9rw_wdpend); ?></div>
      <div class="p9rw-ah-lbl">Pending Withdrawals</div>
    </div>
  </div>

  <!-- Lifetime stats bar -->
  <div class="p9rw-lifetime-bar">
    <div class="p9rw-lt-stat">
      <span class="p9rw-lt-icon">📈</span>
      <div><div class="p9rw-lt-num"><?php echo number_format($p9rw_lifetime['issued']); ?></div><div class="p9rw-lt-lbl">Total Issued</div></div>
    </div>
    <div class="p9rw-lt-divider"></div>
    <div class="p9rw-lt-stat">
      <span class="p9rw-lt-icon">🎟️</span>
      <div><div class="p9rw-lt-num redeemed"><?php echo number_format($p9rw_lifetime['redeemed']); ?></div><div class="p9rw-lt-lbl">Total Redeemed</div></div>
    </div>
    <div class="p9rw-lt-divider"></div>
    <div class="p9rw-lt-stat">
      <span class="p9rw-lt-icon">⏳</span>
      <div><div class="p9rw-lt-num expired"><?php echo number_format($p9rw_lifetime['expired']); ?></div><div class="p9rw-lt-lbl">Total Expired</div></div>
    </div>
    <div class="p9rw-lt-divider"></div>
    <div class="p9rw-lt-stat">
      <span class="p9rw-lt-icon">🕐</span>
      <div><div class="p9rw-lt-num small"><?php echo $p9rw_last_expiry ? esc_html(wp_date(get_option('date_format').' '.get_option('time_format'), strtotime($p9rw_last_expiry))) : 'Never'; ?></div><div class="p9rw-lt-lbl">Last Expiry Run</div></div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="p9rw-tabs">
    <button class="p9rw-tab active" data-panel="p9rw-manage"      title="Manage points for all users">👥 Manage Points</button>
    <button class="p9rw-tab"        data-panel="p9rw-withdrawals" title="Review pending cash withdrawal requests">💸 Withdrawals<?php if($p9rw_wdpend) echo '<span class="p9rw-badge">'.count($p9rw_wdpend).'</span>'; ?></button>
    <button class="p9rw-tab"        data-panel="p9rw-analytics"   title="Leaderboard and transaction log">📊 Analytics</button>
    <button class="p9rw-tab"        data-panel="p9rw-settings"    title="Configure point rates and redemption rules">⚙️ Settings</button>
  </div>

  <!-- ── MANAGE POINTS PANEL ────────────────────────────────────── -->
  <div id="p9rw-manage" class="p9rw-panel active">

    <!-- Search + filters inline row -->
    <div class="p9rw-search-filter-row">
      <!-- Pill search -->
      <div class="p9rw-pill-search">
        <div class="p9rw-pill-search-wrap">
          <span class="p9rw-pill-search-icon">🔍</span>
          <input type="text" id="p9rw-search-input" class="p9rw-pill-input"
                 placeholder="Search name or email…"
                 title="Search Shop Managers and Customers by name or email — results update live as you type"
                 autocomplete="off">
          <button class="p9rw-pill-clear" id="p9rw-search-clear" title="Clear search" style="display:none;">✕</button>
        </div>
        <button class="p9rw-pill-search-btn" id="p9rw-search-btn" title="Search">Search</button>
      </div>

      <!-- Role filter buttons (inline right) -->
      <div class="p9rw-filter-row">
        <button class="p9rw-filter-btn active" data-filter="all"     data-role-filter=""             title="Show all users">All <span class="p9rw-fc"><?php echo count($p9rw_managers) + count($p9rw_customers); ?></span></button>
        <button class="p9rw-filter-btn"        data-filter="manager" data-role-filter="shop_manager" title="Shop Managers only">🏪 Managers <span class="p9rw-fc"><?php echo count($p9rw_managers); ?></span></button>
        <button class="p9rw-filter-btn"        data-filter="cust"    data-role-filter="customer"     title="Customers only">🛒 Customers <span class="p9rw-fc"><?php echo count($p9rw_customers); ?></span></button>
      </div>
    </div>

    <!-- Search status bar -->
    <div class="p9rw-search-status" id="p9rw-search-status" style="display:none;">
      <span id="p9rw-status-text"></span>
      <button class="p9rw-status-reset" id="p9rw-status-reset" title="Clear search and show all users">Show all</button>
    </div>

    <!-- Select-all row -->
    <div class="p9rw-select-row" id="p9rw-select-row" style="display:none;">
      <label class="p9rw-select-all-wrap" title="Select or deselect all visible users">
        <input type="checkbox" id="p9rw-select-all"> <span>Select all</span>
      </label>
      <span class="p9rw-selected-count" id="p9rw-selected-count">0 selected</span>
    </div>

    <!-- User cards grid -->
    <div id="p9rw-user-list" class="p9rw-acard-grid">
      <?php if ( empty($p9rw_managers) && empty($p9rw_customers) ) : ?>
        <div class="p9rw-empty" style="grid-column:1/-1;"><div class="p9rw-empty-icon">👤</div><p>No Shop Managers or Customers found.</p></div>
      <?php else : ?>
        <?php foreach ( $p9rw_managers  as $u ) : p9rw_admin_user_card($u); endforeach; ?>
        <?php foreach ( $p9rw_customers as $u ) : p9rw_admin_user_card($u); endforeach; ?>
      <?php endif; ?>
    </div>

  </div><!-- /manage panel -->

  <!-- ── WITHDRAWALS PANEL ─────────────────────────────────────── -->
  <div id="p9rw-withdrawals" class="p9rw-panel">
    <?php if ( empty($p9rw_wdpend) ) : ?>
      <div class="p9rw-empty"><div class="p9rw-empty-icon">✅</div><p>No pending withdrawal requests — all caught up.</p></div>
    <?php else : foreach ( $p9rw_wdpend as $wd ) : ?>
      <div class="p9rw-wd-card">
        <div class="p9rw-wd-top">
          <div class="p9rw-wd-who">
            <span class="p9rw-wd-name"><?php echo esc_html($wd['user_name']); ?></span>
            <span class="p9rw-pill pending">Pending</span>
          </div>
          <div class="p9rw-wd-amount"><?php echo wp_kses_post( wc_price(floatval($wd['cash'])) ); ?></div>
        </div>
        <div class="p9rw-wd-meta"><?php echo absint($wd['points']); ?> pts &bull; <?php echo esc_html(strtoupper($wd['method'])); ?></div>
        <div class="p9rw-wd-meta" style="word-break:break-all;"><?php echo esc_html($wd['details']); ?></div>
        <div class="p9rw-wd-date"><?php echo esc_html($wd['created_at']); ?></div>
        <div class="p9rw-wd-actions">
          <button class="p9rw-btn emerald p9rw-approve" data-uid="<?php echo absint($wd['user_id']); ?>" data-wdid="<?php echo esc_attr($wd['id']); ?>" title="Approve — mark as paid and close">✅ Approve</button>
          <button class="p9rw-btn danger  p9rw-reject"  data-uid="<?php echo absint($wd['user_id']); ?>" data-wdid="<?php echo esc_attr($wd['id']); ?>" title="Reject — points are refunded to seller">❌ Reject</button>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- ── ANALYTICS PANEL ───────────────────────────────────────── -->
  <div id="p9rw-analytics" class="p9rw-panel">
    <div class="p9rw-analytics-grid">

      <div class="p9rw-analytics-card">
        <div class="p9rw-analytics-card-head">🏆 Top Point Holders</div>
        <div class="p9rw-leaderboard">
          <?php $lb_all = array_merge($p9rw_managers, $p9rw_customers);
          usort($lb_all, fn($a,$b) => absint($b['pts']) - absint($a['pts']));
          foreach ( array_slice($lb_all,0,10) as $i => $u ) :
            $medal = ['gold','silver','bronze'][$i] ?? '';
            $is_mgr = in_array('shop_manager', $u['roles_list'], true);
          ?>
          <div class="p9rw-lb-row <?php echo esc_attr($medal); ?>" title="<?php echo esc_attr($u['display_name']); ?> — <?php echo number_format(absint($u['pts'])); ?> pts">
            <div class="p9rw-lb-rank"><?php echo ($i<3) ? esc_html(['🥇','🥈','🥉'][$i]) : absint($i+1); ?></div>
            <div class="p9rw-lb-info">
              <div class="p9rw-lb-name"><?php echo esc_html($u['display_name']); ?></div>
              <span class="p9rw-role-tag <?php echo $is_mgr?'mgr':'cust'; ?> sm"><?php echo $is_mgr?'Manager':'Customer'; ?></span>
            </div>
            <div class="p9rw-lb-bar"><div class="p9rw-lb-fill" style="width:<?php echo absint(min(100, absint($u['pts'])/max(1,absint($lb_all[0]['pts']??1))*100)); ?>%"></div></div>
            <div class="p9rw-lb-val"><?php echo number_format(absint($u['pts'])); ?> pts</div>
          </div>
          <?php endforeach; ?>
          <?php if ( empty($lb_all) ) echo '<div class="p9rw-empty"><div class="p9rw-empty-icon">📊</div><p>No data yet.</p></div>'; ?>
        </div>
      </div>

      <div class="p9rw-analytics-card">
        <div class="p9rw-analytics-card-head">📋 Recent Transactions</div>
        <?php p9rw_render_log( $p9rw_log_adm, true ); ?>
      </div>

    </div>
  </div>

  <!-- ── SETTINGS PANEL ────────────────────────────────────────── -->
  <div id="p9rw-settings" class="p9rw-panel">

    <div class="p9rw-settings-group">
      <div class="p9rw-settings-group-title">🛒 Customer Points</div>
      <div class="p9rw-settings-grid">
        <?php $cust_cfg = [
          ['points_per_dollar','Points per $1 Spent',$p9rw_s['points_per_dollar'],'Points earned on each dollar of a completed order.',1,1000],
          ['redemption_rate','Points per $1 Coupon',$p9rw_s['redemption_rate'],'How many points equal $1 off on a coupon.',1,10000],
          ['min_redemption','Min Points to Redeem',$p9rw_s['min_redemption'],'Minimum balance required before a customer can redeem.',1,10000],
          ['max_redemption_percent','Max Discount % per Order',$p9rw_s['max_redemption_percent'],'Maximum % of the order total that can be discounted.',1,100],
          ['coupon_expiry_days','Coupon Expiry (days)',$p9rw_s['coupon_expiry_days'],'Days before a redeemed coupon expires. Default is 7.',1,365],
        ]; foreach($cust_cfg as $f): ?>
        <div class="p9rw-sf p9rw-sf-card">
          <label class="p9rw-sf-lbl" for="p9rw-rs-<?php echo esc_attr($f[0]); ?>"><?php echo esc_html($f[1]); ?></label>
          <input type="number" id="p9rw-rs-<?php echo esc_attr($f[0]); ?>" class="p9rw-field p9rw-rs-input" name="<?php echo esc_attr($f[0]); ?>" value="<?php echo absint($f[2]); ?>" min="<?php echo absint($f[4]); ?>" max="<?php echo absint($f[5]); ?>">
          <span class="p9rw-sf-hint"><?php echo esc_html($f[3]); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="p9rw-settings-group">
      <div class="p9rw-settings-group-title">🏪 Seller Withdrawals</div>
      <div class="p9rw-settings-grid">
        <?php $sell_cfg = [
          ['manager_points_per_sale','Points per Item Sold',$p9rw_s['manager_points_per_sale'],'Points awarded to a Shop Manager per unit sold.',1,1000],
          ['withdrawal_rate','Points per $1 Payout',$p9rw_s['withdrawal_rate'],'How many seller points equal $1 cash on withdrawal.',1,10000],
          ['min_withdrawal','Min Points to Withdraw',$p9rw_s['min_withdrawal'],'Minimum points a seller must hold to request a payout.',1,100000],
          ['points_expiry_days','Points Expiry (days, 0=never)',$p9rw_s['points_expiry_days'],'Days before unspent points expire. Set 0 to disable.',0,3650],
        ]; foreach($sell_cfg as $f): ?>
        <div class="p9rw-sf p9rw-sf-card">
          <label class="p9rw-sf-lbl" for="p9rw-rs-<?php echo esc_attr($f[0]); ?>"><?php echo esc_html($f[1]); ?></label>
          <input type="number" id="p9rw-rs-<?php echo esc_attr($f[0]); ?>" class="p9rw-field p9rw-rs-input" name="<?php echo esc_attr($f[0]); ?>" value="<?php echo absint($f[2]); ?>" min="<?php echo absint($f[4]); ?>" max="<?php echo absint($f[5]); ?>">
          <span class="p9rw-sf-hint"><?php echo esc_html($f[3]); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Expiry trigger -->
    <div class="p9rw-settings-group">
      <div class="p9rw-settings-group-title">⏳ Points Expiry</div>
      <div class="p9rw-expiry-row">
        <div class="p9rw-expiry-meta">
          <span class="p9rw-expiry-label">Last run:</span>
          <span class="p9rw-expiry-time" id="p9rw-expiry-time"><?php echo $p9rw_last_expiry ? esc_html(wp_date(get_option('date_format').' '.get_option('time_format'),strtotime($p9rw_last_expiry))) : 'Never'; ?></span>
        </div>
        <button class="p9rw-btn ghost" id="p9rw-run-expiry" title="Manually run the expiry check right now">⚡ Run Now</button>
      </div>
    </div>

    <!-- CSV import -->
    <div class="p9rw-settings-group">
      <div class="p9rw-settings-group-title">📥 Bulk Import via CSV</div>
      <div class="p9rw-csv-hint">Upload a CSV with columns: <code>email_or_username, points, action (add/deduct/set), note</code>. First row is the header and will be skipped.</div>
      <div class="p9rw-csv-drop" id="p9rw-csv-drop" title="Drag a CSV file here or click Browse">
        <div class="p9rw-csv-drop-icon">📂</div>
        <div class="p9rw-csv-drop-text">Drag CSV here or <span class="p9rw-csv-browse">browse</span></div>
        <input type="file" id="p9rw-csv-file" accept=".csv,text/csv" style="display:none;">
        <div class="p9rw-csv-filename" id="p9rw-csv-filename" style="display:none;"></div>
      </div>
      <button class="p9rw-btn emerald" id="p9rw-csv-import" disabled title="Import points from the selected CSV file">📥 Import Points</button>
      <div class="p9rw-csv-result" id="p9rw-csv-result" style="display:none;"></div>
    </div>

    <div class="p9rw-settings-group p9rw-settings-footer">
      <label class="p9rw-toggle-wrap" title="Enable or disable the entire rewards system site-wide">
        <span class="p9rw-tog"><input type="checkbox" id="p9rw-rs-enabled"<?php checked(!empty($p9rw_s['enabled'])); ?>><span class="p9rw-tog-track"></span></span>
        <span class="p9rw-tog-lbl">Reward Points System Enabled</span>
      </label>
      <button class="p9rw-btn primary" id="p9rw-save-settings" title="Save all reward system configuration">💾 Save Settings</button>
    </div>

  </div>

</div><!-- .p9rw-wrap -->

<!-- ── FLOATING BULK ACTION BAR ──────────────────────────────── -->
<div id="p9rw-bulk-bar" class="p9rw-bulk-bar" style="display:none;" aria-hidden="true">
  <div class="p9rw-bulk-bar-inner">
    <div class="p9rw-bulk-info">
      <span class="p9rw-bulk-count" id="p9rw-bulk-count">0</span> users selected
    </div>
    <div class="p9rw-bulk-controls">
      <input type="number" id="p9rw-bulk-pts" class="p9rw-bulk-input" min="1" value="100" placeholder="Points">
      <select id="p9rw-bulk-action" class="p9rw-bulk-select">
        <option value="add">➕ Add</option>
        <option value="deduct">➖ Deduct</option>
      </select>
      <input type="text" id="p9rw-bulk-note" class="p9rw-bulk-input wide" placeholder="Note (optional)">
      <button class="p9rw-btn primary" id="p9rw-bulk-apply" title="Apply to all selected users">Apply</button>
      <button class="p9rw-btn ghost" id="p9rw-bulk-cancel" title="Deselect all">✕</button>
    </div>
  </div>
</div>

<!-- ── ADJUST POINTS MODAL ────────────────────────────────────── -->
<div id="p9rw-modal" class="p9rw-overlay" aria-hidden="true">
  <div class="p9rw-modal">

    <!-- Modal header -->
    <div class="p9rw-modal-hdr">
      <div class="p9rw-modal-user">
        <div id="p9rw-modal-av" class="p9rw-modal-av" style="--av-hue:200">?</div>
        <div>
          <div id="p9rw-modal-title" class="p9rw-modal-title">Adjust Points</div>
          <div id="p9rw-modal-role"  class="p9rw-modal-role-tag"></div>
        </div>
      </div>
      <div class="p9rw-modal-bal-box" title="User's current points balance">
        <div id="p9rw-modal-cur-pts" class="p9rw-modal-cur-pts">0</div>
        <div class="p9rw-modal-cur-lbl">current pts</div>
      </div>
    </div>

    <!-- Action type -->
    <div class="p9rw-mf">
      <label class="p9rw-sf-lbl">Action</label>
      <div class="p9rw-action-pills" id="p9rw-action-pills">
        <button type="button" class="p9rw-apill active" data-action="add"    title="Add points on top of existing balance">➕ Add</button>
        <button type="button" class="p9rw-apill"        data-action="deduct" title="Remove points from existing balance">➖ Deduct</button>
        <button type="button" class="p9rw-apill"        data-action="set"    title="Override balance to an exact number">✏️ Set Balance</button>
        <button type="button" class="p9rw-apill danger" data-action="reset"  title="Zero out the balance entirely">🗑️ Reset to Zero</button>
      </div>
    </div>

    <!-- Quick presets -->
    <div class="p9rw-mf" id="p9rw-preset-row">
      <label class="p9rw-sf-lbl">Quick Amount</label>
      <div class="p9rw-presets">
        <button type="button" class="p9rw-preset" data-val="50"   title="Set amount to 50">50</button>
        <button type="button" class="p9rw-preset" data-val="100"  title="Set amount to 100">100</button>
        <button type="button" class="p9rw-preset" data-val="250"  title="Set amount to 250">250</button>
        <button type="button" class="p9rw-preset" data-val="500"  title="Set amount to 500">500</button>
        <button type="button" class="p9rw-preset" data-val="1000" title="Set amount to 1000">1000</button>
      </div>
    </div>

    <!-- Amount input -->
    <div class="p9rw-mf" id="p9rw-amount-row">
      <label class="p9rw-sf-lbl">Amount</label>
      <input type="number" id="p9rw-modal-pts" class="p9rw-field" min="1" value="100" title="How many points to add, deduct, or set">
    </div>

    <!-- Live preview -->
    <div class="p9rw-modal-preview" id="p9rw-modal-preview" title="What the balance will become after this action">
      Balance after: <strong id="p9rw-preview-after">—</strong>
    </div>

    <!-- Reason category -->
    <div class="p9rw-mf">
      <label class="p9rw-sf-lbl">Reason</label>
      <select id="p9rw-modal-reason" class="p9rw-field" title="Choose a category for this adjustment">
        <option value="Manual Adjustment">Manual Adjustment</option>
        <option value="Bonus Reward">Bonus Reward</option>
        <option value="Compensation">Compensation / Goodwill</option>
        <option value="Promotion">Promotional Grant</option>
        <option value="Correction">Balance Correction</option>
        <option value="Penalty">Policy Violation / Penalty</option>
        <option value="Other">Other</option>
      </select>
    </div>

    <!-- Note -->
    <div class="p9rw-mf">
      <label class="p9rw-sf-lbl">Note (optional)</label>
      <input type="text" id="p9rw-modal-note" class="p9rw-field" placeholder="Additional detail visible in transaction history" title="This note appears in the user's transaction log">
    </div>

    <input type="hidden" id="p9rw-modal-uid">
    <input type="hidden" id="p9rw-modal-action-type" value="add">

    <div class="p9rw-modal-foot">
      <button class="p9rw-btn ghost" id="p9rw-modal-cancel" title="Cancel without making changes">Cancel</button>
      <button class="p9rw-btn primary" id="p9rw-modal-confirm" title="Apply this points adjustment">Apply Change</button>
    </div>
  </div>
</div>

<!-- ── HISTORY DRAWER ─────────────────────────────────────────── -->
<div id="p9rw-drawer" class="p9rw-overlay" aria-hidden="true">
  <div class="p9rw-modal" style="max-width:480px;">
    <div class="p9rw-modal-hdr">
      <div class="p9rw-modal-user">
        <div id="p9rw-drawer-av" class="p9rw-modal-av" style="--av-hue:200">?</div>
        <div>
          <div id="p9rw-drawer-name" class="p9rw-modal-title">History</div>
          <div class="p9rw-modal-role-tag" style="color:var(--p9-muted);">Transaction Log</div>
        </div>
      </div>
      <button class="p9rw-btn ghost" id="p9rw-drawer-close" style="padding:6px 12px;" title="Close history panel">✕</button>
    </div>
    <div id="p9rw-drawer-body" style="margin-top:12px;max-height:360px;overflow-y:auto;">
      <div class="p9rw-empty"><div class="p9rw-empty-icon">⏳</div><p>Loading…</p></div>
    </div>
  </div>
</div>

<?php
/* ================================================================ */
elseif ( $p9rw_mgr ) :
/* ── SHOP MANAGER DATA ───────────────────────────────────────── */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
$p9rw_mylog = $p9rw_tbl_exists ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p9rw_tbl} WHERE user_id=%d ORDER BY created_at DESC LIMIT 25",$p9rw_uid),ARRAY_A)?:[] : [];
$p9rw_myprods_raw = $wpdb->get_results($wpdb->prepare("SELECT ID,post_title FROM {$wpdb->posts} WHERE post_author=%d AND post_type='product' AND post_status='publish' ORDER BY ID DESC",$p9rw_uid),ARRAY_A)?:[];
$p9rw_top_prods = [];
foreach(array_slice($p9rw_myprods_raw,0,10) as $pp) { $prod=wc_get_product(absint($pp['ID'])); if(!$prod) continue; $p9rw_top_prods[]=['name'=>$prod->get_name(),'sales'=>absint($prod->get_total_sales()),'price'=>wp_strip_all_tags(wc_price($prod->get_price()))]; }
usort($p9rw_top_prods, fn($a,$b)=>$b['sales']-$a['sales']);
$p9rw_cust_pts=[];
if(!empty($p9rw_myprods_raw)&&$p9rw_tbl_exists){
    $pids=array_map(fn($r)=>absint($r['ID']),$p9rw_myprods_raw);
    $ph=implode(',',array_fill(0,count($pids),'%d'));
    $oi=esc_sql($wpdb->prefix.'woocommerce_order_items');$oim=esc_sql($wpdb->prefix.'woocommerce_order_itemmeta');
    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$oi))){
        $oids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT oi.order_id FROM {$oi} oi INNER JOIN {$oim} oim ON oim.order_item_id=oi.order_item_id AND oim.meta_key='_product_id' WHERE CAST(oim.meta_value AS UNSIGNED) IN ({$ph})",$pids))?:[];
        if(!empty($oids)){$oph=implode(',',array_map('absint',$oids));$p9rw_cust_pts=$wpdb->get_results("SELECT l.user_id,u.display_name,SUM(CASE WHEN l.type='earn' THEN l.points ELSE 0 END) as earned,SUM(CASE WHEN l.type='redeem' THEN ABS(l.points) ELSE 0 END) as redeemed FROM {$p9rw_tbl} l INNER JOIN {$wpdb->users} u ON l.user_id=u.ID WHERE l.source_type='order' AND l.source_id IN ({$oph}) GROUP BY l.user_id ORDER BY earned DESC LIMIT 30",ARRAY_A)?:[];}
    }
}
$p9rw_wds=get_user_meta($p9rw_uid,'portcld9_reward_withdrawals',true);if(!is_array($p9rw_wds))$p9rw_wds=[];
$p9rw_cash=intval($p9rw_s['withdrawal_rate'])>0?round($p9rw_bal/intval($p9rw_s['withdrawal_rate']),2):0;
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
?>

<div class="p9rw-wrap"
     data-role="shop_manager"
     data-ajax="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>"
     data-nonce="<?php echo esc_attr( wp_create_nonce('portcld9_rewards_nonce') ); ?>"
     data-balance="<?php echo absint($p9rw_bal); ?>"
     data-wd-rate="<?php echo absint($p9rw_s['withdrawal_rate']); ?>"
     data-wd-min="<?php echo absint($p9rw_s['min_withdrawal']); ?>">
  <div class="p9rw-role-pill mgr">🏪 Seller Rewards</div>

  <div class="p9rw-wallet mgr">
    <div class="p9rw-wallet-glow"></div>
    <div class="p9rw-wallet-top">
      <div><div class="p9rw-wallet-label">Seller Points Balance</div><div class="p9rw-wallet-num"><?php echo number_format($p9rw_bal); ?></div></div>
      <div class="p9rw-wallet-coin">🏅</div>
    </div>
    <div class="p9rw-wallet-bottom">
      <div class="p9rw-wallet-meta"><span>Cash Value</span><strong>$<?php echo number_format($p9rw_cash,2); ?></strong></div>
      <div class="p9rw-wallet-meta"><span>Per Item Sold</span><strong>+<?php echo absint($p9rw_s['manager_points_per_sale']); ?> pts</strong></div>
    </div>
  </div>

  <div class="p9rw-mini-stats">
    <div class="p9rw-ms-card violet" title="Your earned points balance"><?php echo '<span>'.number_format($p9rw_bal).'</span><label>Points</label>'; ?></div>
    <div class="p9rw-ms-card amber"  title="How much cash you can withdraw"><?php echo '<span>$'.number_format($p9rw_cash,2).'</span><label>Withdraw</label>'; ?></div>
    <div class="p9rw-ms-card emerald" title="Customers rewarded on your products"><?php echo '<span>'.count($p9rw_cust_pts).'</span><label>Customers</label>'; ?></div>
    <div class="p9rw-ms-card sky" title="Your published products"><?php echo '<span>'.count($p9rw_myprods_raw).'</span><label>Products</label>'; ?></div>
  </div>

  <div class="p9rw-tabs">
    <button class="p9rw-tab active" data-panel="p9rw-withdraw"  title="Request a cash payout from your points">💸 Withdraw</button>
    <button class="p9rw-tab"        data-panel="p9rw-customers" title="Customers who bought your products">👥 Customers</button>
    <button class="p9rw-tab"        data-panel="p9rw-products"  title="Your products by sales">📦 Products</button>
    <button class="p9rw-tab"        data-panel="p9rw-history"   title="Your points transaction history">📋 History</button>
  </div>

  <div id="p9rw-withdraw" class="p9rw-panel active">
    <?php if($p9rw_bal<intval($p9rw_s['min_withdrawal'])): ?>
      <div class="p9rw-locked-card">
        <div class="p9rw-locked-icon">🔒</div>
        <div class="p9rw-locked-title">Withdrawal Locked</div>
        <div class="p9rw-locked-sub">Need <strong><?php echo number_format(absint($p9rw_s['min_withdrawal'])); ?></strong> pts minimum. Keep selling!</div>
        <div class="p9rw-locked-progress"><div class="p9rw-locked-bar" style="width:<?php echo absint(min(100,round($p9rw_bal/max(1,absint($p9rw_s['min_withdrawal']))*100))); ?>%"></div></div>
        <div class="p9rw-locked-prog-lbl"><?php echo number_format($p9rw_bal); ?> / <?php echo number_format(absint($p9rw_s['min_withdrawal'])); ?> pts</div>
      </div>
    <?php else: ?>
      <div class="p9rw-form-card">
        <div class="p9rw-form-title">💸 Request Cash Withdrawal</div>
        <div class="p9rw-form-grid">
          <div class="p9rw-sf" title="How many of your points to convert to cash">
            <label class="p9rw-sf-lbl">Points to Withdraw</label>
            <input type="number" id="p9rw-wd-pts" class="p9rw-field" min="<?php echo absint($p9rw_s['min_withdrawal']); ?>" max="<?php echo absint($p9rw_bal); ?>" value="<?php echo absint($p9rw_s['min_withdrawal']); ?>">
            <div class="p9rw-live-preview" id="p9rw-wd-preview">Cash value: $<?php echo number_format(absint($p9rw_s['min_withdrawal'])/max(1,intval($p9rw_s['withdrawal_rate'])),2); ?></div>
          </div>
          <div class="p9rw-sf" title="How you want to receive payment">
            <label class="p9rw-sf-lbl">Withdrawal Method</label>
            <select id="p9rw-wd-method" class="p9rw-field"><option value="bank">Bank Transfer</option><option value="mpesa">M-Pesa</option><option value="paypal">PayPal</option><option value="other">Other</option></select>
          </div>
          <div class="p9rw-sf p9rw-sf-full" title="Your payment account details">
            <label class="p9rw-sf-lbl">Account Details</label>
            <textarea id="p9rw-wd-details" class="p9rw-field" rows="2" placeholder="Bank account, M-Pesa number, PayPal email…"></textarea>
          </div>
        </div>
        <button class="p9rw-btn violet w100" id="p9rw-wd-submit" title="Submit for admin review — points held until approved">💸 Request Withdrawal</button>
      </div>
    <?php endif; ?>
    <?php if(!empty($p9rw_wds)): ?>
      <div class="p9rw-section-head" style="margin-top:20px;">📋 My Requests</div>
      <?php foreach($p9rw_wds as $wd): $st=esc_html($wd['status']??'pending'); ?>
        <div class="p9rw-wd-card">
          <div class="p9rw-wd-top"><span class="p9rw-pill <?php echo esc_attr($st); ?>"><?php echo esc_html( strtoupper($st) ); ?></span><span style="font-size:11px;color:#64748b;"><?php echo esc_html($wd['created_at']??''); ?></span><span class="p9rw-wd-amount"><?php echo wp_kses_post( wc_price(floatval($wd['cash']??0)) ); ?></span></div>
          <div class="p9rw-wd-meta"><?php echo absint($wd['points']??0); ?> pts &bull; <?php echo esc_html(strtoupper($wd['method']??'')); ?></div>
          <?php if(!empty($wd['reason'])) echo '<div style="font-size:11px;color:#f43f5e;margin-top:4px;">Rejected: '.esc_html($wd['reason']).'</div>'; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div id="p9rw-customers" class="p9rw-panel">
    <?php if(empty($p9rw_cust_pts)): echo '<div class="p9rw-empty"><div class="p9rw-empty-icon">👥</div><p>No customer reward data yet.</p></div>';
    else: ?>
      <div class="p9rw-acard-grid">
        <?php foreach($p9rw_cust_pts as $c): $hue=(crc32($c['display_name'])%360+360)%360; ?>
          <div class="p9rw-acard compact" title="<?php echo esc_attr($c['display_name']); ?>">
            <div class="p9rw-acard-top">
              <div class="p9rw-acard-av" style="--av-hue:<?php echo absint($hue); ?>"><?php echo esc_html(mb_strtoupper(mb_substr($c['display_name'],0,1))); ?></div>
              <div class="p9rw-acard-meta"><div class="p9rw-acard-name"><?php echo esc_html($c['display_name']); ?></div><div class="p9rw-acard-email"><?php echo absint($c['earned']); ?> earned &bull; <?php echo absint($c['redeemed']); ?> redeemed</div></div>
              <div class="p9rw-acard-balance"><div class="p9rw-acard-bal-num"><?php echo number_format(absint($c['earned'])); ?></div><div class="p9rw-acard-bal-lbl">pts earned</div></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div id="p9rw-products" class="p9rw-panel">
    <?php if(empty($p9rw_top_prods)): echo '<div class="p9rw-empty"><div class="p9rw-empty-icon">📦</div><p>No published products yet.</p></div>';
    else: ?>
      <div class="p9rw-leaderboard">
        <?php foreach($p9rw_top_prods as $i=>$pp): $medal=['gold','silver','bronze'][$i]??''; ?>
          <div class="p9rw-lb-row <?php echo esc_attr($medal); ?>" title="<?php echo esc_attr($pp['name']); ?>">
            <div class="p9rw-lb-rank"><?php echo ($i<3) ? esc_html(['🥇','🥈','🥉'][$i]) : absint($i+1); ?></div>
            <div class="p9rw-lb-info"><div class="p9rw-lb-name"><?php echo esc_html($pp['name']); ?></div><div style="font-size:11px;color:var(--p9-muted);"><?php echo wp_kses_post($pp['price']); ?></div></div>
            <div class="p9rw-lb-bar"><div class="p9rw-lb-fill" style="width:<?php echo absint(min(100,absint($pp['sales'])/max(1,absint($p9rw_top_prods[0]['sales']))*100)); ?>%"></div></div>
            <div class="p9rw-lb-val"><?php echo absint($pp['sales']); ?> sold</div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div id="p9rw-history" class="p9rw-panel">
    <?php p9rw_render_log($p9rw_mylog); ?>
  </div>
</div>

<?php
/* ================================================================ */
else:
/* ── CUSTOMER ─────────────────────────────────────────────────── */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
$p9rw_custlog = $p9rw_tbl_exists ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p9rw_tbl} WHERE user_id=%d ORDER BY created_at DESC LIMIT 30",$p9rw_uid),ARRAY_A)?:[] : [];
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
$p9rw_rate   = intval($p9rw_s['redemption_rate']);
$p9rw_cpnval = ($p9rw_rate>0 && $p9rw_bal>=intval($p9rw_s['min_redemption'])) ? round($p9rw_bal/$p9rw_rate,2) : 0;
$p9rw_pct    = min(100, round($p9rw_bal/max(1,absint($p9rw_s['min_redemption']))*100));
?>

<div class="p9rw-wrap"
     data-role="customer"
     data-ajax="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>"
     data-nonce="<?php echo esc_attr( wp_create_nonce('portcld9_rewards_nonce') ); ?>"
     data-balance="<?php echo absint($p9rw_bal); ?>"
     data-redeem-rate="<?php echo absint($p9rw_s['redemption_rate']); ?>"
     data-redeem-min="<?php echo absint($p9rw_s['min_redemption']); ?>">
  <div class="p9rw-role-pill">🎁 Your Reward Points</div>

  <div class="p9rw-wallet cust">
    <div class="p9rw-wallet-glow cust"></div>
    <div class="p9rw-wallet-top">
      <div><div class="p9rw-wallet-label">Points Balance</div><div class="p9rw-wallet-num" id="p9rw-cust-bal-num"><?php echo number_format($p9rw_bal); ?></div></div>
      <div class="p9rw-wallet-coin">⭐</div>
    </div>
    <div class="p9rw-wallet-bottom">
      <div class="p9rw-wallet-meta"><span>Coupon Value</span><strong>$<?php echo number_format($p9rw_cpnval,2); ?></strong></div>
      <div class="p9rw-wallet-meta"><span>Earn Rate</span><strong><?php echo absint($p9rw_s['points_per_dollar']); ?> pts / $1</strong></div>
    </div>
    <div class="p9rw-wallet-progress">
      <div class="p9rw-wp-track"><div class="p9rw-wp-fill" id="p9rw-prog-fill" style="width:<?php echo absint($p9rw_pct); ?>%"></div></div>
      <div class="p9rw-wp-lbl" id="p9rw-prog-lbl">
        <?php if($p9rw_bal >= intval($p9rw_s['min_redemption'])): ?>
          ✅ Ready to redeem!
        <?php else: $needed = max(0, absint($p9rw_s['min_redemption']) - $p9rw_bal); ?>
          <?php echo number_format($needed); ?> more points to unlock redemption
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="p9rw-mini-stats">
    <div class="p9rw-ms-card sky"    title="Points available to use"><?php echo '<span>'.number_format($p9rw_bal).'</span><label>Available</label>'; ?></div>
    <div class="p9rw-ms-card amber"  title="Coupon value if you redeem all points"><?php echo '<span>$'.number_format($p9rw_cpnval,2).'</span><label>Coupon Value</label>'; ?></div>
    <div class="p9rw-ms-card emerald" title="Total transactions on your account"><?php echo '<span>'.count($p9rw_custlog).'</span><label>Transactions</label>'; ?></div>
    <div class="p9rw-ms-card violet" title="Minimum points to generate a coupon"><?php echo '<span>'.number_format(absint($p9rw_s['min_redemption'])).'</span><label>Min Redeem</label>'; ?></div>
  </div>

  <div class="p9rw-tabs">
    <button class="p9rw-tab active" data-panel="p9rw-redeem"  title="Convert your points into a coupon discount code">💳 Redeem Points</button>
    <button class="p9rw-tab"        data-panel="p9rw-history" title="See how you earned and spent your points">📋 History</button>
  </div>

  <div id="p9rw-redeem" class="p9rw-panel active">
    <?php if($p9rw_bal < intval($p9rw_s['min_redemption'])): ?>
      <div class="p9rw-locked-card">
        <div class="p9rw-locked-icon">🔒</div>
        <div class="p9rw-locked-title">Keep Shopping to Unlock!</div>
        <div class="p9rw-locked-sub">You need <strong><?php echo number_format(absint($p9rw_s['min_redemption'])); ?></strong> points to redeem.<br>You earn <strong><?php echo absint($p9rw_s['points_per_dollar']); ?> pts</strong> per $1 spent.</div>
        <div class="p9rw-locked-progress"><div class="p9rw-locked-bar" style="width:<?php echo absint($p9rw_pct); ?>%"></div></div>
        <div class="p9rw-locked-prog-lbl"><?php echo number_format($p9rw_bal); ?> / <?php echo number_format(absint($p9rw_s['min_redemption'])); ?> pts</div>
      </div>
    <?php else: ?>
      <div class="p9rw-form-card">
        <div class="p9rw-form-title">🎟️ Generate Coupon Code</div>
        <div class="p9rw-redeem-row">
          <div class="p9rw-redeem-pts-wrap">
            <input type="number" id="p9rw-redeem-pts" class="p9rw-field p9rw-pts-input" min="<?php echo absint($p9rw_s['min_redemption']); ?>" max="<?php echo absint($p9rw_bal); ?>" value="<?php echo absint(min(absint($p9rw_bal),absint($p9rw_s['min_redemption']))); ?>" title="Points to convert">
            <span class="p9rw-pts-label">pts</span>
          </div>
          <div class="p9rw-redeem-eq">=</div>
          <div class="p9rw-redeem-value" id="p9rw-redeem-preview">$<?php echo number_format((float)(min(absint($p9rw_bal),absint($p9rw_s['min_redemption']))/max(1,intval($p9rw_s['redemption_rate']))),2); ?> off</div>
        </div>
        <button class="p9rw-btn primary w100" id="p9rw-redeem-btn" title="Create a one-time coupon — valid 30 days, locked to your email">🎟️ Get My Coupon Code</button>
        <div class="p9rw-coupon-reveal" id="p9rw-coupon-result" style="display:none;">
          <div class="p9rw-coupon-label">🎉 Your Coupon Code</div>
          <div class="p9rw-coupon-code-box"><span id="p9rw-coupon-code-text" class="p9rw-coupon-code"></span><button id="p9rw-copy-code" class="p9rw-copy-btn" title="Copy to clipboard">📋</button></div>
          <div class="p9rw-coupon-hint">Valid <?php echo absint($p9rw_s['coupon_expiry_days']); ?> days &bull; Single use &bull; Locked to your email</div>
        </div>
      </div>
      <div class="p9rw-how-to-earn">
        <div class="p9rw-section-head">✨ How to Earn More Points</div>
        <div class="p9rw-earn-grid">
          <div class="p9rw-earn-card" title="Shop and complete orders to earn points">🛒<span>Complete orders</span><em><?php echo absint($p9rw_s['points_per_dollar']); ?> pts per $1</em></div>
          <div class="p9rw-earn-card" title="Points credited when order is marked Completed">✅<span>Order completed</span><em>Points credited</em></div>
          <div class="p9rw-earn-card" title="Redeem points for a discount coupon">🎟️<span>Redeem for coupon</span><em><?php echo absint($p9rw_s['redemption_rate']); ?> pts = $1 off</em></div>
          <div class="p9rw-earn-card" title="Coupons are locked to your email for security">🔐<span>Email-locked</span><em>Secure redemption</em></div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div id="p9rw-history" class="p9rw-panel">
    <?php p9rw_render_log($p9rw_custlog); ?>
  </div>
</div>

<?php endif; ?>

