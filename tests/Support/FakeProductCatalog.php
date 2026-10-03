<?php
/**
 * Fake product catalog for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\Api\ProductInfo;

/**
 * A catalog with one product (ID 7, version 2.0.0, API key "prod-key") and a package file.
 */
final class FakeProductCatalog implements ProductCatalog {

	/**
	 * Package file path, null when the product has no package.
	 *
	 * @var string|null
	 */
	public ?string $package = '/tmp/pkg.zip';

	public function find( int $product_id ): ?ProductInfo {
		if ( 7 !== $product_id ) {
			return null;
		}

		return new ProductInfo( 7, 'Great Plugin', '2.0.0', '6.2', '6.6', 'Does great things.', 'https://x.test/logo.png', 'https://x.test/banner.png', null !== $this->package );
	}

	public function api_key( int $product_id ): string {
		return 7 === $product_id ? 'prod-key' : '';
	}

	public function package_file( int $product_id ): ?string {
		return 7 === $product_id ? $this->package : null;
	}
}
