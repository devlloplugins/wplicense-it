<?php
/**
 * WPLicense It client SDK: license activation and automatic updates for your plugin or theme.
 *
 * Copy this folder into your plugin or theme, then:
 *
 *     require_once __DIR__ . '/sdk/wplicense-it-client.php';
 *
 *     wplicense_it_client( array(
 *         'file'       => __FILE__,                    // Plugins: the main plugin file.
 *         'server'     => 'https://your-shop.com',     // The site that runs WPLicense It.
 *         'product_id' => 7,                           // The product's ID there.
 *         'name'       => 'My Plugin',
 *         'version'    => '1.2.0',                     // Your installed version.
 *     ) );
 *
 * Themes: pass 'type' => 'theme' and 'slug' => 'my-theme' instead of 'file'.
 *
 * If several plugins on a site include this SDK, only the newest copy is loaded.
 * Requires PHP 7.4 or newer and WordPress 5.8 or newer.
 *
 * @package WPLicenseIt\Client
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wplicense_it_client' ) ) {

	/**
	 * Registers a plugin or theme with the client. Safe to call at any time while your plugin loads.
	 *
	 * @param array $args See the file header.
	 */
	function wplicense_it_client( array $args ) {
		$GLOBALS['wplicense_it_client_queue'][] = $args;

		// If the SDK is already loaded (this call came late), register right away.
		if ( did_action( 'wplicense_it_client_loaded' ) && class_exists( '\WPLicenseIt\Client\Client' ) ) {
			wplicense_it_client_flush_queue();
		}
	}

	/**
	 * Whether a product's license is active. Returns false if the product is not registered.
	 *
	 * @param string $slug Plugin folder name or theme folder name.
	 * @return bool
	 */
	function wplicense_it_license_active( $slug ) {
		$client = class_exists( '\WPLicenseIt\Client\Client' ) ? \WPLicenseIt\Client\Client::get( (string) $slug ) : null;

		return null !== $client && $client->is_active();
	}

	/**
	 * Registers the version of this copy of the SDK.
	 *
	 * @param string $version Version.
	 * @param string $dir     The src directory of this copy.
	 */
	function wplicense_it_client_register_version( $version, $dir ) {
		$GLOBALS['wplicense_it_client_versions'][ $version ] = $dir;
	}

	/**
	 * Loads the newest registered copy, once.
	 */
	function wplicense_it_client_load() {
		if ( did_action( 'wplicense_it_client_loaded' ) || empty( $GLOBALS['wplicense_it_client_versions'] ) ) {
			return;
		}

		$versions = array_keys( $GLOBALS['wplicense_it_client_versions'] );
		usort( $versions, 'version_compare' );
		$dir = $GLOBALS['wplicense_it_client_versions'][ end( $versions ) ];

		spl_autoload_register(
			static function ( $class_name ) use ( $dir ) {
				$prefix = 'WPLicenseIt\\Client\\';

				if ( 0 === strpos( $class_name, $prefix ) ) {
					$file = $dir . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

					if ( is_file( $file ) ) {
						require_once $file;
					}
				}
			}
		);

		/**
		 * Fires when the client SDK has been loaded.
		 */
		do_action( 'wplicense_it_client_loaded' );

		wplicense_it_client_flush_queue();
	}

	/**
	 * Registers every queued product.
	 */
	function wplicense_it_client_flush_queue() {
		$queue = isset( $GLOBALS['wplicense_it_client_queue'] ) ? $GLOBALS['wplicense_it_client_queue'] : array();

		$GLOBALS['wplicense_it_client_queue'] = array();

		foreach ( $queue as $args ) {
			\WPLicenseIt\Client\Client::register( $args );
		}
	}

	// Load once all plugins (and, for themes, the theme) have had the chance to register their copy.
	add_action( 'after_setup_theme', 'wplicense_it_client_load', 0 );

	// For code that includes the SDK after that point (for example from an admin-only hook).
	if ( did_action( 'after_setup_theme' ) ) {
		wplicense_it_client_load();
	}
}

wplicense_it_client_register_version( '1.0.0', __DIR__ . '/src' );

// A late copy of the SDK (included after loading finished) cannot replace the loaded one, but a late call still works.
if ( did_action( 'wplicense_it_client_loaded' ) ) {
	wplicense_it_client_flush_queue();
}
