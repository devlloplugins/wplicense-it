<?php
/**
 * Shortcodes from 1.x.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Compat;

use Devllo\WPLicenseIt\Admin\Capabilities;
use Devllo\WPLicenseIt\Api\ApiService;
use Devllo\WPLicenseIt\Api\DownloadSigner;
use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\Licenses\LicenseService;

/**
 * 1.x pages used [wplit-product], [wplit-checkout] and [wplit-licenses].
 *
 * - [wplit-licenses] keeps working: it lists the logged-in customer's licenses with a download link.
 * - [wplit-product] and [wplit-checkout] belonged to the removed built-in checkout. They print
 *   nothing to visitors (so pages do not show raw shortcode text) and tell administrators what to do.
 */
final class LegacyShortcodes {

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 * @param ProductCatalog $products Product lookup.
	 * @param DownloadSigner $signer   Download link signer.
	 */
	public function __construct(
		private LicenseService $licenses,
		private ProductCatalog $products,
		private DownloadSigner $signer
	) {
	}

	/**
	 * Registers the shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'wplit-licenses', array( $this, 'licenses' ) );
		add_shortcode( 'wplit-product', array( $this, 'removed' ) );
		add_shortcode( 'wplit-checkout', array( $this, 'removed' ) );
	}

	/**
	 * [wplit-licenses]: the customer's licenses.
	 */
	public function licenses(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to see your licenses.', 'wplicense-it' ) . '</p>';
		}

		$licenses = $this->licenses->licenses_for_user( get_current_user_id() );

		if ( array() === $licenses ) {
			return '<p>' . esc_html__( 'You have no licenses yet.', 'wplicense-it' ) . '</p>';
		}

		$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$html = '';

		foreach ( $licenses as $license ) {
			$product = $this->products->find( $license->product_id );
			$name    = $product ? $product->name : get_the_title( $license->product_id );

			$html .= '<div class="wplit-license" style="margin-bottom: 1.5em;">';
			$html .= '<div><strong>' . esc_html( $name ) . '</strong></div>';
			$html .= '<div>' . esc_html__( 'License key', 'wplicense-it' ) . ': <code>' . esc_html( $license->license_key ) . '</code></div>';
			$html .= '<div>' . esc_html__( 'Expires', 'wplicense-it' ) . ': ' . esc_html( null === $license->expires_at ? __( 'Never', 'wplicense-it' ) : wp_date( get_option( 'date_format' ), $license->expires_at->getTimestamp() ) ) . '</div>';

			if ( $license->is_usable( $now ) && $product && $product->has_package ) {
				$token = $this->signer->sign( $license->id, $license->product_id, ApiService::DOWNLOAD_TTL );
				$html .= '<div><a href="' . esc_url( add_query_arg( 'token', rawurlencode( $token ), rest_url( 'wplicense-it/v1/download' ) ) ) . '">' . esc_html__( 'Download', 'wplicense-it' ) . '</a></div>';
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * [wplit-product] and [wplit-checkout]: removed in 2.0.
	 *
	 * @param mixed  $atts    Attributes.
	 * @param string $content Content.
	 * @param string $tag     Shortcode name.
	 */
	public function removed( $atts = array(), string $content = '', string $tag = '' ): string {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return '';
		}

		/* translators: %s: shortcode name. */
		return '<p style="border: 1px solid #dba617; padding: 8px;"><strong>WPLicense It:</strong> ' . esc_html( sprintf( __( 'The [%s] shortcode was removed in 2.0, because the built-in checkout is gone. Sell licenses with WooCommerce instead. Only administrators see this message.', 'wplicense-it' ), $tag ) ) . '</p>';
	}
}
