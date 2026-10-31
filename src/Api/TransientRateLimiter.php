<?php
/**
 * Rate limiter using transients.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Allows a limited number of failures per client in a time window.
 *
 * Uses transients, so it uses the object cache when there is one.
 */
final class TransientRateLimiter implements RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param int $max_failures Failures allowed in the window.
	 * @param int $window       Window length in seconds.
	 */
	public function __construct(
		private int $max_failures = 20,
		private int $window = 900
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $client Client identifier.
	 */
	public function blocked_for( string $client ): int {
		$state = $this->state( $client );

		if ( $state['count'] < $this->max_failures ) {
			return 0;
		}

		return max( 1, $state['reset'] - time() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $client Client identifier.
	 */
	public function record_failure( string $client ): void {
		$state = $this->state( $client );

		if ( 0 === $state['count'] ) {
			$state['reset'] = time() + $this->window;
		}

		++$state['count'];

		set_transient( $this->key( $client ), $state, max( 1, $state['reset'] - time() ) );
	}

	/**
	 * Current failure count and reset time.
	 *
	 * @param string $client Client identifier.
	 * @return array{count:int,reset:int}
	 */
	private function state( string $client ): array {
		$state = get_transient( $this->key( $client ) );

		if ( is_array( $state ) && isset( $state['count'], $state['reset'] ) && $state['reset'] > time() ) {
			return array(
				'count' => (int) $state['count'],
				'reset' => (int) $state['reset'],
			);
		}

		return array(
			'count' => 0,
			'reset' => 0,
		);
	}

	/**
	 * Transient name. Hashed, so the IP address is not stored in plain text.
	 *
	 * @param string $client Client identifier.
	 */
	private function key( string $client ): string {
		return 'wplit_rl_' . md5( $client );
	}
}
