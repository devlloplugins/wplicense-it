<?php
/**
 * 2.0 data target contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use Devllo\WPLicenseIt\Licenses\License;

/**
 * Writes migrated rows to the 2.0 tables. Every method is safe to repeat.
 */
interface MigrationTarget {

	public const CREATED  = 'created';
	public const EXISTS   = 'exists';
	public const CONFLICT = 'conflict';

	/**
	 * Imports a license, unless it has been imported already.
	 *
	 * A license with the same key but no legacy ID (issued by 2.0 while 1.x was still
	 * writing its own copy) is adopted: it gets the legacy ID and counts as existing.
	 *
	 * @param License $license   Converted license (ID 0).
	 * @param int     $legacy_id ID in the 1.x table.
	 * @return string CREATED, EXISTS, or CONFLICT if the key belongs to a different 1.x row.
	 */
	public function import_license( License $license, int $legacy_id ): string;

	/**
	 * Imports an order and links it to the license it paid for, unless it has been imported already.
	 *
	 * @param MigratedOrder $order Converted order.
	 * @return string CREATED or EXISTS.
	 */
	public function import_order( MigratedOrder $order ): string;

	/**
	 * Whether an order number is already used.
	 *
	 * @param string $order_number Order number.
	 */
	public function order_number_taken( string $order_number ): bool;

	/**
	 * Number of licenses that came from 1.x.
	 */
	public function count_migrated_licenses(): int;

	/**
	 * Number of orders that came from 1.x.
	 */
	public function count_migrated_orders(): int;
}
