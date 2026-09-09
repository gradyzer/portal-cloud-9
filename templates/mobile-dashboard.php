<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Portal Cloud 9 - Mobile Dashboard Template
 * This template is loaded for mobile devices
 * NOTE: CSS is enqueued via wp_enqueue_scripts — do NOT add <link> tags here.
 */
?>

<!-- SVG Gradient Definition for Icons -->
<svg width="0" height="0" style="position: absolute;">
    <defs>
        <linearGradient id="mobile-icon-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#1E90FF;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#000000;stop-opacity:1" />
        </linearGradient>
    </defs>
</svg>

<!-- Mobile Top Bar -->
<header class="portalcloud9-mobile-topbar" id="portalcloud9-mobile-topbar">
    <div class="portalcloud9-topbar-left">
        <button id="portalcloud9-mobile-toggle" aria-label="Open menu" class="portalcloud9-mobile-toggle">
            <svg class="p9-toggle-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
        
        <!-- Back Button (Hidden by default, shown when thread is open) -->
        <button id="p9-mobile-thread-back-btn" class="p9-mobile-thread-action-btn p9-back-action" style="display: none;" aria-label="Back to inbox">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
    </div>
    
    <div class="portalcloud9-topbar-center">
        <h1 class="portalcloud9-page-title">
            <span class="portalcloud9-page-icon">
                <?php 
                $current_tab = get_query_var('portalcloud9_tab', 'overview');
                $menu_items = PortalCloud9_Config::get_user_menu_items();
                echo portcld9_esc_icon( isset($menu_items[$current_tab]['icon']) ? $menu_items[$current_tab]['icon'] : '📊' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </span>
            <span class="portalcloud9-page-label">
                <?php 
                echo esc_html(
                    PortalCloud9_Config::get_user_menu_items()[get_query_var('portalcloud9_tab', 'overview')]['label'] ?? 'Dashboard'
                ); 
                ?>
            </span>
        </h1>
        
        <!-- Thread User Info (Hidden by default, shown when thread is open) -->
        <div class="p9-thread-user-info" id="p9-thread-user-info" style="display: none;">
            <div class="p9-thread-avatar-wrapper">
                <div class="p9-thread-avatar-ring"></div>
                <img src="" alt="" class="p9-thread-avatar" id="p9-thread-avatar">
                <div class="p9-thread-status-indicator" id="p9-thread-status-indicator"></div>
            </div>
            <div class="p9-thread-user-details">
                <span class="p9-thread-user-name" id="p9-thread-user-name">Loading...</span>
                <span class="p9-thread-status-text" id="p9-thread-status-text">Offline</span>
            </div>
        </div>
    </div>
    
    <div class="portalcloud9-topbar-right">
        <!-- Delete Button (Hidden by default, shown when thread is open) -->
        <button id="p9-mobile-thread-delete-btn" class="p9-mobile-thread-action-btn p9-delete-action" style="display: none;" aria-label="Delete conversation">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </button>
        
        <?php 
        $unread = (new PortalCloud9_Messaging_Integration())->get_unread_count();
        $bubble = do_shortcode('[portalcloud9_notification_bubble style="pill" show_zero="false"]');
        echo '<div class="portalcloud9-topbar-actions">' . wp_kses_post( $bubble ) . '</div>';
        ?>
        <div class="portalcloud9-topbar-avatar">
            <?php echo wp_kses_post( get_avatar(get_current_user_id(), 36) ); ?>
        </div>
    </div>
</header>

<!-- Mobile Sidebar -->
<aside class="portalcloud9-mobile-sidebar" id="portalcloud9-mobile-sidebar">
    <div class="portalcloud9-mobile-sidebar-header">
        <div class="portalcloud9-user-info">
            <div class="portalcloud9-avatar">
                <?php echo wp_kses_post( get_avatar(get_current_user_id(), 48) ); ?>
            </div>
            <div class="portalcloud9-user-details">
                <h3><?php echo esc_html(wp_get_current_user()->display_name); ?></h3>
                <p><?php echo esc_html(ucfirst(wp_get_current_user()->roles[0] ?? '')); ?></p>
            </div>
        </div>
        
        <button id="portalcloud9-mobile-close" class="portalcloud9-mobile-close" type="button" aria-label="Close menu">
            <svg class="p9-close-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
    
    <nav class="portalcloud9-mobile-nav">
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

<!-- Mobile Overlay -->
<div class="portalcloud9-mobile-overlay" id="portalcloud9-mobile-overlay"></div>

<!-- Main Content -->
<main class="portalcloud9-mobile-main">
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
