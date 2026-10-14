<?php
/**
 * Removes a license product's files when the product is deleted for good.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

use Devllo\WPLicenseIt\Releases\ReleaseService;
use Throwable;

/**
 * Release zips, the logo and the banner of a product are kept in uploads/wplit-files. Trashing a product keeps them,
 * because it can be restored; deleting it permanently (from the trash, or by the daily emptying of the trash) removes them.
 * Licenses already sold are not touched: they stay in the license list.
 */
final class ProductCleanup {

	public const POST_TYPE = 'wplit_product';

	/**
	 * @var ReleaseService
	 */
	private $releases;

	/**
	 * @var FileStore
	 */
	private $files;

	/**
	 * Constructor.
	 *
	 * @param ReleaseService $releases Release history.
	 * @param FileStore      $files    File storage.
	 */
	public function __construct( ReleaseService $releases, FileStore $files ) {
		$this->releases = $releases;
		$this->files    = $files;
	}

	/**
	 * Registers the hook.
	 */
	public function register(): void {
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
	}

	/**
	 * Runs just before WordPress deletes a post for good.
	 *
	 * @param int         $post_id Post ID.
	 * @param object|null $post    The post.
	 */
	public function on_delete( int $post_id, $post = null ): void {
		$type = is_object( $post ) && isset( $post->post_type ) ? (string) $post->post_type : (string) get_post_type( $post_id );

		if ( self::POST_TYPE !== $type ) {
			return;
		}

		$this->clean( $post_id );
	}

	/**
	 * Deletes the files of a product. Problems are logged and never stop the deletion of the product itself.
	 *
	 * @param int $product_id License product ID.
	 */
	public function clean( int $product_id ): void {
		try {
			// Zips first, one by one: this also reaches zips that 1.x kept outside the product's own folder.
			$paths = array();
			foreach ( $this->releases->all( $product_id ) as $release ) {
				if ( '' !== $release->path ) {
					$paths[ $release->path ] = true;
					// Releases can share one zip, which is deleted once.
				}
			}

			foreach ( array_keys( $paths ) as $path ) {
				$this->files->delete_package( (string) $path );
			}
		} catch ( Throwable $e ) {
			error_log( 'WPLicense It: could not delete a release file of product ' . $product_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need to see this.
		}

		try {
			$this->files->delete_product_files( $product_id );
		} catch ( Throwable $e ) {
			error_log( 'WPLicense It: could not delete the folder of product ' . $product_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need to see this.
		}
	}
}
