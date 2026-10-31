<?php
/**
 * Storage in WordPress options and transients.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Keeps the license state in an option (not autoloaded) and cached data in transients.
 */
final class OptionStore implements Store {

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * {@inheritDoc}
	 */
	public function load(): LicenseState {
		return LicenseState::from_array( get_option( $this->config->option_name(), array() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param LicenseState $state State.
	 */
	public function save( LicenseState $state ): void {
		update_option( $this->config->option_name(), $state->to_array(), false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear(): void {
		delete_option( $this->config->option_name() );
		$this->cache_delete( 'update' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Cache name.
	 */
	public function cache_get( string $name ) {
		$value = get_transient( $this->transient( $name ) );

		return false === $value ? null : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name  Cache name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 */
	public function cache_set( string $name, $value, int $ttl ): void {
		set_transient( $this->transient( $name ), $value, $ttl );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Cache name.
	 */
	public function cache_delete( string $name ): void {
		delete_transient( $this->transient( $name ) );
	}

	/**
	 * Transient name (kept short, transient names are limited to 172 characters).
	 *
	 * @param string $name Cache name.
	 */
	private function transient( string $name ): string {
		return substr( 'wplit_c_' . $this->config->slug . '_' . $name, 0, 160 );
	}
}
