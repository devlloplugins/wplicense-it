<?php
/**
 * In-memory order line to license links for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\WooCommerce\ItemLicenseStore;

/**
 * Keeps the links in an array.
 */
final class InMemoryItemLicenseStore implements ItemLicenseStore {

	/**
	 * License IDs by order item ID.
	 *
	 * @var array<int, int[]>
	 */
	public array $links = array();

	public function append( int $item_id, int $license_id ): void {
		$this->links[ $item_id ][] = $license_id;
	}
}
