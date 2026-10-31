<?php
/**
 * File store that only records what it was asked to do.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Products\FileStore;
use Devllo\WPLicenseIt\Products\FileStoreException;
use Devllo\WPLicenseIt\Products\StoredFile;

/**
 * Pretends to store files.
 */
final class FakeFileStore implements FileStore {

	/**
	 * @var string[] Paths that are not real uploads.
	 */
	public $not_uploaded = array();

	/**
	 * @var string|null Message to fail package storage with.
	 */
	public $package_error;

	/**
	 * @var string|null Message to fail image storage with.
	 */
	public $image_error;

	/**
	 * @var array<int, array{product:int,name:string,version:string}>
	 */
	public $packages = array();

	/**
	 * @var array<int, array{product:int,kind:string,name:string}>
	 */
	public $images = array();

	public function is_uploaded( string $path ): bool {
		return ! in_array( $path, $this->not_uploaded, true );
	}

	public function store_package( int $product_id, string $tmp, string $original_name, string $version ): StoredFile {
		if ( null !== $this->package_error ) {
			throw new FileStoreException( $this->package_error );
		}

		$this->packages[] = array(
			'product' => $product_id,
			'name'    => $original_name,
			'version' => $version,
		);
		$relative         = 'wplit-files/p' . $product_id . '/v' . $version . '/' . str_repeat( 'a', 32 ) . '/' . $original_name;

		return new StoredFile( $relative, 'https://shop.example.com/wp-content/uploads/' . $relative, $original_name );
	}

	/**
	 * @var string[] Paths that were deleted.
	 */
	public $deleted = array();

	/**
	 * @var string|null Message to fail deleting with.
	 */
	public $delete_error;

	public function delete_package( string $relative ): void {
		if ( null !== $this->delete_error ) {
			throw new FileStoreException( $this->delete_error );
		}

		$this->deleted[] = $relative;
	}

	/**
	 * @var int[] Products whose folder was deleted.
	 */
	public $deleted_products = array();

	public function delete_product_files( int $product_id ): void {
		if ( null !== $this->delete_error ) {
			throw new FileStoreException( $this->delete_error );
		}

		$this->deleted_products[] = $product_id;
	}

	public function store_image( int $product_id, string $kind, array $file ): StoredFile {
		if ( null !== $this->image_error ) {
			throw new FileStoreException( $this->image_error );
		}

		$this->images[] = array(
			'product' => $product_id,
			'kind'    => $kind,
			'name'    => (string) $file['name'],
		);
		$relative       = 'wplit-files/p' . $product_id . '/' . $kind . '/' . $file['name'];

		return new StoredFile( $relative, 'https://shop.example.com/wp-content/uploads/' . $relative, (string) $file['name'] );
	}
}
