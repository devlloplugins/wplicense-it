<?php
/**
 * Connects the product form to the saver.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Packages\UploadReport;
use Devllo\WPLicenseIt\Products\ProductSaver;
use Devllo\WPLicenseIt\Products\WpFileStore;
use Devllo\WPLicenseIt\Products\WpProductMeta;
use WP_Post;

/**
 * The WordPress side of saving a product: it checks the nonce and the user's rights, hands the form to the
 * ProductSaver and keeps the result for the next page load. It also lets the edit form carry files.
 */
final class ProductSaveHook {

	public const NONCE_FIELD  = 'wplit_inner_custom_box_nonce';
	public const NONCE_ACTION = 'wplit_inner_custom_box';

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'save_post_wplit_product', array( $this, 'save' ), 1, 2 );
		add_action( 'post_edit_form_tag', array( $this, 'form_tag' ) );
		add_action( 'admin_notices', array( UploadReport::class, 'render' ) );
	}

	/**
	 * Lets the form of a product carry files. Other post types are left alone.
	 *
	 * @param mixed $post The post being edited.
	 */
	public function form_tag( $post ): void {
		if ( $post instanceof WP_Post && 'wplit_product' === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Saves the product form.
	 *
	 * @param int     $post_id License product ID.
	 * @param WP_Post $post    The post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// A save that is not a form submission (autosave) or not allowed for this user does nothing.
		if ( 'wplit_product' !== $post->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$saver  = new ProductSaver( new WpProductMeta(), new WpFileStore() );
		$report = $saver->save( $post_id, (array) wp_unslash( $_POST ), $_FILES ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is cleaned by ProductSaver.

		if ( ! $report->is_empty() ) {
			UploadReport::queue( $report );
		}
	}
}
