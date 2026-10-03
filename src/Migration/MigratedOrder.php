<?php
/**
 * An order converted from the 1.x format.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use DateTimeImmutable;

/**
 * A row for the 2.0 license orders table, converted from a 1.x order.
 */
final class MigratedOrder {

	/**
	 * Constructor.
	 *
	 * @param int               $legacy_id      ID in the 1.x orders table.
	 * @param string            $order_number   Human-readable order number.
	 * @param string            $source         free or legacy_stripe.
	 * @param int|null          $user_id        WordPress user, if any.
	 * @param int               $product_id     Product post ID.
	 * @param string            $currency       ISO 4217 code.
	 * @param int               $total_minor    Total in minor units (cents).
	 * @param string            $status         Order status.
	 * @param string|null       $legacy_billing JSON of the 1.x billing fields, or null if not kept.
	 * @param DateTimeImmutable $created_at     Created, UTC.
	 * @param DateTimeImmutable $updated_at     Updated, UTC.
	 */
	public function __construct(
		public int $legacy_id,
		public string $order_number,
		public string $source,
		public ?int $user_id,
		public int $product_id,
		public string $currency,
		public int $total_minor,
		public string $status,
		public ?string $legacy_billing,
		public DateTimeImmutable $created_at,
		public DateTimeImmutable $updated_at
	) {
	}
}
