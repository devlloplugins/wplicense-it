<?php
/**
 * PSR-4 autoloader, so the plugin works without Composer's vendor directory.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt;

/**
 * Maps the Devllo\WPLicenseIt namespace onto the src/ directory.
 */
final class Autoloader {

	private const PREFIX = 'Devllo\\WPLicenseIt\\';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base_dir Directory that maps to the plugin namespace, with a trailing slash.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				self::load( $class_name, $base_dir );
			}
		);
	}

	/**
	 * Resolves a class name to a file path inside the base directory.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $base_dir   Base directory, with a trailing slash.
	 * @return string|null Path, or null if the class is not part of this plugin.
	 */
	public static function path_for( string $class_name, string $base_dir ): ?string {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return null;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );

		return $base_dir . str_replace( '\\', '/', $relative ) . '.php';
	}

	/**
	 * Loads the file for a class, if it exists.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $base_dir   Base directory, with a trailing slash.
	 */
	private static function load( string $class_name, string $base_dir ): void {
		$path = self::path_for( $class_name, $base_dir );

		if ( null !== $path && is_file( $path ) ) {
			require_once $path;
		}
	}
}
