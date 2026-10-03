<?php
/**
 * Order line to license links in WooCommerce item meta.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use WC_Order_Factory;
use WC_Order_Item;

/**
 * Stores license IDs in the "_wplit_license_ids" meta of the order line, which works with
 * both the classic and the high-performance (HPOS) order storage.
 */
final class WooItemLicenseStore implements ItemLicenseStore {

	public const META_KEY = '_wplit_license_ids';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $item_id    Order item ID.
	 * @param int $license_id License ID.
	 */
	public function append( int $item_id, int $license_id ): void {
		$item = WC_Order_Factory::get_order_item( $item_id );

		if ( ! $item instanceof WC_Order_Item ) {
			return;
		}

		$ids   = self::ids( $item );
		$ids[] = $license_id;

		$item->update_meta_data( self::META_KEY, array_values( array_unique( $ids ) ) );
		$item->save();
	}

	/**
	 * License IDs recorded on an order line.
	 *
	 * @param WC_Order_Item $item Order line.
	 * @return int[]
	 */
	public static function ids( WC_Order_Item $item ): array {
		$stored = $item->get_meta( self::META_KEY, true );

		return is_array( $stored ) ? array_map( 'intval', $stored ) : array();
	}
}
