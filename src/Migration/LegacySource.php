<?php
/**
 * 1.x data source contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

/**
 * Reads the 1.x tables. Never writes to them.
 */
interface LegacySource {

	/**
	 * Licenses with an ID above the cursor, in ID order.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function licenses_after( int $after_id, int $limit ): array;

	/**
	 * Orders with an ID above the cursor, in ID order.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function orders_after( int $after_id, int $limit ): array;

	/**
	 * Number of 1.x licenses.
	 */
	public function count_licenses(): int;

	/**
	 * Number of 1.x orders.
	 */
	public function count_orders(): int;

	/**
	 * Recent licenses that 1.x would currently accept, used to spot-check the migration.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function sample_usable_licenses( int $limit ): array;
}
