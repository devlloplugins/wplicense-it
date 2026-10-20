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

	/**
	 * {@inheritdoc}
	 */
	public function is_uploaded( string $path ): bool {
		return is_uploaded_file( $path );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws FileStoreException If the file cannot be stored or deleted.
	 */
	public function store_package( int $product_id, string $tmp, string $original_name, string $version ): StoredFile {
		$name = sanitize_file_name( basename( $original_name ) );
		if ( '' === $name || 0 !== validate_file( $name ) || 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			throw new FileStoreException( __( 'The file name is not valid. Use a name that ends in .zip.', 'wplicense-it' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new FileStoreException( 'WordPress cannot use its uploads folder: ' . (string) $uploads['error'] );
		}

		ProtectedStorage::maybe_create();

		$folder      = 'wplit-files/p' . $product_id . '/v' . Version::folder( $version );
		$version_dir = $uploads['basedir'] . '/' . $folder . '/';

		if ( ! wp_mkdir_p( $version_dir ) ) {
			throw new FileStoreException( __( 'The folder for this release could not be created. Check the permissions of the uploads folder.', 'wplicense-it' ) );
		}

		// A new folder with a random name for every upload, so the package cannot be found by guessing its address.
		$package_dir = ProtectedStorage::new_package_directory( $version_dir );

		if ( ! is_dir( $package_dir ) || ! move_uploaded_file( $tmp, $package_dir . $name ) ) {
			throw new FileStoreException( __( 'The file could not be moved into the releases folder. Check the permissions of the uploads folder.', 'wplicense-it' ) );
		}

		$relative = $folder . '/' . basename( $package_dir ) . '/' . $name;

		return new StoredFile( $relative, $uploads['baseurl'] . '/' . $relative, $name );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws FileStoreException If the file cannot be stored or deleted.
	 */
	public function delete_package( string $relative ): void {
		$uploads = wp_upload_dir();
		$root    = realpath( $uploads['basedir'] . '/wplit-files' );
		$file    = realpath( $uploads['basedir'] . '/' . $relative );

		if ( false === $file ) {
			return;
			// Already gone.
		}

		if ( false === $root || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || 'zip' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) || ! is_file( $file ) ) {
			throw new FileStoreException( __( 'That file is not a release zip in the releases folder, so it was not deleted.', 'wplicense-it' ) );
		}

		if ( ! @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The result is checked.
			throw new FileStoreException( __( 'The file could not be deleted. Check the permissions of the uploads folder.', 'wplicense-it' ) );
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

	/**
	 * {@inheritdoc}
	 *
	 * @throws FileStoreException If the file cannot be stored or deleted.
	 */
	public function delete_product_files( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$uploads = wp_upload_dir();
		$root    = realpath( $uploads['basedir'] . '/wplit-files' );
		$folder  = $uploads['basedir'] . '/wplit-files/p' . $product_id;

		if ( false === $root || ! is_dir( $folder ) ) {
			return;
			// Nothing was ever stored.
		}

		// A link in place of the folder is removed as a link; what it points to is not ours to delete.
		if ( is_link( $folder ) ) {
			if ( ! @unlink( $folder ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The result is checked.
				throw new FileStoreException( __( 'The product folder could not be removed.', 'wplicense-it' ) );
			}

			return;
		}

		$real = realpath( $folder );

		$expected = 'p' . $product_id;

		if ( false === $real || dirname( $real ) !== $root || basename( $real ) !== $expected ) { // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both sides are values, neither is a constant.
			throw new FileStoreException( __( 'The product folder is not inside the releases folder, so it was not deleted.', 'wplicense-it' ) );
		}

		self::remove_tree( $real );
	}

	/**
	 * Deletes a folder and everything in it. Links are removed, never followed.
	 *
	 * @param string $dir Folder.
	 * @throws FileStoreException If something cannot be deleted.
	 */
	private static function remove_tree( string $dir ): void {
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $entry ) {
			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_tree( $path );
			} elseif ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The result is checked.
				throw new FileStoreException(
					sprintf(
						/* translators: %s: file path. */
						__( 'A file could not be deleted: %s', 'wplicense-it' ),
						$entry
					)
				);
			}
		}

		if ( ! @rmdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The result is checked.
			throw new FileStoreException( __( 'A folder could not be removed.', 'wplicense-it' ) );
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws FileStoreException If the file cannot be stored or deleted.
	 */
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
			throw new FileStoreException( is_array( $result ) && isset( $result['error'] ) ? (string) $result['error'] : __( 'The image could not be stored.', 'wplicense-it' ) );
		}

		ProtectedStorage::seal( dirname( (string) $result['file'] ) );

		$uploads  = wp_upload_dir();
		$relative = ltrim( str_replace( $uploads['basedir'], '', (string) $result['file'] ), '/' );

		return new StoredFile( $relative, (string) $result['url'], basename( (string) $result['file'] ) );
	}
}
