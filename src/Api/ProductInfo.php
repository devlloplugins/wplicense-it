<?php
/**
 * Product information.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * What the update checker needs to know about a product.
 */
final class ProductInfo {

	/**
	 * Constructor.
	 *
	 * @param int    $id          Post ID of the wplit_product.
	 * @param string $name        Plugin or theme name.
	 * @param string $version     Latest version.
	 * @param string $requires    Minimum WordPress version.
	 * @param string $tested      WordPress version tested up to.
	 * @param string $description Short description.
	 * @param string $logo_url    Logo URL (used as the small banner).
	 * @param string $banner_url  Banner URL (used as the large banner).
	 * @param bool   $has_package Whether a downloadable file has been uploaded.
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $version,
		public string $requires,
		public string $tested,
		public string $description,
		public string $logo_url,
		public string $banner_url,
		public bool $has_package
	) {
	}
}
