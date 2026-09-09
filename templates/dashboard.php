<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
/**
 * Portal Cloud 9 - Main Dashboard Template
 *
 * @package Portal Cloud 9
 * @version 8.3.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

defined( 'PORTALCLOUD9_PLUGIN_PATH' ) || define( 'PORTALCLOUD9_PLUGIN_PATH', plugin_dir_path( dirname( __DIR__ ) ) );
defined( 'PORTALCLOUD9_PLUGIN_URL' )  || define( 'PORTALCLOUD9_PLUGIN_URL',  plugin_dir_url( dirname( __DIR__ ) ) );
defined( 'PORTALCLOUD9_VERSION' )     || define( 'PORTALCLOUD9_VERSION',     '8.0.6' );

$is_mobile   = wp_is_mobile();
$current_tab = get_query_var( 'portalcloud9_tab', 'overview' );

/**
 * INBOX on DESKTOP → output a self-contained HTML document.
 *
 * We deliberately do NOT call get_header() or get_footer().
 * wp_head() / wp_footer() are still called so every enqueued
 * asset loads normally.
 * The admin bar is suppressed so it cannot inject top-margin.
 */
if ( ! $is_mobile && $current_tab === 'inbox' ) :

    add_filter( 'show_admin_bar', '__return_false' );
    add_filter( 'body_class', function ( $c ) {
        $c[] = 'p9-inbox-fullscreen';
        return $c;
    } );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php wp_title( '|', true, 'right' ); ?><?php bloginfo( 'name' ); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php if ( function_exists( 'wp_body_open' ) ) wp_body_open(); ?>
<div class="portalcloud9-dashboard-wrapper">
<?php include PORTALCLOUD9_PLUGIN_PATH . 'templates/desktop-dashboard.php'; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php

else :
    /**
     * ALL OTHER TABS → normal theme header + footer.
     */
    get_header();
?>
<div class="portalcloud9-dashboard-wrapper">
<?php
    if ( $is_mobile ) :
        include PORTALCLOUD9_PLUGIN_PATH . 'templates/mobile-dashboard.php';
    else :
        include PORTALCLOUD9_PLUGIN_PATH . 'templates/desktop-dashboard.php';
    endif;
?>
</div>
<?php
    get_footer();

endif;
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
