<?php
/**
 * Personal data storage contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Privacy;

use Devllo\WPLicenseIt\Licenses\Activation;
use Devllo\WPLicenseIt\Licenses\License;

/**
 * Finds, and anonymises, everything stored about one person: by email address and WordPress user.
 */
interface PrivacyRepository {

	/**
	 * Licenses that belong to a person, oldest first.
	 *
	 * @param string   $email    Email address (any case).
	 * @param int|null $user_id  WordPress user ID, if the person has an account.
	 * @param int      $page     Page number, from 1.
	 * @param int      $per_page Licenses per page.
	 * @return License[]
	 */
	public function licenses( string $email, ?int $user_id, int $page, int $per_page ): array;

	/**
	 * Every site a license was ever activated on, active or not.
	 *
	 * @param int $license_id License ID.
	 * @return Activation[]
	 */
	public function activations( int $license_id ): array;

	/**
	 * Order records of a person (by account, and the orders their licenses came from).
	 *
	 * @param License[] $licenses The person's licenses on this page.
	 * @param int|null  $user_id  WordPress user ID, if any.
	 * @return array<int, array{id:int,order_number:string,source:string,currency:string,total_minor:int,status:string,created_at:string,billing:array<string,string>}>
	 */
	public function orders( array $licenses, ?int $user_id ): array;

	/**
	 * History of a license, newest first.
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Maximum events.
	 * @return array<int, array{type:string,data:array<string,mixed>,created_at:string}>
	 */
	public function events( int $license_id, int $limit ): array;

	/**
	 * Rows the 1.x tables hold for a person (which stay in the database after the migration).
	 *
	 * @param string   $email   Email address.
	 * @param int|null $user_id WordPress user ID, if any.
	 * @return array{licenses:array<int,array<string,mixed>>,orders:array<int,array<string,mixed>>}
	 */
	public function legacy_rows( string $email, ?int $user_id ): array;

	/**
	 * Removes the person's identity from a license and its history, keeping the license itself.
	 *
	 * @param int    $license_id License ID.
	 * @param string $anonymous  The placeholder email to store instead.
	 */
	public function anonymize_license( int $license_id, string $anonymous ): void;

	/**
	 * Removes the person's name, address, phone and email from the order records linked to
	 * a person (new and migrated), keeping totals and numbers for accounting.
	 *
	 * @param int[]    $license_ids Licenses that were anonymised.
	 * @param int|null $user_id     WordPress user ID, if any.
	 * @return int Number of order records cleaned.
	 */
	public function anonymize_orders( array $license_ids, ?int $user_id ): int;

	/**
	 * Does the same for the 1.x tables.
	 *
	 * @param string   $email   Email address.
	 * @param int|null $user_id WordPress user ID, if any.
	 * @return int Number of 1.x rows cleaned.
	 */
	public function anonymize_legacy( string $email, ?int $user_id ): int;
}
