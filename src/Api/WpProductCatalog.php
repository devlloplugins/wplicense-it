<?php
/**
 * Product lookup using WordPress posts.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Reads products from the wplit_product post type and its post meta.
 */
final class WpProductCatalog implements ProductCatalog {

	private const POST_TYPE = 'wplit_product';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $product_id Post ID of the wplit_product.
	 */
	public function find( int $product_id ): ?ProductInfo {
		$post = get_post( $product_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$name = (string) get_post_meta( $product_id, 'wplit_product_name', true );

		return new ProductInfo(
			$product_id,
			'' !== $name ? $name : $post->post_title,
			(string) get_post_meta( $product_id, 'wplit_product_version', true ),
			(string) get_post_meta( $product_id, 'wplit_required_wp_version', true ),
			(string) get_post_meta( $product_id, 'wplit_tested_wp_version', true ),
			(string) get_post_meta( $product_id, 'wplit_product_description', true ),
			(string) get_post_meta( $product_id, 'wplit_product_logo_url', true ),
			(string) get_post_meta( $product_id, 'wplit_product_banner_url', true ),
			null !== $this->package_file( $product_id )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $product_id Post ID of the wplit_product.
	 */
	public function api_key( int $product_id ): string {
		return (string) get_post_meta( $product_id, 'wplit_product_api_key', true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only files inside uploads/wplit-files are served, whatever the post meta says.
	 *
	 * @param int $product_id Post ID of the wplit_product.
	 */
	public function package_file( int $product_id ): ?string {
		$relative = (string) get_post_meta( $product_id, 'file_dir_path', true );
		if ( '' === $relative ) {
			return null;
		}

		$uploads = wp_upload_dir();
		$root    = realpath( $uploads['basedir'] . '/wplit-files' );
		$file    = realpath( $uploads['basedir'] . '/' . $relative );

		if ( false === $root || false === $file || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) ) {
			return null;
		}

		return $file;
	}
}
