<?php
/**
 * Renewal invoice settings.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Renewals;

/**
 * Reads the renewal invoice options. Off until the site owner turns it on, because it creates orders and emails customers.
 */
final class RenewalSettings {

	public const OPTION_ENABLED = 'wplit_invoices_enabled';
	public const OPTION_DAYS    = 'wplit_invoice_days';

	public const DEFAULT_DAYS = 14;
	public const MAX_DAYS     = 90;

	/**
	 * Whether renewal invoices are on.
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * How many days before expiry the invoice is created and sent.
	 */
	public static function days(): int {
		return self::clamp_days( get_option( self::OPTION_DAYS, self::DEFAULT_DAYS ) );
	}

	/**
	 * Keeps the days between 1 and 90, with the default for anything that is not a number.
	 *
	 * @param mixed $value Typed value.
	 */
	public static function clamp_days( $value ): int {
		return is_numeric( $value ) ? max( 1, min( self::MAX_DAYS, (int) $value ) ) : self::DEFAULT_DAYS;
	}
}
