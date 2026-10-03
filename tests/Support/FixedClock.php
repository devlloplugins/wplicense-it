<?php
/**
 * Adjustable clock for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that only moves when told to.
 */
final class FixedClock {

	/**
	 * Current time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Constructor.
	 *
	 * @param string $start Start time, UTC.
	 */
	public function __construct( string $start = '2026-01-01 00:00:00' ) {
		$this->now = new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) );
	}

	public function __invoke(): DateTimeImmutable {
		return $this->now;
	}

	/**
	 * Moves the clock.
	 *
	 * @param string $modifier A DateTime modifier such as "+1 month".
	 */
	public function advance( string $modifier ): void {
		$this->now = $this->now->modify( $modifier );
	}
}
