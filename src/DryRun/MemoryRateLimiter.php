<?php
/**
 * In-memory rate limiter.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\DryRun;

use Devllo\WPLicenseIt\Api\RateLimiter;

/**
 * Blocks a client after a number of failures. Nothing is stored outside the object.
 */
final class MemoryRateLimiter implements RateLimiter {

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

	/**
	 * {@inheritdoc}
	 */
	public function blocked_for( string $client ): int {
		return ( $this->failures[ $client ] ?? 0 ) >= $this->max ? 60 : 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function record_failure( string $client ): void {
		$this->failures[ $client ] = ( $this->failures[ $client ] ?? 0 ) + 1;
	}
}
