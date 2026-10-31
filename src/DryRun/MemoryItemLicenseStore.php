<?php
/**
 * In-memory order line to license links.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\DryRun;

use Devllo\WPLicenseIt\WooCommerce\ItemLicenseStore;

/**
 * Keeps the links in an array. Nothing is written to the order.
 */
final class MemoryItemLicenseStore implements ItemLicenseStore {

	/**
	 * License IDs by order item ID.
	 *
	 * @var array<int, int[]>
	 */
	public array $links = array();

	/**
	 * {@inheritdoc}
	 */
	public function append( int $item_id, int $license_id ): void {
		$this->links[ $item_id ][] = $license_id;
	}
}
