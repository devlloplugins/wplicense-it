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

	/**
	 * Licenses in a status (active by default) that expire after $from and up to $to, soonest first, for expiry reminders
	 * (and, with Status::EXPIRED, the email after expiry).
	 *
	 * @param DateTimeImmutable $from   Expiring after this time.
	 * @param DateTimeImmutable $to     Expiring at or before this time.
	 * @param License|null      $after  The last license of the previous page, to continue after it (null for the first page).
	 * @param int               $limit  Maximum number to return.
	 * @param string            $status Status::ACTIVE or Status::EXPIRED.
	 * @return License[]
	 */
	public function find_expiring( DateTimeImmutable $from, DateTimeImmutable $to, ?License $after, int $limit, string $status = Status::ACTIVE ): array;

	/**
	 * How many licenses of a status have their expiry after $from and up to $to.
	 *
	 * @param DateTimeImmutable $from   Exclusive start (UTC).
	 * @param DateTimeImmutable $to     Inclusive end (UTC).
	 * @param string            $status Status.
	 */
	public function count_expiring( DateTimeImmutable $from, DateTimeImmutable $to, string $status ): int;
}
