<?php if(!defined('ABSPATH')){exit;}

class PortalCloud9_Config{

    private static $user_roles=[];
    private static $menu_items=[];
    private static $initialized=false;

    public function __construct(){
        add_action('init',[$this,'late_init'],1);
    }

    public function late_init(){
        if(self::$initialized){
            return;
        }
        $this->init_user_roles();
        $this->init_menu_items();
        self::$initialized=true;
    }

    private function init_user_roles(){

        self::$user_roles=[

            'administrator'=>[
                'can_manage_products'=>true,
                'can_add_products'=>true,
                'can_delete_products'=>true,
                'can_edit_all_products'=>true,
                'can_manage_orders'=>true,
                'menu_items'=>[
                    'overview',
                    'visitor-analytics',
                    'orders',
                    'products',
                    'add-product',
                    'phone-contacts',
                    'inbox',
                    'rewards',
                    'account',
                    'logout'
                ],
                'admin_email'=>get_option('admin_email',''),
            ],

            'editor'=>[
                'can_manage_products'=>true,
                'can_add_products'=>true,
                'can_delete_products'=>true,
                'can_edit_all_products'=>true,
                'can_manage_orders'=>true,
                'menu_items'=>[
                    'overview',
                    'orders',
                    'products',
                    'add-product',
                    'inbox',
                    'account',
                    'logout'
                ],
                'admin_email'=>get_option('admin_email',''),
            ],

            'author'=>[
                'can_manage_products'=>true,
                'can_add_products'=>true,
                'can_delete_products'=>false,
                'can_edit_all_products'=>false,
                'can_manage_orders'=>true,
                'menu_items'=>[
                    'overview',
                    'orders',
                    'products',
                    'add-product',
                    'inbox',
                    'account',
                    'logout'
                ],
                'admin_email'=>get_option('admin_email',''),
            ],

            'contributor'=>[
                'can_manage_products'=>false,
                'can_add_products'=>false,
                'can_delete_products'=>false,
                'can_edit_all_products'=>false,
                'can_manage_orders'=>false,
                'menu_items'=>[
                    'overview',
                    'orders',
                    'inbox',
                    'account',
                    'logout'
                ],
                'admin_email'=>get_option('admin_email',''),
            ],

            'customer'=>[
                'can_manage_products'=>false,
                'can_add_products'=>false,
                'can_delete_products'=>false,
                'can_edit_all_products'=>false,
                'can_manage_orders'=>false,
                'menu_items'=>[
                    'overview',
                    'orders',
                    'cart',
                    'favourites',
                    'inbox',
                    'rewards',
                    'account',
                    'logout'
                ],
                'admin_email'=>get_option('admin_email',''),
            ],

            'shop_manager'=>[
                'can_manage_products'=>true,
                'can_add_products'=>true,
                'can_delete_products'=>true,
                'can_edit_all_products'=>false,
                'can_manage_orders'=>true,
                'menu_items'=>[
                    'overview',
                    'orders',
                    'products',
                    'add-product',
                    'phone-contacts',
                    'inbox',
                    'rewards',
                    'account',
                    'logout'
                ],
                'admin_email'=>get_option('admin_email',''),
            ],

            'subscriber'=>[
                'can_manage_products'=>false,
                'can_add_products'=>false,
                'can_delete_products'=>false,
                'can_edit_all_products'=>false,
                'can_manage_orders'=>false,
                'menu_items'=>[
                    'overview',
                    'orders',
                    'cart',
                    'favourites',
                    'inbox',
                    'account',
                    'logout'
                ],
                'admin_email'=>get_option('admin_email',''),
            ],

        ];
    }


    private function init_menu_items(){

        self::$menu_items=[

            'overview'=>[
                'label'=>'Overview',
                'icon'=>'📊',
                'template'=>'overview.php',
                'capability'=>'read',
            ],

            'orders'=>[
                'label'=>'Orders',
                'icon'=>'🧾',
                'template'=>'orders.php',
                'capability'=>'read',
                'has_order_counter'=>true
            ],

            'products'=>[
                'label'=>'Products',
                'icon'=>'🛍',
                'template'=>'products.php',
                'capability'=>'edit_products',
            ],

            'add-product'=>[
                'label'=>'Add New Product',
                'icon'=>'➕',
                'template'=>'add-product.php',
                'capability'=>'edit_products',
            ],

            'phone-contacts'=>[
                'label'=>'Contacted by Phone',
                'icon'=>'📞',
                'template'=>'phone-contacts.php',
                'capability'=>'manage_woocommerce',
                'has_counter'=>false,
                'requires_phone_contacts'=>true,
            ],

            'visitor-analytics'=>[
                'label'=>'Visitor Analytics',
                'icon'=>'📈',
                'template'=>'visitor-analytics.php',
                'capability'=>'manage_options',
            ],

            'cart'=>[
                'label'=>'Cart',
                'icon'=>'🛒',
                'template'=>'cart.php',
                'capability'=>'read',
            ],

            'favourites'=>[
                'label'=>'Favourites',
                'icon'=>'❤️',
                'template'=>'favourites.php',
                'capability'=>'read',
            ],

            'inbox'=>[
                'label'=>'Inbox',
                'icon'=>'<svg width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M3 4h18v12h-5l-2 3h-4l-2-3H3V4zm2 2v8h4.2l2 3l2-3H19V6H5z"/></svg>',
                'template'=>'inbox.php',
                'capability'=>'read',
                'has_counter'=>true,
                'requires_messaging'=>true,
            ],

            'rewards'=>[
                'label'=>'Rewards',
                'icon'=>'🎁',
                'template'=>'rewards.php',
                'capability'=>'read',
            ],

            'account'=>[
                'label'=>'Account',
                'icon'=>'👤',
                'template'=>'account.php',
                'capability'=>'read',
            ],

            'logout'=>[
                'label'=>'Logout',
                'icon'=>'🚪',
                'template'=>false,
                'capability'=>'read',
                'action'=>'logout',
            ],

        ];
    }


    public static function get_user_capabilities($user=null){
        if(!self::$initialized){
            $instance=new self();
            $instance->late_init();
        }
        if(!$user){
            $user=wp_get_current_user();
        }
        $role=self::get_primary_role($user);
        return self::$user_roles[$role]?? self::get_default_caps();
    }

    /**
     * Returns the full menu item registry — no user/feature filtering.
     * Used to resolve labels when redirecting from a disabled/forbidden tab.
     * @return array
     */
    public static function get_all_menu_items(){
        return self::$menu_items;
    }

    public static function get_user_menu_items($user=null){
        $caps=self::get_user_capabilities($user);
        $out=[];
        
        // Check if messaging is enabled
        $messaging_enabled = self::is_messaging_enabled();

        // Check if Contacted by Phone is enabled
        $phone_contacts_enabled = self::is_phone_contacts_enabled();
        
        foreach($caps['menu_items']as $key){
            if(isset(self::$menu_items[$key])){
                $item=self::$menu_items[$key];
                
                // Skip inbox if messaging is disabled
                if(!empty($item['requires_messaging']) && !$messaging_enabled){
                    continue;
                }

                // Skip phone-contacts if the feature is disabled
                if(!empty($item['requires_phone_contacts']) && !$phone_contacts_enabled){
                    continue;
                }
                
                if(!empty($item['capability'])&&!current_user_can($item['capability'])){
                    continue;
                }
                $out[$key]=$item;
            }
        }
        return $out;
    }

    /**
     * Check if messaging feature is enabled
     * @return bool
     */
    public static function is_messaging_enabled(){
        return (bool) self::get_option('enable_messaging', 1);
    }

    /**
     * Check if Contacted by Phone feature is enabled
     * @return bool
     */
    public static function is_phone_contacts_enabled(){
        return (bool) self::get_option('enable_phone_contacts', 1);
    }

    public static function user_can_manage_products($user=null){
        $caps=self::get_user_capabilities($user);
        return !empty($caps['can_manage_products']);
    }

    public static function user_can_add_products($user=null){
        $caps=self::get_user_capabilities($user);
        return !empty($caps['can_add_products']);
    }

    public static function user_can_delete_products($user=null){
        $caps=self::get_user_capabilities($user);
        return !empty($caps['can_delete_products']);
    }

    private static function get_primary_role($user){
        if(empty($user->roles)||!is_array($user->roles)){
            return 'subscriber';
        }
        $priority=['administrator','shop_manager','editor','author','contributor','customer','subscriber'];
        foreach($priority as $r){
            if(in_array($r,$user->roles,true)){
                return $r;
            }
        }
        return $user->roles[0];
    }

    private static function get_default_caps(){
        return[
            'can_manage_products'=>false,
            'can_add_products'=>false,
            'can_delete_products'=>false,
            'can_edit_all_products'=>false,
            'can_manage_orders'=>false,
            'menu_items'=>['overview','account','logout'],
            'admin_email'=>'',
        ];
    }

    /**
     * Get products per page from settings.
     */
    public static function get_products_per_page(){
        return (int) self::get_option( 'products_per_page', 15 );
    }

    /**
     * Get orders per page from settings.
     * @param string $role User role (customer|manager)
     */
    public static function get_orders_per_page( $role = 'customer' ){
        $option_key = ( $role === 'customer' ) ? 'orders_per_page_customer' : 'orders_per_page_manager';
        $default    = ( $role === 'customer' ) ? 10 : 15;
        return (int) self::get_option( $option_key, $default );
    }

    public static function get_dashboard_tab_url($tab){
        return home_url('/user-portal/'.$tab.'/');
    }

    public static function get_option($key,$default=null){
        $opts=wp_parse_args(
            get_option('portalcloud9_options',[]),
            [
                'products_per_page'=>15,
                'orders_per_page_manager'=>15,
                'orders_per_page_customer'=>10,
                'enable_messaging'=>true,
                'enable_product_inquiry'=>true,
                'enable_phone_contacts'=>true,
                'email_notifications'=>true,
                'admin_email'=>get_option('admin_email',''),
            ]
        );
        return $opts[$key]?? $default;
    }
}
