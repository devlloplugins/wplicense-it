<?php
/**
 * Product meta conversion.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

/**
 * Adds the 2.0 product meta next to the 1.x meta. The 1.x meta is left untouched.
 *
 * - wplit_product_price ("29.99")                          becomes wplit_price_minor (2999)
 * - wplit_expire / wplit_expire_time ("yes", "1-month")    becomes wplit_period ("P1M", "P1Y" or "lifetime")
 * - wplit_currency is set to USD, which is all 1.x supported
 */
final class WpProductMetaMigrator {

	/**
	 * Period codes used by 1.x.
	 */
	private const PERIODS = array(
		'1-month' => 'P1M',
		'1-year'  => 'P1Y',
	);

	/**
	 * Constructor.
	 *
	 * @param LegacyConverter $converter Conversion rules.
	 */
	public function __construct( private LegacyConverter $converter ) {
	}

	/**
	 * Converts every product.
	 *
	 * @return int Number of products converted.
	 */
	public function migrate(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;

			update_post_meta( $id, 'wplit_price_minor', $this->converter->to_minor_units( get_post_meta( $id, 'wplit_product_price', true ) ) );
			update_post_meta( $id, 'wplit_currency', 'USD' );
			update_post_meta( $id, 'wplit_period', self::period( (string) get_post_meta( $id, 'wplit_expire', true ), (string) get_post_meta( $id, 'wplit_expire_time', true ) ) );
		}

		return count( $ids );
	}

	/**
	 * Converts the 1.x expiry settings to a period.
	 *
	 * @param string $expire      wplit_expire value ("yes" or empty).
	 * @param string $expire_time wplit_expire_time value ("1-month" or "1-year").
	 */
	public static function period( string $expire, string $expire_time ): string {
		if ( 'yes' !== $expire ) {
			return 'lifetime';
		}

		return self::PERIODS[ $expire_time ] ?? 'lifetime';
	}
}
