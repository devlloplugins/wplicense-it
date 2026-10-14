<?php
/**
 * Rate limiter contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Slows down clients that keep sending bad license keys.
 */
interface RateLimiter {

	/**
	 * Seconds the client must wait before trying again, or 0 if it may proceed.
	 *
	 * @param string $client Client identifier, normally the IP address.
	 */
	public function blocked_for( string $client ): int;

	/**
	 * Records a failed attempt.
	 *
	 * @param string $client Client identifier.
	 */
	public function record_failure( string $client ): void;
}
