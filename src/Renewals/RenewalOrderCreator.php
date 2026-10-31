<?php
/**
 * Contract for making a renewal invoice.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Renewals;

use Devllo\WPLicenseIt\Licenses\License;

/**
 * Makes the order a customer pays to renew a license, and sends it to them.
 */
interface RenewalOrderCreator {

	/**
	 * Whether a license product can be renewed this way (something to sell, with a price).
	 *
	 * @param int $license_product_id WPLicense It product ID.
	 */
	public function sells( int $license_product_id ): bool;

	/**
	 * Creates the unpaid renewal order for a license and sends the invoice to the customer.
	 *
	 * @param License $license The license that is about to expire.
	 * @return int|null The order ID, or null when no invoice could be made (nothing was sent).
	 */
	public function create( License $license ): ?int;
}
