<?php
/**
 * Add license screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\WooCommerce\ProductFields;

/**
 * Form for issuing a license by hand: gifts, support, migrations from other systems.
 */
final class AddLicenseScreen {

	/**
	 * Renders the page. The form posts to LicenseActions.
	 */
	public function render(): void {
		Capabilities::require_manage();

		$products = get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Add license', 'wplicense-it' ) . '</h1>';
		Notices::render();

		if ( array() === $products ) {
			echo '<p>' . esc_html__( 'Create and publish a license product first.', 'wplicense-it' ) . ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=wplit_product' ) ) . '">' . esc_html__( 'Add a product', 'wplicense-it' ) . '</a></p></div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Urls::form_fields( 'add', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in form_fields().
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wplit-product">' . esc_html__( 'Product', 'wplicense-it' ) . '</label></th><td><select id="wplit-product" name="product_id" required>';
		foreach ( $products as $product ) {
			echo '<option value="' . esc_attr( (string) $product->ID ) . '">' . esc_html( $product->post_title ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="wplit-email">' . esc_html__( 'Customer email', 'wplicense-it' ) . '</label></th><td><input type="email" id="wplit-email" name="email" class="regular-text"> <span class="description">' . esc_html__( 'Required unless you choose a user below.', 'wplicense-it' ) . '</span></td></tr>';

		echo '<tr><th scope="row"><label for="wplit-user">' . esc_html__( 'WordPress user', 'wplicense-it' ) . '</label></th><td><input type="text" id="wplit-user" name="user" class="regular-text" autocomplete="off"> <span class="description">' . esc_html__( 'Optional. A username or email. The license then shows in that customer\'s account.', 'wplicense-it' ) . '</span></td></tr>';

		echo '<tr><th scope="row"><label for="wplit-limit">' . esc_html__( 'Sites per license', 'wplicense-it' ) . '</label></th><td><input type="number" min="0" step="1" id="wplit-limit" name="activation_limit" class="small-text" placeholder="1"> <span class="description">' . esc_html__( 'Empty uses the product default (1). 0 is unlimited.', 'wplicense-it' ) . '</span></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Expiry', 'wplicense-it' ) . '</th><td>';
		echo '<label><input type="radio" name="expiry_mode" value="lifetime" checked> ' . esc_html__( 'Never expires', 'wplicense-it' ) . '</label><br>';
		echo '<label><input type="radio" name="expiry_mode" value="period"> ' . esc_html__( 'Expires after', 'wplicense-it' ) . ' <select name="period">';
		foreach ( ProductFields::periods() as $value => $label ) {
			if ( '' !== $value && 'lifetime' !== $value ) {
				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
		}
		echo '</select></label><br>';
		echo '<label><input type="radio" name="expiry_mode" value="date"> ' . esc_html__( 'Valid through', 'wplicense-it' ) . ' <input type="date" name="expiry_date"></label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Email', 'wplicense-it' ) . '</th><td><label><input type="checkbox" name="send_email" value="1"> ' . esc_html__( 'Email the license key to the customer', 'wplicense-it' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Issue license', 'wplicense-it' ) );
		echo '</form></div>';
	}
}
