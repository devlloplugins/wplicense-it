<?php
/**
 * Expiry reminder settings.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Reminders;

/**
 * Reads and cleans the reminder options. Off until the site owner turns it on, so upgrading never emails customers by surprise.
 */
final class ReminderSettings {

	public const OPTION_ENABLED = 'wplit_reminders_enabled';
	public const OPTION_DAYS    = 'wplit_reminder_days';
	public const OPTION_URL     = 'wplit_reminder_url';

	public const DEFAULT_DAYS = '30,7';
	public const MAX_DAYS     = 365;
	public const MAX_COUNT    = 5;

	/**
	 * Whether reminders are on.
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * The days before expiry to send a reminder, largest first.
	 *
	 * @return int[]
	 */
	public static function days(): array {
		return self::parse_days( (string) get_option( self::OPTION_DAYS, self::DEFAULT_DAYS ) );
	}

	/**
	 * Turns "30, 7" into [30, 7]: whole numbers from 1 to 365, no repeats, largest first, at most five. Junk gives the default.
	 *
	 * @param string $text Typed value.
	 * @return int[]
	 */
	public static function parse_days( string $text ): array {
		$days = array();

		$parts = preg_split( '/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( false === $parts ? array() : $parts as $part ) {
			if ( 1 === preg_match( '/^\d{1,4}$/', $part ) && (int) $part >= 1 && (int) $part <= self::MAX_DAYS ) {
				$days[ (int) $part ] = (int) $part;
			}
		}

		rsort( $days );
		$days = array_slice( array_values( $days ), 0, self::MAX_COUNT );

		return array() === $days ? self::parse_days( self::DEFAULT_DAYS ) : $days;
	}

	/**
	 * The setting as the text field shows it.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_days( $value ): string {
		return implode( ', ', self::parse_days( is_string( $value ) ? $value : '' ) );
	}

	/**
	 * The address the email sends customers to. Empty uses the store's My Account licenses page, or the home page.
	 */
	public static function custom_url(): string {
		return (string) get_option( self::OPTION_URL, '' );
	}
}
