<?php
/**
 * Order builder over the fake WooCommerce.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\TestPurchase\OrderBuilder;
use Devllo\WPLicenseIt\WooCommerce\OrderData;
use Devllo\WPLicenseIt\WooCommerce\OrderItemData;

/**
 * Builds an order from the settings of a product in FakeProductGateway.
 */
final class FakeOrderBuilder implements OrderBuilder {

	/**
	 * @var FakeProductGateway
	 */
	private $woo;

	/**
	 * Constructor.
	 *
	 * @param FakeProductGateway $woo Fake WooCommerce.
	 */
	public function __construct( FakeProductGateway $woo ) {
		$this->woo = $woo;
	}

	public function build( int $woo_product_id, int $quantity ): OrderData {
		$product = $this->woo->products[ $woo_product_id ] ?? null;
		if ( null === $product ) {
			throw new \RuntimeException( 'The WooCommerce product could not be loaded.' );
		}

		return new OrderData( 1, 'TEST', null, 'test-purchase@example.com', 'USD', 0, array( new OrderItemData( 1, 'SEO Pro', $quantity, (int) $product['software'], (int) $product['sites'], (string) $product['period'], null, array(), 0 ) ) );
	}
}
