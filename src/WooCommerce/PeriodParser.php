<?php
/**
 * License period parsing.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use DateInterval;
use InvalidArgumentException;

/**
 * Turns a period setting ("lifetime", "P1Y", "P6M", ...) into a DateInterval.
 */
final class PeriodParser {

	public const LIFETIME = 'lifetime';

	/**
	 * Parses a period.
	 *
	 * @param string $period "lifetime", or an ISO 8601 duration of years, months and days such as P1Y or P6M.
	 * @return DateInterval|null Null for a lifetime license.
	 * @throws InvalidArgumentException If the period is not valid. A bad period must never silently become a lifetime license.
	 */
	public static function parse( string $period ): ?DateInterval {
		$period = trim( $period );

		if ( self::LIFETIME === strtolower( $period ) ) {
			return null;
		}

		if ( 1 !== preg_match( '/^P(?:(\d{1,3})Y)?(?:(\d{1,3})M)?(?:(\d{1,4})D)?$/', $period, $parts ) || 'P' === $period ) {
			throw new InvalidArgumentException( "Invalid license period '{$period}'." );
		}

		$years  = (int) ( $parts[1] ?? 0 );
		$months = (int) ( $parts[2] ?? 0 );
		$days   = (int) ( $parts[3] ?? 0 );

		if ( 0 === $years + $months + $days ) {
			throw new InvalidArgumentException( "Invalid license period '{$period}'." );
		}

		return new DateInterval( $period );
	}
}
