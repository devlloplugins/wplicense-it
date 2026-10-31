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

// Exit if accessed directly.
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

register_activation_hook( __FILE__, array( \Devllo\WPLicenseIt\Activation::class, 'activate' ) );

\Devllo\WPLicenseIt\Plugin::instance()->boot();
