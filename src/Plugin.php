<?php
/**
 * Plugin bootstrap.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt;

use Devllo\WPLicenseIt\Api\ApiService;
use Devllo\WPLicenseIt\Api\DownloadSigner;
use Devllo\WPLicenseIt\Api\LegacyApi;
use Devllo\WPLicenseIt\Api\LegacyEndpoint;
use Devllo\WPLicenseIt\Api\RestController;
use Devllo\WPLicenseIt\Api\TransientRateLimiter;
use Devllo\WPLicenseIt\Api\WpProductCatalog;
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
	 * Shared rate limiter for the REST API and the legacy endpoint.
	 *
	 * @var TransientRateLimiter|null
	 */
	private ?TransientRateLimiter $limiter = null;

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
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( self::legacy_data_migrated() ) {
			$this->legacy_endpoint()->register();
		}
	}

	/**
	 * Whether the 1.x data has been migrated, which switches the deprecated
	 * /api/wplicense-it-api/ URLs from the 1.x code to the 2.0 core.
	 */
	public static function legacy_data_migrated(): bool {
		return version_compare( (string) get_option( 'wplit_db_version', '0' ), '2.0.0', '>=' );
	}

	/**
	 * Registers /wp-json/wplicense-it/v1/.
	 */
	public function register_rest_routes(): void {
		$api = new ApiService(
			$this->licenses(),
			new WpProductCatalog(),
			new DownloadSigner( wp_salt( 'auth' ) ),
			$this->limiter(),
			static function ( string $token ): string {
				return add_query_arg( 'token', rawurlencode( $token ), rest_url( RestController::ROUTE_NAMESPACE . '/download' ) );
			}
		);

		( new RestController( $api ) )->register_routes();
	}

	/**
	 * The deprecated 1.x endpoint, backed by the 2.0 core.
	 */
	private function legacy_endpoint(): LegacyEndpoint {
		return new LegacyEndpoint(
			new LegacyApi(
				$this->licenses(),
				new WpProductCatalog(),
				$this->limiter(),
				static function ( int $product_id, string $api_key, string $email, string $license_key ): string {
					return home_url( '/api/wplicense-it-api/v1/get?p=' . $product_id . '&k=' . rawurlencode( $api_key ) . '&e=' . rawurlencode( $email ) . '&l=' . rawurlencode( $license_key ) );
				}
			)
		);
	}

	/**
	 * Shared rate limiter.
	 */
	private function limiter(): TransientRateLimiter {
		if ( null === $this->limiter ) {
			$this->limiter = new TransientRateLimiter();
		}

		return $this->limiter;
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
