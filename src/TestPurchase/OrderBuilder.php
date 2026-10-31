<?php
/**
 * Builds the order a test purchase pretends to receive.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

use Devllo\WPLicenseIt\WooCommerce\OrderData;

/**
 * Makes an order for one product without saving anything.
 */
interface OrderBuilder {

	/**
	 * An in-memory order with one line for the WooCommerce product.
	 *
	 * @param int $woo_product_id WooCommerce product ID.
	 * @param int $quantity       Units on the line.
	 * @throws \RuntimeException If the product cannot be loaded.
	 */
	public function build( int $woo_product_id, int $quantity ): OrderData;
}
