<?php
/**
 * Notice about the removed checkout.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

/**
 * Tells administrators of sites that used the 1.x checkout that it is gone, and offers to delete the
 * Stripe keys it stored. The keys stay in the database until the administrator says otherwise, because
 * the shop owner may still need to copy them into a WooCommerce payment gateway.
 */
final class UpgradeNotice {

	private const ACTION    = 'wplit_legacy_checkout_notice';
	private const DISMISSED = 'wplit_checkout_notice_dismissed';

	/**
	 * Options the 1.x checkout stored.
	 *
	 * @return string[]
	 */
	public static function legacy_options(): array {
		return array(
			'wplit-checkout-page',
			'wplit-licenses-page',
			'wplit-stripe-settings-test-mode',
			'wplit-stripe-settings-live-pk',
			'wplit-stripe-settings-live-sk',
			'wplit-stripe-settings-test-pk',
			'wplit-stripe-settings-test-sk',
		);
	}

	/**
	 * Registers the notice and its buttons.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Whether the 1.x checkout left settings behind.
	 */
	private function has_legacy_settings(): bool {
		foreach ( self::legacy_options() as $option ) {
			if ( false !== get_option( $option, false ) && '' !== get_option( $option ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || get_option( self::DISMISSED ) || ! $this->has_legacy_settings() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'WPLicense It 2.0 no longer has its own checkout.', 'wplicense-it' ) . '</strong> ';
		esc_html_e( 'Sell licenses with WooCommerce instead: link a WooCommerce product to your license product. Pages that used the old product and checkout shortcodes now show nothing to visitors. Your Stripe keys from the old checkout are still stored. Copy them into your WooCommerce payment gateway if you need them, then delete them here.', 'wplicense-it' );
		echo '</p><p>';

		$this->button( 'delete', __( 'Delete the stored Stripe keys and old checkout settings', 'wplicense-it' ), true );
		$this->button( 'dismiss', __( 'Dismiss', 'wplicense-it' ), false );

		echo '</p></div>';
	}

	/**
	 * Prints one button.
	 *
	 * @param string $mode  delete or dismiss.
	 * @param string $label Label.
	 * @param bool   $warn  Ask for confirmation first.
	 */
	private function button( string $mode, string $label, bool $warn ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display: inline-block; margin-right: 8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<button type="submit" class="button"' . ( $warn ? ' onclick="return confirm(\'' . esc_js( __( 'Delete the stored Stripe keys? This cannot be undone.', 'wplicense-it' ) ) . '\');"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Handles the buttons.
	 */
	public function handle(): void {
		Capabilities::require_manage();
		check_admin_referer( self::ACTION );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

		if ( 'delete' === $mode ) {
			foreach ( self::legacy_options() as $option ) {
				delete_option( $option );
			}
		}

		update_option( self::DISMISSED, '1' );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
