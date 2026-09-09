<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are function-scoped, not global.
/**
 * Portal Cloud 9 — Tab Access Notice
 *
 * Displayed once when a user is redirected away from a disabled or
 * forbidden tab. The message is stored as a short-lived transient
 * keyed to the user ID and deleted immediately after being shown.
 */
defined( 'ABSPATH' ) || exit;

$notice_key = 'portalcloud9_tab_notice_' . get_current_user_id();
$notice_msg = get_transient( $notice_key );

if ( $notice_msg ) :
    delete_transient( $notice_key );
?>
<div class="portalcloud9-tab-notice" id="portalcloud9-tab-notice" role="alert" aria-live="polite">
    <div class="portalcloud9-tab-notice__inner">
        <span class="portalcloud9-tab-notice__icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
        </span>
        <span class="portalcloud9-tab-notice__msg"><?php echo esc_html( $notice_msg ); ?></span>
        <button class="portalcloud9-tab-notice__close" type="button" aria-label="Dismiss">
            <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>
</div>
<?php endif; ?>
