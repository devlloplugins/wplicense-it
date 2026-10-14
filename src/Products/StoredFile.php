<?php
/**
 * A file that was stored.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * Where an uploaded file ended up.
 */
final class StoredFile {

	/**
	 * Constructor.
	 *
	 * @param string $relative Path relative to the uploads folder.
	 * @param string $url      Address of the file. For a package this is never served: packages go through the license API.
	 * @param string $name     File name.
	 */
	public function __construct(
		public string $relative,
		public string $url,
		public string $name
	) {
	}
}
