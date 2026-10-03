<?php
/**
 * License terms for a purchased product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * Works out the activation limit and period for a WooCommerce product or variation.
 *
 * The most specific setting wins: variation, then WooCommerce parent product, then the
 * WPLicense It product's own defaults, then the built-in defaults (1 site, lifetime).
 */
final class LicenseTerms {

	public const DEFAULT_LIMIT  = 1;
	public const DEFAULT_PERIOD = PeriodParser::LIFETIME;

	/**
	 * Resolves the activation limit. An empty string means "not set here, ask the next level".
	 *
	 * @param string $variation        Variation setting.
	 * @param string $parent           WooCommerce product setting.
	 * @param string $product_default  WPLicense It product default.
	 * @return int Activation limit, 0 for unlimited.
	 */
	public static function activation_limit( string $variation, string $parent, string $product_default ): int {
		foreach ( array( $variation, $parent, $product_default ) as $value ) {
			if ( '' !== trim( $value ) && is_numeric( $value ) ) {
				return max( 0, (int) $value );
			}
		}

		return self::DEFAULT_LIMIT;
	}

	/**
	 * Resolves the license period. An empty string means "not set here, ask the next level".
	 *
	 * @param string $variation        Variation setting.
	 * @param string $parent           WooCommerce product setting.
	 * @param string $product_default  WPLicense It product default (wplit_period meta).
	 * @return string "lifetime" or an ISO 8601 duration.
	 */
	public static function period( string $variation, string $parent, string $product_default ): string {
		foreach ( array( $variation, $parent, $product_default ) as $value ) {
			if ( '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return self::DEFAULT_PERIOD;
	}
}
