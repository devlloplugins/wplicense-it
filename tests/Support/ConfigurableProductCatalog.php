<?php
/**
 * Product catalog whose answers a test can set.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\Api\ProductInfo;

/**
 * One software product (ID 7) that can be unpublished, versionless or without a zip.
 */
final class ConfigurableProductCatalog implements ProductCatalog {

	/**
	 * @var bool
	 */
	public $published = true;

	/**
	 * @var string
	 */
	public $version = '1.4.2';

	/**
	 * @var bool
	 */
	public $has_package = true;

	/**
	 * @var string|null
	 */
	public $file;

	public function find( int $product_id ): ?ProductInfo {
		if ( 7 !== $product_id || ! $this->published ) {
			return null;
		}

		return new ProductInfo( 7, 'SEO Pro', $this->version, '6.2', '6.7', '', '', '', $this->has_package );
	}

	public function api_key( int $product_id ): string {
		return 'key';
	}

	public function package_file( int $product_id ): ?string {
		return 7 === $product_id ? $this->file : null;
	}
}
