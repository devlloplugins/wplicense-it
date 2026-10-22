<?php
/**
 * A WooCommerce product, as the sync sees it.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * What the sync needs to know about the WooCommerce product behind a plan.
 */
final class RemoteProduct {

	/**
	 * Constructor.
	 *
	 * @param int      $id          WooCommerce product ID.
	 * @param string   $status      Post status: draft, publish, pending, private or trash.
	 * @param int|null $price_minor Regular price in minor units, null if the product has no price.
	 * @param int      $software_id License product the WooCommerce product is linked to, 0 if none.
	 * @param string   $sites       Sites per license setting, as stored.
	 * @param string   $period      License period setting, as stored.
	 * @param string   $type        WooCommerce product type, such as simple or variable.
	 */
	public function __construct(
		public int $id,
		public string $status,
		public ?int $price_minor,
		public int $software_id,
		public string $sites,
		public string $period,
		public string $type = 'simple'
	) {
	}

	/**
	 * Whether the product is in the trash.
	 */
	public function is_trashed(): bool {
		return 'trash' === $this->status;
	}
}
