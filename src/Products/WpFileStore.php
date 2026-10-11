<?php
/**
 * File storage in the WordPress uploads folder.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

use Devllo\WPLicenseIt\Files\ProtectedStorage;

/**
 * Keeps release zips in the protected folder, under a folder named after the product's ID (a draft has no slug
 * yet) and a random token, and keeps logos and banners next to them.
 */
final class WpFileStore implements FileStore {

	private const IMAGE_TYPES = array(
		'png'          => 'image/png',
		'jpg|jpeg|jpe' => 'image/jpeg',
		'webp'         => 'image/webp',
	);

	public function is_uploaded( string $path ): bool {
		return is_uploaded_file( $path );
	}

	public function store_package( int $product_id, string $tmp, string $original_name, string $version ): StoredFile {
		$name = sanitize_file_name( basename( $original_name ) );
		if ( '' === $name || 0 !== validate_file( $name ) || 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			throw new FileStoreException( 'The file name is not valid. Use a name that ends in .zip.' );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new FileStoreException( 'WordPress cannot use its uploads folder: ' . (string) $uploads['error'] );
		}

		ProtectedStorage::maybe_create();

		$folder      = 'wplit-files/p' . $product_id . '/v' . Version::folder( $version );
		$version_dir = $uploads['basedir'] . '/' . $folder . '/';

		if ( ! wp_mkdir_p( $version_dir ) ) {
			throw new FileStoreException( 'The folder for this release could not be created. Check the permissions of the uploads folder.' );
		}

		// A new folder with a random name for every upload, so the package cannot be found by guessing its address.
		$package_dir = ProtectedStorage::new_package_directory( $version_dir );

		if ( ! is_dir( $package_dir ) || ! move_uploaded_file( $tmp, $package_dir . $name ) ) {
			throw new FileStoreException( 'The file could not be moved into the releases folder. Check the permissions of the uploads folder.' );
		}

		$relative = $folder . '/' . basename( $package_dir ) . '/' . $name;

		return new StoredFile( $relative, $uploads['baseurl'] . '/' . $relative, $name );
	}

	public function delete_package( string $relative ): void {
		$uploads = wp_upload_dir();
		$root    = realpath( $uploads['basedir'] . '/wplit-files' );
		$file    = realpath( $uploads['basedir'] . '/' . $relative );

		if ( false === $file ) {
			return; // Already gone.
		}

		if ( false === $root || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || 'zip' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) || ! is_file( $file ) ) {
			throw new FileStoreException( 'That file is not a release zip in the releases folder, so it was not deleted.' );
		}

		if ( ! @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The result is checked.
			throw new FileStoreException( 'The file could not be deleted. Check the permissions of the uploads folder.' );
		}

		// Tidy up the folders that only held this file: the random one, then the version's.
		foreach ( array( dirname( $file ), dirname( $file, 2 ) ) as $dir ) {
			if ( 0 !== strpos( $dir, $root . DIRECTORY_SEPARATOR ) ) {
				break;
			}
			$left = array_diff( (array) scandir( $dir ), array( '.', '..', 'index.php' ) );
			if ( $left ) {
				break;
			}
			@unlink( $dir . '/index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Our own placeholder file.
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only empty folders.
		}
	}

	public function store_image( int $product_id, string $kind, array $file ): StoredFile {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$redirect = static function ( array $dirs ) use ( $product_id, $kind ): array {
			$subdir         = '/wplit-files/p' . $product_id . '/' . $kind;
			$dirs['subdir'] = $subdir;
			$dirs['path']   = $dirs['basedir'] . $subdir;
			$dirs['url']    = $dirs['baseurl'] . $subdir;

			return $dirs;
		};

		add_filter( 'upload_dir', $redirect );
		try {
			// WordPress checks the type from the file's content, not only its name.
			$result = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'mimes'     => self::IMAGE_TYPES,
				)
			);
		} finally {
			remove_filter( 'upload_dir', $redirect );
		}

		if ( ! is_array( $result ) || isset( $result['error'] ) || empty( $result['file'] ) || empty( $result['url'] ) ) {
			throw new FileStoreException( is_array( $result ) && isset( $result['error'] ) ? (string) $result['error'] : 'The image could not be stored.' );
		}

		ProtectedStorage::seal( dirname( (string) $result['file'] ) );

		$uploads  = wp_upload_dir();
		$relative = ltrim( str_replace( $uploads['basedir'], '', (string) $result['file'] ), '/' );

		return new StoredFile( $relative, (string) $result['url'], basename( (string) $result['file'] ) );
	}
}
