<?php
/**
 * The WooCommerce product that sells a license product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * Finds the product a customer buys to renew: used by the Renew button in My Account and by renewal invoices.
 */
final class RenewalProduct {

	/**
	 * A simple, purchasable WooCommerce product that sells the license product.
	 *
	 * Variable products are not used here: the customer renews from the product page.
	 *
	 * @param int $license_product_id WPLicense It product ID.
	 * @return int WooCommerce product ID, 0 if none.
	 */
	public static function find( int $license_product_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'meta_key'       => OrderReader::META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of products.
				'meta_value'     => (string) $license_product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( $product && $product->is_type( 'simple' ) && $product->is_purchasable() ) {
				return (int) $id;
			}
		}

		return 0;
	}
}
