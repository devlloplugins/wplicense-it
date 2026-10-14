<?php
/**
 * Builds a test order from a real WooCommerce product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

use Devllo\WPLicenseIt\WooCommerce\OrderData;
use Devllo\WPLicenseIt\WooCommerce\OrderItemData;
use Devllo\WPLicenseIt\WooCommerce\OrderReader;

/**
 * Reads the license terms the same way a real order would (OrderReader::current_terms) but builds the order
 * data itself. It does not use a WooCommerce order object: adding a product to one normally saves it, and a
 * test must write nothing.
 */
final class WcOrderBuilder implements OrderBuilder {

	/**
	 * @var OrderReader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param OrderReader $reader Order reader.
	 */
	public function __construct( OrderReader $reader ) {
		$this->reader = $reader;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException If the WooCommerce product cannot be loaded.
	 */
	public function build( int $woo_product_id, int $quantity ): OrderData {
		$product = wc_get_product( $woo_product_id );
		if ( ! $product ) {
			throw new \RuntimeException( 'The WooCommerce product could not be loaded.' );
		}

		$terms = $this->reader->current_terms( $product );

		return new OrderData(
			1,
			'TEST',
			null,
			'test-purchase@example.com',
			(string) get_woocommerce_currency(),
			0,
			array(
				new OrderItemData( 1, (string) $product->get_name(), $quantity, $terms['product'], $terms['limit'], $terms['period'], null, array(), 0 ),
			)
		);
	}
}
