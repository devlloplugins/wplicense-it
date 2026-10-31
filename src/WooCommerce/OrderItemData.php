<?php
/**
 * Order line item data.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * What the adapter needs to know about one order line, independent of WooCommerce.
 */
final class OrderItemData {

	/**
	 * Constructor.
	 *
	 * @param int      $item_id           Order item ID.
	 * @param string   $name              Product name, for notes.
	 * @param int      $quantity          Units bought.
	 * @param int      $wplit_product_id  Linked WPLicense It product, 0 if the line is not licensed.
	 * @param int      $activation_limit  Sites per license, 0 for unlimited.
	 * @param string   $period            "lifetime" or an ISO 8601 duration.
	 * @param int|null $renew_license_id  License the customer chose to renew, if any.
	 * @param int[]    $license_ids       Licenses already created for this line (including a renewed one).
	 * @param int      $refunded_quantity Units refunded so far.
	 */
	public function __construct(
		public int $item_id,
		public string $name,
		public int $quantity,
		public int $wplit_product_id,
		public int $activation_limit,
		public string $period,
		public ?int $renew_license_id,
		public array $license_ids,
		public int $refunded_quantity
	) {
	}
}
