<?php
/**
 * Portal Cloud 9 — Uninstall Script
 *
 * Runs when the plugin is deleted from WP Admin > Plugins > Delete.
 * Only executes if the user has enabled "Remove Data on Uninstall"
 * in Settings > Feature Toggles > Data Management.
 *
 * The actual deletion logic lives in portcld9_purge_all_data() which
 * is shared with the instant-delete AJAX action so both paths are
 * identical and always in sync.
 *
 * @package Portal_Cloud_9
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The setting is stored inside the portalcloud9_options array
$portcld9_opts = get_option( 'portalcloud9_options', [] );
if ( empty( $portcld9_opts['remove_data_on_uninstall'] ) ) {
    return; // Toggle is off — preserve all data
}

// Load the shared purge helper and run it
require_once __DIR__ . '/includes/class-purge.php';
PortalCloud9_Purge::run();
