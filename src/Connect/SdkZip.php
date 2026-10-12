<?php
/**
 * Packs the client SDK into a zip a seller can unpack into their own plugin or theme.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Connect;

use RuntimeException;
use ZipArchive;

/**
 * Builds wplicense-it-client.zip from the sdk folder. The zip holds one folder, wplicense-it-client, so unpacking it
 * into vendor/ gives vendor/wplicense-it-client, which is where the pasted code expects it.
 */
final class SdkZip {

	public const FOLDER = 'wplicense-it-client';

	/**
	 * Files in the sdk folder that are for developing the SDK, not for using it.
	 */
	private const SKIP = array( 'phpcs.xml.dist' );

	/**
	 * Builds the zip in a temp file. The caller deletes it.
	 *
	 * @param string $sdk_dir The sdk folder.
	 * @return string Path of the zip.
	 * @throws RuntimeException If the zip cannot be made.
	 */
	public static function build( string $sdk_dir ): string {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'The PHP zip extension is not available.' );
		}

		$sdk_dir = rtrim( $sdk_dir, '/\\' );

		if ( ! is_file( $sdk_dir . '/wplicense-it-client.php' ) ) {
			throw new RuntimeException( 'The SDK files were not found.' );
		}

		$path = tempnam( sys_get_temp_dir(), 'wplit-sdk' );

		if ( false === $path ) {
			throw new RuntimeException( 'Could not create a temporary file.' );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best effort cleanup.
			throw new RuntimeException( 'Could not create the zip.' );
		}

		foreach ( self::files( $sdk_dir ) as $relative ) {
			$zip->addFile( $sdk_dir . '/' . $relative, self::FOLDER . '/' . $relative );
		}

		if ( ! $zip->close() ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best effort cleanup.
			throw new RuntimeException( 'Could not write the zip.' );
		}

		return $path;
	}

	/**
	 * Lists the files to pack, as paths inside the sdk folder, in a stable order.
	 *
	 * @param string $sdk_dir The sdk folder.
	 * @return string[]
	 */
	public static function files( string $sdk_dir ): array {
		$sdk_dir = rtrim( $sdk_dir, '/\\' );
		$found   = array();
		$walker  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $sdk_dir, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $walker as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $sdk_dir ) + 1 ) );

			if ( in_array( $relative, self::SKIP, true ) ) {
				continue;
			}

			$found[] = $relative;
		}

		sort( $found );

		return $found;
	}
}
