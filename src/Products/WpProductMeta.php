<?php
/**
 * Product settings in post meta.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * Keeps product settings in the post meta of the license product.
 */
final class WpProductMeta implements ProductMeta {

	public function get( int $product_id, string $key ): string {
		$value = get_post_meta( $product_id, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	public function set( int $product_id, string $key, string $value ): void {
		// update_post_meta removes slashes, so the value is slashed first to keep backslashes intact.
		update_post_meta( $product_id, $key, wp_slash( $value ) );
	}

	public function delete( int $product_id, string $key ): void {
		delete_post_meta( $product_id, $key );
	}
}
