<?php
/**
 * Date input conversion.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts between the date an administrator types (in the site's time zone) and the UTC time stored.
 *
 * A license entered as "expires on 2026-12-31" is valid through the end of that day on the site.
 */
final class DateInput {

	/**
	 * End of the given day in the site's time zone, as UTC.
	 *
	 * @param string       $date Date as Y-m-d.
	 * @param DateTimeZone $zone Site time zone.
	 * @return DateTimeImmutable|null Null if the input is not a real date.
	 */
	public static function end_of_day_utc( string $date, DateTimeZone $zone ): ?DateTimeImmutable {
		$date = trim( $date );

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return null;
		}

		return ( new DateTimeImmutable( $date . ' 23:59:59', $zone ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The date to show in a date field: the stored UTC time, in the site's time zone.
	 *
	 * @param DateTimeImmutable|null $utc  Stored expiry.
	 * @param DateTimeZone           $zone Site time zone.
	 */
	public static function to_field( ?DateTimeImmutable $utc, DateTimeZone $zone ): string {
		return null === $utc ? '' : $utc->setTimezone( $zone )->format( 'Y-m-d' );
	}
}
