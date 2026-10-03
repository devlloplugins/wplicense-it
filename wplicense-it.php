<?php
/*
    Plugin Name: WPLicense It
    Plugin URI: https://wplicenseit.com/
    Description: WordPress Plugin and Theme Licensing plugin
    Author: Devllo Plugins
    Version: 2.0.0-dev
    Requires at least: 6.2
    Requires PHP: 8.0
    License: GPLv2 or later
    License URI: https://www.gnu.org/licenses/gpl-2.0.html
    Author URI: http://devlloplugins.com/
    Text Domain: wplicense-it
    Domain Path: /languages
*/

// Exit if accessed directly

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

define( 'WPLICENSE_IT_VERSION', '2.0.0-dev' );
define( 'WPLICENSE_IT_FILE', __FILE__ );
define( 'WPLICENSE_IT_DIR', plugin_dir_path( __FILE__ ) );

// Stop early on unsupported PHP or WordPress versions (kept PHP 5.x-safe on purpose).
global $wp_version;
if ( version_compare( PHP_VERSION, '8.0', '<' ) || version_compare( $wp_version, '6.2', '<' ) ) {
    add_action( 'admin_notices', 'wplicense_it_requirements_notice' );
    if ( ! function_exists( 'wplicense_it_requirements_notice' ) ) {
        function wplicense_it_requirements_notice() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'WPLicense It 2.0 requires PHP 8.0 or higher and WordPress 6.2 or higher. The plugin has not been loaded.', 'wplicense-it' );
            echo '</p></div>';
        }
    }
    return;
}

require_once WPLICENSE_IT_DIR . 'src/Autoloader.php';
\Devllo\WPLicenseIt\Autoloader::register( WPLICENSE_IT_DIR . 'src/' );

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
        // The 1.x API answers the old /api/... URLs until the 1.x data is migrated; the 2.0 core takes over after.
        if ( ! \Devllo\WPLicenseIt\Plugin::legacy_data_migrated() ) {
            include_once( 'includes/wplicense-it-api.php');
        }

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

\Devllo\WPLicenseIt\Plugin::instance()->boot();
