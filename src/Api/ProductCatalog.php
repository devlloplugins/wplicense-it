<?php
/**
 * Product lookup contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Reads the products that licenses are sold for.
 */
interface ProductCatalog {

	/**
	 * Finds a published product.
	 *
	 * @param int $product_id Post ID of the wplit_product.
	 */
	public function find( int $product_id ): ?ProductInfo;

	/**
	 * The product's API key, or an empty string if it has none.
	 *
	 * @param int $product_id Post ID of the wplit_product.
	 */
	public function api_key( int $product_id ): string;

	/**
	 * Absolute path of the product's package, only if it exists inside the protected folder.
	 *
	 * @param int $product_id Post ID of the wplit_product.
	 */
	public function package_file( int $product_id ): ?string;
}
