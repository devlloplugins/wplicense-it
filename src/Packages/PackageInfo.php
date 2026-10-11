<?php
/**
 * What a plugin or theme zip says about itself.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Packages;

/**
 * Details read from a package. Values come from the zip, so they are cleaned but never trusted.
 */
final class PackageInfo {

	public const TYPE_PLUGIN = 'plugin';
	public const TYPE_THEME  = 'theme';

	/**
	 * @var string 'plugin' or 'theme'.
	 */
	public $type;

	/**
	 * @var string Display name.
	 */
	public $name;

	/**
	 * @var string Version, for example 1.4.2.
	 */
	public $version;

	/**
	 * @var string Name of the folder the zip unpacks to.
	 */
	public $folder;

	/**
	 * @var string Path of the file the details came from, inside the zip.
	 */
	public $main_file;

	/**
	 * @var string Minimum WordPress version, or ''.
	 */
	public $requires_wp = '';

	/**
	 * @var string Minimum PHP version, or ''.
	 */
	public $requires_php = '';

	/**
	 * @var string Latest WordPress version the software was tested on, or ''.
	 */
	public $tested_up_to = '';

	/**
	 * @var string Text domain, or ''.
	 */
	public $text_domain = '';

	/**
	 * @var string Author, or ''.
	 */
	public $author = '';

	/**
	 * @var string What changed in this version, taken from the Changelog section of readme.txt, or ''.
	 */
	public $changelog = '';

	/**
	 * @var string[] Things the seller should look at. The package is still usable.
	 */
	public $warnings = array();

	/**
	 * @param string $type      Package type.
	 * @param string $name      Display name.
	 * @param string $version   Version.
	 * @param string $folder    Folder name inside the zip.
	 * @param string $main_file Source file inside the zip.
	 */
	public function __construct( string $type, string $name, string $version, string $folder, string $main_file ) {
		$this->type      = $type;
		$this->name      = $name;
		$this->version   = $version;
		$this->folder    = $folder;
		$this->main_file = $main_file;
	}
}
