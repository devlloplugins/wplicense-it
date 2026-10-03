<?php
/**
 * WooCommerce product editor fields.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use WP_Post;

/**
 * Adds a "WPLicense It" section to WooCommerce products and variations:
 * which license product the WooCommerce product sells, how many sites a license covers, and for how long.
 *
 * Empty values mean "use the next level up": variation, then product, then the license product's defaults.
 */
final class ProductFields {

	/**
	 * Period choices. Anything else is rejected on save.
	 *
	 * @return array<string, string>
	 */
	public static function periods(): array {
		return array(
			''         => __( 'Use the license product default', 'wplicense-it' ),
			'lifetime' => __( 'Lifetime (never expires)', 'wplicense-it' ),
			'P1M'      => __( '1 month', 'wplicense-it' ),
			'P3M'      => __( '3 months', 'wplicense-it' ),
			'P6M'      => __( '6 months', 'wplicense-it' ),
			'P1Y'      => __( '1 year', 'wplicense-it' ),
			'P2Y'      => __( '2 years', 'wplicense-it' ),
		);
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );
	}

	/**
	 * Fields on the product's General tab.
	 */
	public function render_product_fields(): void {
		$options = array( '' => __( 'Not a licensed product', 'wplicense-it' ) );

		foreach ( $this->license_products() as $license_product ) {
			$options[ (string) $license_product->ID ] = $license_product->post_title;
		}

		echo '<div class="options_group">';

		woocommerce_wp_select(
			array(
				'id'          => OrderReader::META_PRODUCT,
				'label'       => __( 'WPLicense It product', 'wplicense-it' ),
				'description' => __( 'Buying this product issues a license for the selected plugin or theme. For best results also tick Virtual.', 'wplicense-it' ),
				'desc_tip'    => true,
				'options'     => $options,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => OrderReader::META_LIMIT,
				'label'             => __( 'Sites per license', 'wplicense-it' ),
				'description'       => __( 'Leave empty to use the license product default (1). Enter 0 for unlimited.', 'wplicense-it' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => OrderReader::META_PERIOD,
				'label'   => __( 'License period', 'wplicense-it' ),
				'options' => self::periods(),
			)
		);

		echo '</div>';
	}

	/**
	 * Saves the product fields. WooCommerce has verified the nonce and the user's capability.
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_fields( int $product_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook runs.
		$linked = isset( $_POST[ OrderReader::META_PRODUCT ] ) ? absint( wp_unslash( $_POST[ OrderReader::META_PRODUCT ] ) ) : 0;
		$limit  = isset( $_POST[ OrderReader::META_LIMIT ] ) ? sanitize_text_field( wp_unslash( $_POST[ OrderReader::META_LIMIT ] ) ) : '';
		$period = isset( $_POST[ OrderReader::META_PERIOD ] ) ? sanitize_text_field( wp_unslash( $_POST[ OrderReader::META_PERIOD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$product->update_meta_data( OrderReader::META_PRODUCT, $this->is_license_product( $linked ) ? $linked : '' );
		$product->update_meta_data( OrderReader::META_LIMIT, self::clean_limit( $limit ) );
		$product->update_meta_data( OrderReader::META_PERIOD, self::clean_period( $period ) );
		$product->save();
	}

	/**
	 * Fields on each variation.
	 *
	 * @param int     $loop           Position in the variations list.
	 * @param mixed[] $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function render_variation_fields( int $loop, array $variation_data, WP_Post $variation ): void {
		$limit  = (string) get_post_meta( $variation->ID, OrderReader::META_LIMIT, true );
		$period = (string) get_post_meta( $variation->ID, OrderReader::META_PERIOD, true );

		echo '<div>';

		woocommerce_wp_text_input(
			array(
				'id'                => OrderReader::META_LIMIT . '_' . $loop,
				'name'              => OrderReader::META_LIMIT . '[' . $loop . ']',
				'label'             => __( 'Sites per license', 'wplicense-it' ),
				'description'       => __( 'Empty uses the product setting. 0 is unlimited.', 'wplicense-it' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'value'             => $limit,
				'wrapper_class'     => 'form-row form-row-first',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => OrderReader::META_PERIOD . '_' . $loop,
				'name'          => OrderReader::META_PERIOD . '[' . $loop . ']',
				'label'         => __( 'License period', 'wplicense-it' ),
				'value'         => $period,
				'options'       => self::periods(),
				'wrapper_class' => 'form-row form-row-last',
			)
		);

		echo '</div>';
	}

	/**
	 * Saves the variation fields. WooCommerce has verified the nonce and the user's capability.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Position in the variations list.
	 */
	public function save_variation_fields( int $variation_id, int $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before this hook runs.
		$limit  = isset( $_POST[ OrderReader::META_LIMIT ][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST[ OrderReader::META_LIMIT ][ $loop ] ) ) : '';
		$period = isset( $_POST[ OrderReader::META_PERIOD ][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST[ OrderReader::META_PERIOD ][ $loop ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}

		$variation->update_meta_data( OrderReader::META_LIMIT, self::clean_limit( $limit ) );
		$variation->update_meta_data( OrderReader::META_PERIOD, self::clean_period( $period ) );
		$variation->save();
	}

	/**
	 * Normalises a sites-per-license value: a whole number from 0, or empty for "inherit".
	 *
	 * @param string $value Submitted value.
	 */
	public static function clean_limit( string $value ): string {
		$value = trim( $value );

		return '' !== $value && ctype_digit( $value ) ? (string) (int) $value : '';
	}

	/**
	 * Accepts only the period choices above, otherwise "inherit".
	 *
	 * @param string $value Submitted value.
	 */
	public static function clean_period( string $value ): string {
		return '' !== $value && array_key_exists( $value, self::periods() ) ? $value : '';
	}

	/**
	 * Published license products.
	 *
	 * @return WP_Post[]
	 */
	private function license_products(): array {
		return get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Whether a post ID is a published license product.
	 *
	 * @param int $post_id Post ID.
	 */
	private function is_license_product( int $post_id ): bool {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		return $post && 'wplit_product' === $post->post_type && 'publish' === $post->post_status;
	}
}
