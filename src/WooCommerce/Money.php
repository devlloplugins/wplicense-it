<?php
/**
 * Money conversion.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * Converts decimal amounts to minor units (cents) using each currency's own number of decimals.
 */
final class Money {

	private const ZERO_DECIMAL  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
	private const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Converts an amount such as "29.99" to minor units.
	 *
	 * @param string|float|int $amount   Amount in the currency's major unit.
	 * @param string           $currency ISO 4217 code.
	 */
	public static function to_minor( $amount, string $currency ): int {
		$currency = strtoupper( $currency );

		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			$factor = 1;
		} elseif ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			$factor = 1000;
		} else {
			$factor = 100;
		}

		return (int) round( (float) $amount * $factor );
	}

	/**
	 * Converts minor units back to a plain decimal string, for example 4900 and USD to "49.00".
	 *
	 * @param int    $minor    Amount in minor units, from 0.
	 * @param string $currency ISO 4217 code.
	 */
	public static function from_minor( int $minor, string $currency ): string {
		$currency = strtoupper( $currency );

		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return (string) $minor;
		}

		$decimals = in_array( $currency, self::THREE_DECIMAL, true ) ? 3 : 2;
		$factor   = 10 ** $decimals;

		return intdiv( $minor, $factor ) . '.' . str_pad( (string) ( $minor % $factor ), $decimals, '0', STR_PAD_LEFT );
	}
}
