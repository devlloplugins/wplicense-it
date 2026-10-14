<?php
/**
 * Storage contract.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Where a site keeps its license state and cached update information.
 */
interface Store {

	/**
	 * The stored license state, or an empty one.
	 */
	public function load(): LicenseState;

	/**
	 * Saves the license state.
	 *
	 * @param LicenseState $state State.
	 */
	public function save( LicenseState $state ): void;

	/**
	 * Forgets the license state and cached data.
	 */
	public function clear(): void;

	/**
	 * Reads a cached value.
	 *
	 * @param string $name Cache name.
	 * @return mixed Value, or null if missing or expired.
	 */
	public function cache_get( string $name );

	/**
	 * Stores a value for a while.
	 *
	 * @param string $name  Cache name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 */
	public function cache_set( string $name, $value, int $ttl ): void;

	/**
	 * Forgets a cached value.
	 *
	 * @param string $name Cache name.
	 */
	public function cache_delete( string $name ): void;
}
