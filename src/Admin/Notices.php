<?php
/**
 * Admin notices.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

/**
 * Shows the result of an action after the redirect. Only fixed messages are ever shown:
 * the URL carries a short code, never text.
 */
final class Notices {

	/**
	 * Success messages by code.
	 *
	 * @return array<string, string>
	 */
	public static function messages(): array {
		return array(
			'revoked'     => __( 'License revoked.', 'wplicense-it' ),
			'refunded'    => __( 'License marked as refunded.', 'wplicense-it' ),
			'reinstated'  => __( 'License reinstated.', 'wplicense-it' ),
			'renewed'     => __( 'License renewed.', 'wplicense-it' ),
			'updated'     => __( 'License updated.', 'wplicense-it' ),
			'deactivated' => __( 'Site deactivated.', 'wplicense-it' ),
			'issued'      => __( 'License issued.', 'wplicense-it' ),
			'emailed'     => __( 'License issued and emailed to the customer.', 'wplicense-it' ),
		);
	}

	/**
	 * Error messages by code.
	 *
	 * @return array<string, string>
	 */
	public static function errors(): array {
		return array(
			'not_found'     => __( 'That license no longer exists.', 'wplicense-it' ),
			'invalid_input' => __( 'Some of the values were not valid. Nothing was changed.', 'wplicense-it' ),
			'not_renewable' => __( 'A revoked or refunded license cannot be renewed. Reinstate it first.', 'wplicense-it' ),
			'no_site'       => __( 'That site is not active on this license.', 'wplicense-it' ),
			'no_user'       => __( 'No user was found for that username or email.', 'wplicense-it' ),
			'no_product'    => __( 'Choose a published license product.', 'wplicense-it' ),
		);
	}

	/**
	 * Prints the notice for the current request, if any.
	 */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only selects one of the fixed messages above.
		$ok    = isset( $_GET['wplit_notice'] ) ? sanitize_key( wp_unslash( $_GET['wplit_notice'] ) ) : '';
		$error = isset( $_GET['wplit_error'] ) ? sanitize_key( wp_unslash( $_GET['wplit_error'] ) ) : '';
		$count = isset( $_GET['wplit_count'] ) ? absint( wp_unslash( $_GET['wplit_count'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = self::messages();
		$errors   = self::errors();

		if ( 'bulk' === $ok ) {
			/* translators: %d: number of licenses. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d license updated.', '%d licenses updated.', $count, 'wplicense-it' ), $count ) ) );
		} elseif ( isset( $messages[ $ok ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $ok ] ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $error ] ?? __( 'Something went wrong.', 'wplicense-it' ) ) );
		}
	}
}
