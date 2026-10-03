<?php
/**
 * Stand-ins for the WordPress transient functions, for unit tests.
 *
 * @package Devllo\WPLicenseIt
 */

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Transient store used by the stubs.
	 *
	 * @return array<string, mixed>
	 */
	function &wplit_test_transients(): array {
		static $store = array();

		return $store;
	}

	function get_transient( string $name ) {
		$store = &wplit_test_transients();

		return $store[ $name ] ?? false;
	}

	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		$store          = &wplit_test_transients();
		$store[ $name ] = $value;

		return true;
	}
}
