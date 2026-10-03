<?php
/**
 * Plugin bootstrap.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt;

use Devllo\WPLicenseIt\Database\Installer;
use Devllo\WPLicenseIt\Database\WpdbActivationRepository;
use Devllo\WPLicenseIt\Database\WpdbEventLog;
use Devllo\WPLicenseIt\Database\WpdbLicenseRepository;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseService;

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
	 * Licensing core, built on first use.
	 *
	 * @var LicenseService|null
	 */
	private ?LicenseService $licenses = null;

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
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_install' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * The licensing core. Payment adapters and the API call this.
	 */
	public function licenses(): LicenseService {
		if ( null === $this->licenses ) {
			global $wpdb;

			$this->licenses = new LicenseService(
				new WpdbLicenseRepository( $wpdb ),
				new WpdbActivationRepository( $wpdb ),
				new WpdbEventLog( $wpdb ),
				new KeyGenerator()
			);
		}

		return $this->licenses;
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wplicense-it', false, dirname( plugin_basename( WPLICENSE_IT_FILE ) ) . '/languages' );
	}
}
