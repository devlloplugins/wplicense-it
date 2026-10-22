<?php
/**
 * License order storage contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * Stores the minimal order record in the license orders table.
 */
interface LicenseOrderRepository {

	/**
	 * Returns the record for a WooCommerce order, creating it if needed.
	 *
	 * @param OrderData $order Order.
	 * @return int Row ID.
	 */
	public function record( OrderData $order ): int;

	/**
	 * Sets the status of the record for a WooCommerce order, if there is one.
	 *
	 * @param int    $woo_order_id WooCommerce order ID.
	 * @param string $status       completed, refunded or cancelled.
	 */
	public function set_status( int $woo_order_id, string $status ): void;
}
