<?php
/**
 * Version strings.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * Cleans version numbers typed into the product form or read from a zip.
 */
final class Version {

	public const MAX_LENGTH = 40;

	/**
	 * Keeps only what a version number can contain: letters, digits, dots, dashes, underscores and plus signs.
	 * Runs of dots are collapsed and the ends are trimmed, so the result is safe in a folder name.
	 *
	 * @param string $raw Typed or read value.
	 */
	public static function clean( string $raw ): string {
		$version = (string) preg_replace( '/[^A-Za-z0-9._+-]/', '', $raw );
		$version = (string) preg_replace( '/\.{2,}/', '.', $version );
		$version = trim( $version, '.-_+' );

		return substr( $version, 0, self::MAX_LENGTH );
	}

	/**
	 * The version as a folder name: the same, without plus signs.
	 *
	 * @param string $version Cleaned version.
	 */
	public static function folder( string $version ): string {
		$folder = str_replace( '+', '-', self::clean( $version ) );

		return '' === $folder ? 'unversioned' : $folder;
	}
}
