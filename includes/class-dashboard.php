<?php
/**
 * Dashboard Controller Class
 * 
 * @package Portal_Cloud_9
 */

defined('ABSPATH') || exit;

/**
 * Main Dashboard Controller
 */
class PortalCloud9_Dashboard
{
    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    /**
     * Initialize dashboard
     */
    public function init()
    {
        $this->setup_dashboard_hooks();
    }
    
    /**
     * Setup dashboard hooks
     */
    private function setup_dashboard_hooks()
    {
        add_action('wp_ajax_portalcloud9_dashboard_action', [$this, 'handle_dashboard_action']);
    }
    
    /**
     * Enqueue dashboard scripts
     */
    public function enqueue_scripts()
    {
        if (get_query_var('portalcloud9_dashboard')) {
            // Dashboard-specific scripts would go here
        }
    }
    
    /**
     * Handle AJAX dashboard action
     */
    public function handle_dashboard_action()
    {
        check_ajax_referer('portalcloud9_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized');
        }
        
        wp_send_json_success();
    }
    
    /**
     * Get current dashboard tab
     *
     * @return string Current tab name
     */
    public static function get_current_tab()
    {
        return get_query_var('portalcloud9_tab', 'overview');
    }
    
    /**
     * Get dashboard URL
     *
     * @return string Dashboard URL
     */
    public static function get_dashboard_url()
    {
        return home_url('/user-portal/');
    }
    
    /**
     * Get tab URL
     *
     * @param string $tab Tab name
     * @return string Tab URL
     */
    public static function get_tab_url($tab)
    {
        return home_url('/user-portal/' . $tab . '/');
    }
}
