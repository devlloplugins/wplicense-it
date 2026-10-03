<?php
/**
 * Plugin bootstrap.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt;

/**
 * Wires the plugin into WordPress.
 *
 * The 2.0 rewrite grows here. Until each 1.x feature is replaced, the legacy
 * code in admin/ and includes/ is still loaded from the main plugin file.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wplicense-it', false, dirname( plugin_basename( WPLICENSE_IT_FILE ) ) . '/languages' );
	}
}
