<?php
/*
    Plugin Name: WPLicense It
    Plugin URI: https://wplicenseit.com/
    Description: WordPress Plugin and Theme Licensing plugin
    Author: Devllo Plugins
    Version: 1.0.1
    Author URI: http://devlloplugins.com/
    Text Domain: wplicense-it
    Domain Path: /languages
*/

// Exit if accessed directly

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

if ( function_exists( 'wplicense_it_pro' ) ) {
    deactivate_plugins('wplicense-it-pro/wplicense-it.php');
} 

/**
 * Current plugin version.
 */

if ( ! class_exists( 'WPLicense_It' ) ) {

class WPLicense_It {

	private static $instance;
    public $_session = null;

    public static function instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof WPLicense_It ) ) {
			self::$instance = new WPLicense_It;

		}

		return self::$instance;
	}

    /**
     * Constructor
     */

    public function __construct(){
        register_activation_hook( __FILE__, array( 'WP_License_It_Activator', 'activate' ));

        $this->define_constants();
		$this->includes();
        $this->init_hooks();

        // Admin Files
        include_once( 'admin/wplicense-it-product-admin.php');
        include_once( 'admin/wplicense-it-product-post.php'); 
        include_once( 'admin/wplicense-it-admin-menu.php'); 

        // Include Files
        include_once( 'includes/wplicense-it-protect-file.php'); 
        include_once( 'includes/wplicense-it-activator.php');
        include_once( 'includes/wplicense-it-api.php'); 

        // Pages Files
        include_once( 'includes/pages/wplit-render-product.php'); 
        include_once( 'includes/pages/view-licenses.php'); 
        include_once( 'includes/pages/payment-checkout.php'); 

        // Email
        include_once( 'includes/emails/wplicense-it-email.php'); 

    }


    public function includes(){

    }

    public function define_constants(){
          // Plugin Root File.
		if ( ! defined( 'WPLIT_PLUGIN_FILE' ) ) {
			define( 'WPLIT_PLUGIN_FILE', __FILE__ );
		}

        define( 'WPLIT_URI', plugin_dir_url( __FILE__ ) );
        define( 'WPLIT_DIR', dirname(__FILE__) );

        define( 'WPLIT_ADMIN_URI', WPLIT_URI . 'admin/' );
        define( 'WPLIT_INCLUDES_URI', WPLIT_URI . 'includes/' );

        define( 'WPLIT_ADMIN_DIR', WPLIT_DIR . '/admin' );
        define( 'WPLIT_INCLUDES_DIR', WPLIT_DIR . '/includes' );

    }

    public function init_hooks(){
    }
    
}
}

if ( ! function_exists( 'wplicense_it' ) ) {
	function wplicense_it() {
		return WPLicense_It::instance();
	}
}


wplicense_it();