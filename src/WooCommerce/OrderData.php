<?php
/**
 * Order data.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * What the adapter needs to know about an order, independent of WooCommerce.
 */
final class OrderData {

	/**
	 * Constructor.
	 *
	 * @param int             $id          WooCommerce order ID.
	 * @param string          $number      Order number shown to the customer.
	 * @param int|null        $user_id     WordPress user, null for guests.
	 * @param string          $email       Billing email.
	 * @param string          $currency    ISO 4217 code.
	 * @param int             $total_minor Order total in minor units.
	 * @param OrderItemData[] $items       Order lines.
	 */
	public function __construct(
		public int $id,
		public string $number,
		public ?int $user_id,
		public string $email,
		public string $currency,
		public int $total_minor,
		public array $items
	) {
	}

	/**
	 * The licensed product this order is for: its ID when every licensed line is for the same product, 0 when the order
	 * has lines for several products (no one of them is "the" product) or none. The licenses themselves, which each
	 * link to the order, are the full record; see OrderProducts.
	 */
	public function licensed_product_id(): int {
		$ids = array();

		foreach ( $this->items as $item ) {
			if ( $item->wplit_product_id > 0 ) {
				$ids[ $item->wplit_product_id ] = true;
			}
		}

		return 1 === count( $ids ) ? (int) array_key_first( $ids ) : 0;
	}
}
