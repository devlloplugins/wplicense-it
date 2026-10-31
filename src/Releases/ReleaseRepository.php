<?php
/**
 * Release storage.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Releases;

/**
 * Where the releases of a product are kept.
 */
interface ReleaseRepository {

	/**
	 * The releases of a product, oldest first. Empty if none are recorded yet.
	 *
	 * @param int $product_id License product ID.
	 * @return Release[]
	 */
	public function all( int $product_id ): array;

	/**
	 * Replaces the releases of a product.
	 *
	 * @param int       $product_id License product ID.
	 * @param Release[] $releases   Releases, oldest first.
	 */
	public function save( int $product_id, array $releases ): void;
}
