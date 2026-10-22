<?php
/**
 * The WooCommerce product that sells a license product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Database\Schema;
use Devllo\WPLicenseIt\Licenses\License;
use WC_Order;
use WC_Product;

/**
 * Finds the product a customer buys to renew: used by the Renew button in My Account, the cart and renewal invoices.
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

	/**
	 * The plan a license renews with: the WooCommerce product it was bought with, at that plan's price. If that plan is
	 * no longer sold, a plan of the same product with the same number of sites (and period, when known). 0 when none
	 * fits, so the license cannot be renewed in the shop (rather than at another plan's price and sites).
	 *
	 * @param License $license License.
	 * @return int WooCommerce product ID, 0 if none.
	 */
	public static function for_license( License $license ): int {
		$reader = new OrderReader();
		$bought = self::bought_with( $license );
		$period = '';

		if ( null !== $bought ) {
			[ $product_id, $period ] = $bought;

			if ( self::sells( $product_id, $license->product_id ) ) {
				return $product_id;
			}
		}

		foreach ( self::plans( $license->product_id ) as $id ) {
			$terms = $reader->current_terms( wc_get_product( $id ), 0 );

			if ( $terms['limit'] === $license->activation_limit && ( '' === $period || $terms['period'] === $period ) ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * The WooCommerce product and period of the order line the license was issued on, if it is still there.
	 *
	 * @param License $license License.
	 * @return array{0:int, 1:string}|null
	 */
	private static function bought_with( License $license ): ?array {
		global $wpdb;

		if ( null === $license->order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$table    = Schema::table( $wpdb->prefix, 'license_orders' );
		$external = $wpdb->get_var( $wpdb->prepare( "SELECT external_id FROM {$table} WHERE id = %d AND source = 'woocommerce'", $license->order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name is built from the prefix; one row.
		$order    = null === $external ? null : wc_get_order( (int) $external );

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && in_array( $license->id, WooItemLicenseStore::ids( $item ), true ) ) {
				$id = $item->get_variation_id() > 0 ? (int) $item->get_variation_id() : (int) $item->get_product_id();

				return array( $id, (string) $item->get_meta( OrderReader::META_PERIOD, true ) );
			}
		}

		return null;
	}

	/**
	 * Whether a WooCommerce product is a published, purchasable plan (simple product) of the license product.
	 *
	 * @param int $woo_product_id     WooCommerce product ID.
	 * @param int $license_product_id WPLicense It product ID.
	 */
	public static function sells( int $woo_product_id, int $license_product_id ): bool {
		$product = $woo_product_id > 0 ? wc_get_product( $woo_product_id ) : null;

		return $product instanceof WC_Product
			&& $product->is_type( 'simple' )
			&& 'publish' === $product->get_status()
			&& $product->is_purchasable()
			&& (int) $product->get_meta( OrderReader::META_PRODUCT, true ) === $license_product_id;
	}

	/**
	 * The published plans of a license product, oldest first.
	 *
	 * @param int $license_product_id WPLicense It product ID.
	 * @return array<int, int>
	 */
	private static function plans( int $license_product_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => OrderReader::META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of products.
				'meta_value'     => (string) $license_product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => self::sells( $id, $license_product_id ) ) );
	}
}
