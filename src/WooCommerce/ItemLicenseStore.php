<?php
/**
 * Order item to license links.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * Remembers which licenses were created for an order line, so processing an order twice
 * never issues licenses twice.
 */
interface ItemLicenseStore {

	/**
	 * Records a license for an order line. Called right after each license is created,
	 * so a failure halfway through an order loses nothing.
	 *
	 * @param int $item_id    Order item ID.
	 * @param int $license_id License ID.
	 */
	public function append( int $item_id, int $license_id ): void;
}
