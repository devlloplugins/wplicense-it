<?php
/**
 * In-memory storage for the client SDK tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use WPLicenseIt\Client\LicenseState;
use WPLicenseIt\Client\Store;

/**
 * Keeps the state and cache in arrays. The cache ignores expiry, but remembers the requested lifetime.
 */
final class SdkArrayStore implements Store {

	/**
	 * Stored state as an array, like the option.
	 *
	 * @var array<string, mixed>
	 */
	public $data = array();

	/**
	 * Cached values.
	 *
	 * @var array<string, mixed>
	 */
	public $cache = array();

	/**
	 * Lifetimes given to cache_set().
	 *
	 * @var array<string, int>
	 */
	public $ttl = array();

	public function load(): LicenseState {
		return LicenseState::from_array( $this->data );
	}

	public function save( LicenseState $state ): void {
		$this->data = $state->to_array();
	}

	public function clear(): void {
		$this->data  = array();
		$this->cache = array();
	}

	public function cache_get( string $name ) {
		return $this->cache[ $name ] ?? null;
	}

	public function cache_set( string $name, $value, int $ttl ): void {
		$this->cache[ $name ] = $value;
		$this->ttl[ $name ]   = $ttl;
	}

	public function cache_delete( string $name ): void {
		unset( $this->cache[ $name ] );
	}
}
