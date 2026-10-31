<?php
/**
 * The WooCommerce side of plan syncing.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * Everything the sync does to WooCommerce goes through here, so the sync logic can be tested without it.
 */
interface ProductGateway {

	/**
	 * Currency of the store, such as USD.
	 */
	public function currency(): string;

	/**
	 * Looks a product up.
	 *
	 * @param int $id WooCommerce product ID.
	 * @return RemoteProduct|null Null if it does not exist.
	 */
	public function find( int $id ): ?RemoteProduct;

	/**
	 * Creates a draft, virtual, simple product linked to the software.
	 *
	 * @param string $plan_id     Plan ID, kept on the product so it can be traced back.
	 * @param string $title       Product title.
	 * @param int    $price_minor Regular price in minor units.
	 * @param int    $software_id License product it sells.
	 * @param int    $sites       Sites per license, 0 for unlimited.
	 * @param string $period      License period.
	 * @return int New product ID.
	 * @throws PlanException If it cannot be created.
	 */
	public function create( string $plan_id, string $title, int $price_minor, int $software_id, int $sites, string $period ): int;

	/**
	 * Sets the regular price.
	 *
	 * @param int $id          WooCommerce product ID.
	 * @param int $price_minor Price in minor units.
	 * @throws PlanException If it cannot be changed.
	 */
	public function update_price( int $id, int $price_minor ): void;

	/**
	 * Sets the link to the software and the license terms.
	 *
	 * @param int    $id          WooCommerce product ID.
	 * @param int    $software_id License product it sells.
	 * @param int    $sites       Sites per license, 0 for unlimited.
	 * @param string $period      License period.
	 * @throws PlanException If it cannot be changed.
	 */
	public function update_terms( int $id, int $software_id, int $sites, string $period ): void;

	/**
	 * Sets the product status.
	 *
	 * @param int    $id     WooCommerce product ID.
	 * @param string $status 'draft' or 'publish'.
	 * @throws PlanException If it cannot be changed.
	 */
	public function set_status( int $id, string $status ): void;
}
