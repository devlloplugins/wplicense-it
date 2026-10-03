<?php
/**
 * In-memory rate limiter for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Api\RateLimiter;

/**
 * Blocks a client after a number of failures.
 */
final class InMemoryRateLimiter implements RateLimiter {

	/**
	 * Failures per client.
	 *
	 * @var array<string, int>
	 */
	public array $failures = array();

	/**
	 * Constructor.
	 *
	 * @param int $max Failures allowed.
	 */
	public function __construct( private int $max = 3 ) {
	}

	public function blocked_for( string $client ): int {
		return ( $this->failures[ $client ] ?? 0 ) >= $this->max ? 60 : 0;
	}

	public function record_failure( string $client ): void {
		$this->failures[ $client ] = ( $this->failures[ $client ] ?? 0 ) + 1;
	}
}
