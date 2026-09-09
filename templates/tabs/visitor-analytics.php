<?php
/**
 * Portal Cloud 9 – Visitor Analytics Tab Template
 * templates/tabs/visitor-analytics.php
 */

defined( 'ABSPATH' ) || exit;

// Guard – only administrators may reach this template
if ( ! current_user_can( 'manage_options' ) ) {
    echo '<div class="p9-access-denied">⛔ Access denied.</div>';
    return;
}
?>

<div class="p9-va-wrap" id="p9-va-wrap">

    <!-- ── Page header ──────────────────────────────────────── -->
    <div class="p9-va-header">
        <div class="p9-va-header-icon">📈</div>
        <div>
            <h2>Visitor Analytics</h2>
            <p>Unique visitors tracked across your entire website</p>
        </div>
        <span class="p9-va-online-badge">
            <span class="p9-va-online-dot"></span>
            <span id="p9-va-val-online">—</span> online now
        </span>
    </div>

    <!-- ── Stat cards ───────────────────────────────────────── -->
    <div class="p9-va-cards">
        <div class="p9-va-card">
            <div class="p9-va-card-icon today">🌅</div>
            <div class="p9-va-card-label">Today</div>
            <div class="p9-va-card-value" id="p9-va-val-today">—</div>
            <div class="p9-va-card-sub">Unique visitors today</div>
        </div>
        <div class="p9-va-card">
            <div class="p9-va-card-icon week">📅</div>
            <div class="p9-va-card-label">Last 7 Days</div>
            <div class="p9-va-card-value" id="p9-va-val-week">—</div>
            <div class="p9-va-card-sub">Unique visitors past week</div>
        </div>
        <div class="p9-va-card">
            <div class="p9-va-card-icon month">🗓️</div>
            <div class="p9-va-card-label">Last 30 Days</div>
            <div class="p9-va-card-value" id="p9-va-val-month">—</div>
            <div class="p9-va-card-sub">Unique visitors past month</div>
        </div>
        <div class="p9-va-card">
            <div class="p9-va-card-icon year">📆</div>
            <div class="p9-va-card-label">Last 365 Days</div>
            <div class="p9-va-card-value" id="p9-va-val-year">—</div>
            <div class="p9-va-card-sub">Unique visitors past year</div>
        </div>
    </div>

</div>
