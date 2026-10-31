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
 * An expired license keeps your plugin working (wplicense_it_license_active() stays true) and only stops updates; pass
 * 'expired' => 'stop' to switch your licensed features off when it expires. wplicense_it_license_status() tells
 * 'active', 'expired' and 'inactive' apart.
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
	 * Whether a product's licensed features may run: the license is active, or it has expired and the product was
	 * registered without 'expired' => 'stop' (an expired license keeps the plugin working; only updates stop). Returns
	 * false if the product is not registered.
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
}//end if

// Defined on their own, so that they exist even when an older copy of the SDK was included first.
if ( ! function_exists( 'wplicense_it_client_forget' ) ) {

	/**
	 * Removes everything the client stored for a product: the license, the cached update data and the daily check, on this
	 * site or, on a network, on every site. Plugins without an uninstall.php get this automatically when they are deleted.
	 *
	 * A plugin with its own uninstall.php, or a theme, calls it there after including this file (see the README).
	 * It also frees the site on your server, with one short request per site (a few seconds at most). If the server
	 * cannot be reached nothing breaks, and the slot is freed from the customer's account or by you.
	 *
	 * @param string $slug Plugin folder name, or the slug passed to wplicense_it_client().
	 */
	function wplicense_it_client_forget( $slug ) {
		$slug = trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $slug ) ), '-' );

		if ( '' === $slug ) {
			return;
		}

		$clean = static function () use ( $slug ) {
			$state = get_option( 'wplit_client_' . $slug );

			// Free this site on the license server. Only to an https server (or a local one while developing), as always.
			if ( is_array( $state ) && ! empty( $state['key'] ) && ! empty( $state['site'] ) && ! empty( $state['server'] ) && function_exists( 'wp_remote_post' ) ) {
				$server = rtrim( (string) $state['server'], '/' );
				$host   = (string) wp_parse_url( $server, PHP_URL_HOST );

				if ( 0 === strpos( $server, 'https://' ) || in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || (bool) preg_match( '/\.(test|local|localhost)$/', $host ) ) {
					wp_remote_post(
						$server . '/wp-json/wplicense-it/v1/licenses/deactivate',
						array(
							'timeout' => 3,
							'body'    => array(
								'license_key' => (string) $state['key'],
								'site_url'    => (string) $state['site'],
							),
						)
					);
				}
			}

			delete_option( 'wplit_client_' . $slug );
			delete_transient( substr( 'wplit_c_' . $slug . '_update', 0, 160 ) );
			wp_clear_scheduled_hook( 'wplit_client_refresh_' . $slug );

			$map = get_option( 'wplit_cu_map', array() );
			if ( is_array( $map ) && in_array( $slug, $map, true ) ) {
				$map = array_diff( $map, array( $slug ) );
				array() === $map ? delete_option( 'wplit_cu_map' ) : update_option( 'wplit_cu_map', $map, false );
			}
		};

		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				$clean();
				restore_current_blog();
			}
		} else {
			$clean();
		}
	}
}//end if

if ( ! function_exists( 'wplicense_it_client_uninstall_hook' ) ) {

	/**
	 * WordPress calls this when a plugin that registered it (and has no uninstall.php) is deleted. It runs while WordPress
	 * deletes the plugin, so it uses no SDK classes: they may not be loaded then.
	 */
	function wplicense_it_client_uninstall_hook() {
		$file = substr( (string) current_action(), strlen( 'uninstall_' ) );
		$map  = get_option( 'wplit_cu_map', array() );

		if ( is_array( $map ) && isset( $map[ $file ] ) ) {
			$slug = (string) $map[ $file ];
		} else {
			$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
		}

		wplicense_it_client_forget( $slug );
	}
}

// Its own block: on a site where an older copy of the SDK (without it) loaded first, the block above is skipped.
if ( ! function_exists( 'wplicense_it_license_status' ) ) {
	/**
	 * A product's license in one word: 'active' (updates and features), 'expired' (past its date: no updates) or
	 * 'inactive' (no key, revoked, refunded, deactivated, activated for another address, or the product is not registered).
	 *
	 * @param string $slug Plugin folder name or theme folder name.
	 * @return string
	 */
	function wplicense_it_license_status( $slug ) {
		$client = class_exists( '\WPLicenseIt\Client\Client' ) ? \WPLicenseIt\Client\Client::get( (string) $slug ) : null;

		return null !== $client && method_exists( $client, 'status' ) ? $client->status() : 'inactive';
	}
}

wplicense_it_client_register_version( '1.1.0', __DIR__ . '/src' );

// A late copy of the SDK (included after loading finished) cannot replace the loaded one, but a late call still works.
if ( did_action( 'wplicense_it_client_loaded' ) ) {
	wplicense_it_client_flush_queue();
}
