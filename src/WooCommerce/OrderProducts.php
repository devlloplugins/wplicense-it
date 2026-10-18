<?php
/**
 * The products an order was for.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Licenses\LicenseRepository;

/**
 * Reads the products of an order from its licenses.
 *
 * The order record has room for one product, so for an order with several the licenses, which each link to the order, are
 * the full record. A line that has no license (a period that could not be used, for example) is not in it.
 */
final class OrderProducts {

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository $licenses License storage.
	 */
	public function __construct( private LicenseRepository $licenses ) {
	}

	/**
	 * The distinct products of the licenses issued for an order, in the order they were first licensed.
	 *
	 * @param int $order_id Row ID in the license orders table.
	 * @return int[]
	 */
	public function ids( int $order_id ): array {
		$ids = array();

		foreach ( $this->licenses->find_by_order( $order_id ) as $license ) {
			$ids[ $license->product_id ] = $license->product_id;
		}

		return array_values( $ids );
	}
}
