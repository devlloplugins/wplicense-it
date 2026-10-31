<?php
/**
 * UTC date conversion for the database.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Database;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts between database DATETIME strings and DateTimeImmutable. All values are UTC.
 */
final class Dates {

	public const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Formats a date for the database.
	 *
	 * @param DateTimeImmutable|null $date Date.
	 */
	public static function to_db( ?DateTimeImmutable $date ): ?string {
		return null === $date ? null : $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::FORMAT );
	}

	/**
	 * Parses a database value.
	 *
	 * @param string|null $value DATETIME string, or null.
	 */
	public static function from_db( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}

		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
