<?php
/**
 * In-memory product settings for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Products\ProductMeta;

/**
 * Keeps settings in an array.
 */
final class InMemoryProductMeta implements ProductMeta {

	/**
	 * Settings by product ID and name.
	 *
	 * @var array<int, array<string, string>>
	 */
	public $data = array();

	public function get( int $product_id, string $key ): string {
		return $this->data[ $product_id ][ $key ] ?? '';
	}

	public function set( int $product_id, string $key, string $value ): void {
		$this->data[ $product_id ][ $key ] = $value;
	}

	public function delete( int $product_id, string $key ): void {
		unset( $this->data[ $product_id ][ $key ] );
	}
}
