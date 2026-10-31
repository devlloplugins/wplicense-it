<?php
/**
 * Reads WooCommerce orders.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Turns a WC_Order into OrderData.
 *
 * The licensing terms are read from the order line when it carries a snapshot taken at
 * checkout (see CartRenewal::snapshot_line), so changing a product later does not change
 * what past customers were sold. Orders created without checkout, for example by an
 * administrator, use the product's current settings.
 */
final class OrderReader {

	public const META_PRODUCT = '_wplit_product_id';
	public const META_LIMIT   = '_wplit_activation_limit';
	public const META_PERIOD  = '_wplit_period';
	public const META_RENEW   = '_wplit_renew_license_id';

	/**
	 * Reads an order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function read( WC_Order $order ): OrderData {
		$items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$terms = $this->terms( $item );
			$renew = (int) $item->get_meta( self::META_RENEW, true );

			$items[] = new OrderItemData(
				(int) $item_id,
				$item->get_name(),
				max( 0, (int) $item->get_quantity() ),
				$terms['product'],
				$terms['limit'],
				$terms['period'],
				$renew > 0 ? $renew : null,
				WooItemLicenseStore::ids( $item ),
				abs( (int) $order->get_qty_refunded_for_item( (int) $item_id ) )
			);
		}

		$user_id  = (int) $order->get_user_id();
		$currency = (string) $order->get_currency();

		return new OrderData(
			(int) $order->get_id(),
			(string) $order->get_order_number(),
			$user_id > 0 ? $user_id : null,
			(string) $order->get_billing_email(),
			$currency,
			Money::to_minor( $order->get_total( 'edit' ), $currency ),
			$items
		);
	}

	/**
	 * Licensing terms for a product (and optional variation), from current settings.
	 *
	 * @param WC_Product|false|null $product   Product or variation.
	 * @param int                   $parent_id Parent product ID for variations, else 0.
	 * @return array{product:int,limit:int,period:string}
	 */
	public function current_terms( $product, int $parent_id = 0 ): array {
		if ( ! $product instanceof WC_Product ) {
			return array(
				'product' => 0,
				'limit'   => LicenseTerms::DEFAULT_LIMIT,
				'period'  => LicenseTerms::DEFAULT_PERIOD,
			);
		}

		$parent = $parent_id > 0 ? wc_get_product( $parent_id ) : $product;
		$parent = $parent instanceof WC_Product ? $parent : $product;

		$linked = (int) $parent->get_meta( self::META_PRODUCT, true );
		if ( $linked <= 0 ) {
			return array(
				'product' => 0,
				'limit'   => LicenseTerms::DEFAULT_LIMIT,
				'period'  => LicenseTerms::DEFAULT_PERIOD,
			);
		}

		$variation_limit  = $product->get_id() !== $parent->get_id() ? (string) $product->get_meta( self::META_LIMIT, true ) : '';
		$variation_period = $product->get_id() !== $parent->get_id() ? (string) $product->get_meta( self::META_PERIOD, true ) : '';

		return array(
			'product' => $linked,
			'limit'   => LicenseTerms::activation_limit(
				$variation_limit,
				(string) $parent->get_meta( self::META_LIMIT, true ),
				(string) get_post_meta( $linked, 'wplit_default_activation_limit', true )
			),
			'period'  => LicenseTerms::period(
				$variation_period,
				(string) $parent->get_meta( self::META_PERIOD, true ),
				(string) get_post_meta( $linked, 'wplit_period', true )
			),
		);
	}

	/**
	 * Terms for an order line: the checkout snapshot if there is one, else the current settings.
	 *
	 * @param WC_Order_Item_Product $item Order line.
	 * @return array{product:int,limit:int,period:string}
	 */
	private function terms( WC_Order_Item_Product $item ): array {
		$snapshot = (int) $item->get_meta( self::META_PRODUCT, true );

		if ( $snapshot > 0 ) {
			return array(
				'product' => $snapshot,
				'limit'   => max( 0, (int) $item->get_meta( self::META_LIMIT, true ) ),
				'period'  => (string) $item->get_meta( self::META_PERIOD, true ),
			);
		}

		$variation_id = (int) $item->get_variation_id();

		return $this->current_terms( $item->get_product(), $variation_id > 0 ? (int) $item->get_product_id() : 0 );
	}
}
