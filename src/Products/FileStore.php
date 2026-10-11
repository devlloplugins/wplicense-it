<?php
/**
 * Where uploaded product files go.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * Stores the files a seller uploads for a product.
 */
interface FileStore {

	/**
	 * Whether a path is a file that PHP received in this request's upload.
	 *
	 * @param string $path Temporary path.
	 */
	public function is_uploaded( string $path ): bool;

	/**
	 * Keeps a zip in the protected folder, in a new folder with a random name.
	 *
	 * @param int    $product_id    License product ID.
	 * @param string $tmp           Temporary path of the upload.
	 * @param string $original_name Name the seller's browser sent.
	 * @param string $version       Version, used in the folder name.
	 * @throws FileStoreException If the file cannot be stored.
	 */
	public function store_package( int $product_id, string $tmp, string $original_name, string $version ): StoredFile;

	/**
	 * Keeps a logo or banner image, which is shown publicly in the update details.
	 *
	 * @param int                  $product_id License product ID.
	 * @param string               $kind       'logo' or 'banner'.
	 * @param array<string, mixed> $file       The $_FILES entry.
	 * @throws FileStoreException If the image is not an accepted type or cannot be stored.
	 */
	public function store_image( int $product_id, string $kind, array $file ): StoredFile;
}
