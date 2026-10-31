<?php
/**
 * Product settings storage.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * Reads and writes the settings kept on a license product.
 */
interface ProductMeta {

	/**
	 * A setting, or '' if it is not set.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $key        Setting name.
	 */
	public function get( int $product_id, string $key ): string;

	/**
	 * Saves a setting.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $key        Setting name.
	 * @param string $value      Value.
	 */
	public function set( int $product_id, string $key, string $value ): void;

	/**
	 * Removes a setting.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $key        Setting name.
	 */
	public function delete( int $product_id, string $key ): void;
}
