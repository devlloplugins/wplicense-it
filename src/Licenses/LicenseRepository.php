<?php
/**
 * License storage contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

use DateTimeImmutable;

/**
 * Stores licenses.
 */
interface LicenseRepository {

	/**
	 * Saves a new license and returns it with its ID set.
	 *
	 * @param License $license License with ID 0.
	 */
	public function insert( License $license ): License;

	/**
	 * Saves changes to an existing license.
	 *
	 * @param License $license License with an ID.
	 */
	public function update( License $license ): void;

	/**
	 * Finds a license by ID.
	 *
	 * @param int $id License ID.
	 */
	public function find( int $id ): ?License;

	/**
	 * Finds a license by its key (exact match).
	 *
	 * @param string $license_key License key.
	 */
	public function find_by_key( string $license_key ): ?License;

	/**
	 * A customer's licenses, newest first.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return License[]
	 */
	public function find_by_user( int $user_id ): array;

	/**
	 * Licenses issued for an order, in the order they were created.
	 *
	 * @param int $order_id Row ID in the license orders table.
	 * @return License[]
	 */
	public function find_by_order( int $order_id ): array;

	/**
	 * Searches licenses for the admin list.
	 *
	 * @param LicenseQuery $query Filters, sort order and page.
	 */
	public function search( LicenseQuery $query ): LicensePage;

	/**
	 * Number of licenses per status.
	 *
	 * @return array<string, int> Status => count, for every status.
	 */
	public function status_counts(): array;

	/**
	 * Active licenses whose expiry has passed.
	 *
	 * @param DateTimeImmutable $now   Current time.
	 * @param int               $limit Maximum number to return.
	 * @return License[]
	 */
	public function find_due_for_expiry( DateTimeImmutable $now, int $limit ): array;
}
