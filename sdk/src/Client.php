<?php
/**
 * Client for one licensed product.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

use InvalidArgumentException;

/**
 * Ties the pieces together for one plugin or theme, and keeps a registry of every product on the site
 * that uses the client (several plugins can each embed it).
 */
final class Client {

	/**
	 * Registered clients by slug.
	 *
	 * @var array<string, Client>
	 */
	private static $clients = array();

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * License manager.
	 *
	 * @var LicenseManager
	 */
	private $manager;

	/**
	 * License page.
	 *
	 * @var LicensePage
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param Config         $config  Configuration.
	 * @param LicenseManager $manager License manager.
	 * @param LicensePage    $page    License page.
	 */
	private function __construct( Config $config, LicenseManager $manager, LicensePage $page ) {
		$this->config  = $config;
		$this->manager = $manager;
		$this->page    = $page;
	}

	/**
	 * Registers a product and hooks it into WordPress.
	 *
	 * @param array<string, mixed> $args Settings: server, product_id, name, version and file (plugins) or type and slug (themes).
	 * @return Client|null Null if the settings are invalid (a notice is shown to administrators).
	 */
	public static function register( array $args ): ?Client {
		try {
			$config = Config::from_array( $args );
		} catch ( InvalidArgumentException $e ) {
			self::report_misconfiguration( $e->getMessage() );

			return null;
		}

		if ( isset( self::$clients[ $config->slug ] ) ) {
			return self::$clients[ $config->slug ];
		}

		$store   = new OptionStore( $config );
		$api     = new ServerApi( $config, new WpTransport() );
		$manager = new LicenseManager( $api, $store, home_url() );
		$client  = new self( $config, $manager, new LicensePage( $config, $manager ) );

		self::$clients[ $config->slug ] = $client;

		$client->hook( new Updater( $config, new UpdateService( $api, $store, $config->server ), $manager ) );

		return $client;
	}

	/**
	 * The client of a product, by slug.
	 *
	 * @param string $slug Plugin folder name or theme folder name.
	 */
	public static function get( string $slug ): ?Client {
		return self::$clients[ $slug ] ?? null;
	}

	/**
	 * Whether the product's license is active right now.
	 */
	public function is_active(): bool {
		return $this->manager->is_active();
	}

	/**
	 * What the site knows about its license.
	 */
	public function license(): LicenseState {
		return $this->manager->state();
	}

	/**
	 * Activates a key on this site.
	 *
	 * @param string $key License key.
	 */
	public function activate( string $key ): ApiResult {
		return $this->manager->activate( $key );
	}

	/**
	 * Deactivates this site.
	 */
	public function deactivate(): ApiResult {
		return $this->manager->deactivate();
	}

	/**
	 * Prints the license form, for plugins that want it inside their own settings page.
	 */
	public function render_license_form(): void {
		$this->page->render_form();
	}

	/**
	 * Forgets the license, the cached data and the daily check (on every site of a network). See wplicense_it_client_forget().
	 */
	public function forget(): void {
		if ( function_exists( 'wplicense_it_client_forget' ) ) {
			wplicense_it_client_forget( $this->config->slug );

			return;
		}

		( new OptionStore( $this->config ) )->clear();
		$this->unschedule_check();
	}

	/**
	 * Has WordPress clean up when the plugin is deleted, for plugins that have no uninstall.php of their own (WordPress
	 * runs that file instead, so such a plugin calls wplicense_it_client_forget() there). Only on admin requests, where
	 * plugins are deleted; WordPress writes the setting only when it changes.
	 */
	public function register_cleanup(): void {
		$file = $this->config->file;

		if ( 'plugin' !== $this->config->type || '' === $file || '' === $this->config->basename || ! is_admin() || ! function_exists( 'register_uninstall_hook' ) || ! function_exists( 'wplicense_it_client_uninstall_hook' ) ) {
			return;
		}

		if ( file_exists( dirname( $file ) . '/uninstall.php' ) ) {
			return;
		}

		register_uninstall_hook( $file, 'wplicense_it_client_uninstall_hook' );

		// The slug can differ from the folder name, so remember which slug belongs to the plugin file.
		$map = get_option( 'wplit_cu_map', array() );
		$map = is_array( $map ) ? $map : array();

		if ( ( $map[ $this->config->basename ] ?? null ) !== $this->config->slug ) {
			$map[ $this->config->basename ] = $this->config->slug;
			update_option( 'wplit_cu_map', $map, false );
		}
	}

	/**
	 * The slug of this product.
	 */
	public function slug(): string {
		return $this->config->slug;
	}

	/**
	 * Registers the WordPress hooks.
	 *
	 * @param Updater $updater Updater.
	 */
	private function hook( Updater $updater ): void {
		$updater->register();
		$this->page->register();

		$hook = 'wplit_client_refresh_' . $this->config->slug;

		add_action( $hook, array( $this, 'daily_check' ) );
		add_action( 'init', array( $this, 'schedule_check' ) );
		add_action( 'admin_init', array( $this, 'register_cleanup' ) );

		if ( 'plugin' === $this->config->type && '' !== $this->config->file ) {
			register_deactivation_hook( $this->config->file, array( $this, 'unschedule_check' ) );
		}
	}

	/**
	 * Schedules the daily license check once there is a license to check.
	 */
	public function schedule_check(): void {
		$hook = 'wplit_client_refresh_' . $this->config->slug;

		if ( '' !== $this->manager->state()->key && ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
		}
	}

	/**
	 * Removes the daily check, when the plugin is deactivated.
	 */
	public function unschedule_check(): void {
		wp_clear_scheduled_hook( 'wplit_client_refresh_' . $this->config->slug );
	}

	/**
	 * Daily: checks the license with the server.
	 */
	public function daily_check(): void {
		$this->manager->refresh();
	}

	/**
	 * Tells administrators that the client was set up wrongly, once per request, without breaking the site.
	 *
	 * @param string $message What is wrong.
	 */
	private static function report_misconfiguration( string $message ): void {
		add_action(
			'admin_notices',
			static function () use ( $message ): void {
				if ( current_user_can( 'manage_options' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
				}
			}
		);
	}
}
