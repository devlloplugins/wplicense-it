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
	 * @param int              $id          WooCommerce order ID.
	 * @param string           $number      Order number shown to the customer.
	 * @param int|null         $user_id     WordPress user, null for guests.
	 * @param string           $email       Billing email.
	 * @param string           $currency    ISO 4217 code.
	 * @param int              $total_minor Order total in minor units.
	 * @param OrderItemData[]  $items       Order lines.
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
}
