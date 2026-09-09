<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Portal Cloud 9 - Desktop Dashboard Template
 * This template is loaded for desktop devices
 * NOTE: CSS is enqueued via wp_enqueue_scripts — do NOT add <link> tags here.
 */

$current_tab = get_query_var('portalcloud9_tab', 'overview');
?>

<!-- Desktop Sidebar -->
<aside class="portalcloud9-sidebar-desktop">
    <header class="portalcloud9-sidebar-header">
        <div class="portalcloud9-user-info">
            <div class="portalcloud9-avatar-ring">
                <?php echo wp_kses_post( get_avatar(get_current_user_id(), 48) ); ?>
            </div>
            <div class="portalcloud9-user-details">
                <h3><?php echo esc_html(wp_get_current_user()->display_name); ?></h3>
                <p><?php echo esc_html(ucfirst(wp_get_current_user()->roles[0] ?? '')); ?></p>
            </div>
        </div>
    </header>
    
    <nav class="portalcloud9-sidebar-nav">
        <ul class="portalcloud9-nav-list">
            <?php 
            $tabs = PortalCloud9_Config::get_user_menu_items();
            $current = get_query_var('portalcloud9_tab', 'overview');
            
            foreach ($tabs as $key => $tab):
                // Logout redirects to homepage, other tabs go to their pages
                $url = ($key === 'logout') 
                    ? wp_logout_url(home_url('/')) 
                    : home_url('/user-portal/' . $key . '/');
                
                $active = ($current === $key) ? 'active' : '';
            ?>
            <li class="portalcloud9-nav-item <?php echo esc_attr($active); ?>">
                <a class="portalcloud9-nav-link" href="<?php echo esc_url($url); ?>">
                    <span class="portalcloud9-nav-icon">
                        <?php echo portcld9_esc_icon( $tab['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </span>
                    <span class="portalcloud9-nav-label">
                        <?php echo esc_html($tab['label']); ?>
                    </span>
                    <?php if (!empty($tab['has_counter'])): ?>
                        <span class="portalcloud9-counter">
                            <span class="portalcloud9-counter-badge">0</span>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>

<!-- Main Content Area -->
<main class="portalcloud9-main-content-desktop">
    <div class="portalcloud9-topbar-desktop">
        <!-- SVG Gradient Definition -->
        <svg width="0" height="0" style="position: absolute;">
            <defs>
                <linearGradient id="icon-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#008CFF;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#000000;stop-opacity:1" />
                </linearGradient>
            </defs>
        </svg>
        
        <div class="portalcloud9-topbar-left">
            <h1 class="portalcloud9-page-title">
                <span class="portalcloud9-page-icon">
                    <svg fill="none" stroke="url(#icon-gradient)" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </span>
                <?php echo esc_html($tabs[$current]['label'] ?? 'Dashboard'); ?>
            </h1>
        </div>
        
        <div class="portalcloud9-topbar-actions">
            <a class="portalcloud9-notification-bubble" href="<?php echo esc_url(home_url('/user-portal/inbox/')); ?>" id="portalcloud9-message-bubble">
                <span class="portalcloud9-bubble-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </span>
                <span class="portalcloud9-bubble-count">0</span>
            </a>
            
            <div class="portalcloud9-user-menu">
                <?php echo wp_kses_post( get_avatar(get_current_user_id(), 32) ); ?>
            </div>
        </div>
    </div>
    
    <div class="portalcloud9-content">
        <?php
        // Show redirect notice if user was bounced from a disabled/forbidden tab
        include PORTALCLOUD9_PLUGIN_PATH . 'templates/partials/tab-notice.php';

        $tab = get_query_var('portalcloud9_tab', 'overview');
        $template = PORTALCLOUD9_PLUGIN_PATH . 'templates/tabs/' . $tab . '.php';
        
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="portalcloud9-error">Tab not found.</div>';
        }
        ?>
    </div>
</main>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>
