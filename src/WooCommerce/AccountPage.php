<?php
/**
 * My Account "Licenses" page.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Api\ApiService;
use Devllo\WPLicenseIt\Api\DownloadSigner;
use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;

/**
 * Lets customers see their licenses, download the package, renew, and free a site.
 */
final class AccountPage {

	public const ENDPOINT          = 'licenses';
	private const DEACTIVATE_ACTION = 'wplit_deactivate_site';
	private const FLUSH_OPTION     = 'wplit_woo_endpoint_version';

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 * @param ProductCatalog $products Product lookup.
	 * @param DownloadSigner $signer   Download token signer.
	 */
	public function __construct(
		private LicenseService $licenses,
		private ProductCatalog $products,
		private DownloadSigner $signer
	) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DEACTIVATE_ACTION, array( $this, 'handle_deactivate' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite_rules' ) );
	}

	/**
	 * Registers the endpoint with WooCommerce.
	 *
	 * @param array<string, string> $vars Endpoint query variables.
	 * @return array<string, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Adds "Licenses" to the My Account menu, before "Log out".
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		$menu = array();

		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$menu[ self::ENDPOINT ] = __( 'Licenses', 'wplicense-it' );
			}
			$menu[ $key ] = $label;
		}

		if ( ! isset( $menu[ self::ENDPOINT ] ) ) {
			$menu[ self::ENDPOINT ] = __( 'Licenses', 'wplicense-it' );
		}

		return $menu;
	}

	/**
	 * Makes the new endpoint reachable, once.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::FLUSH_OPTION ) !== '1' ) {
			flush_rewrite_rules();
			update_option( self::FLUSH_OPTION, '1' );
		}
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a message to show.
		$notice = isset( $_GET['wplit_notice'] ) ? sanitize_key( wp_unslash( $_GET['wplit_notice'] ) ) : '';

		if ( 'deactivated' === $notice ) {
			wc_print_notice( __( 'The site was deactivated. You can use the license on another site.', 'wplicense-it' ), 'success' );
		} elseif ( 'failed' === $notice ) {
			wc_print_notice( __( 'That site could not be deactivated.', 'wplicense-it' ), 'error' );
		}

		$licenses = $this->licenses->licenses_for_user( get_current_user_id() );

		if ( array() === $licenses ) {
			wc_print_notice( __( 'You have no licenses yet.', 'wplicense-it' ), 'notice' );

			return;
		}

		foreach ( $licenses as $license ) {
			$this->render_license( $license );
		}
	}

	/**
	 * Renders one license.
	 *
	 * @param License $license License.
	 */
	private function render_license( License $license ): void {
		$product = $this->products->find( $license->product_id );
		$name    = $product ? $product->name : get_the_title( $license->product_id );
		$sites   = $this->licenses->active_sites( $license );
		$used    = $this->licenses->activations_used( $license );
		$limit   = 0 === $license->activation_limit ? __( 'unlimited', 'wplicense-it' ) : (string) $license->activation_limit;
		$usable  = $license->is_usable( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );

		echo '<div class="wplit-license" style="margin-bottom: 2em;">';
		echo '<h3>' . esc_html( $name ) . '</h3>';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		echo '<tr><th>' . esc_html__( 'License key', 'wplicense-it' ) . '</th><td><code>' . esc_html( $license->license_key ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'wplicense-it' ) . '</th><td>' . esc_html( $this->status_label( $license, $usable ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Expires', 'wplicense-it' ) . '</th><td>' . esc_html( null === $license->expires_at ? __( 'Never', 'wplicense-it' ) : wp_date( get_option( 'date_format' ), $license->expires_at->getTimestamp() ) ) . '</td></tr>';
		/* translators: 1: sites in use, 2: sites allowed. */
		echo '<tr><th>' . esc_html__( 'Sites', 'wplicense-it' ) . '</th><td>' . esc_html( sprintf( __( '%1$d of %2$s in use', 'wplicense-it' ), $used, $limit ) ) . $this->sites_list( $license, $sites ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<p>';
		if ( $usable && $product && $product->has_package ) {
			$token = $this->signer->sign( $license->id, $license->product_id, ApiService::DOWNLOAD_TTL );
			echo '<a class="button" href="' . esc_url( add_query_arg( 'token', rawurlencode( $token ), rest_url( 'wplicense-it/v1/download' ) ) ) . '">' . esc_html__( 'Download', 'wplicense-it' ) . '</a> ';
		}

		$woo_product = in_array( $license->status, Status::terminal(), true ) || null === $license->expires_at ? 0 : $this->renewal_product( $license->product_id );
		if ( $woo_product > 0 ) {
			echo '<a class="button alt" href="' . esc_url( CartRenewal::renewal_url( $woo_product, $license->id ) ) . '">' . esc_html__( 'Renew', 'wplicense-it' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Status text.
	 *
	 * @param License $license License.
	 * @param bool    $usable  Whether it can be used now.
	 */
	private function status_label( License $license, bool $usable ): string {
		if ( $usable ) {
			return __( 'Active', 'wplicense-it' );
		}

		if ( Status::REFUNDED === $license->status ) {
			return __( 'Refunded', 'wplicense-it' );
		}

		if ( Status::REVOKED === $license->status ) {
			return __( 'Revoked', 'wplicense-it' );
		}

		return __( 'Expired', 'wplicense-it' );
	}

	/**
	 * The sites a license is active on, each with a Deactivate button.
	 *
	 * @param License                                       $license License.
	 * @param \Devllo\WPLicenseIt\Licenses\Activation[]     $sites   Active sites.
	 */
	private function sites_list( License $license, array $sites ): string {
		if ( array() === $sites ) {
			return '';
		}

		$html = '<ul style="margin: 0.5em 0 0;">';

		foreach ( $sites as $site ) {
			$html .= '<li>' . esc_html( $site->site ) . ' ';
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display: inline;">';
			$html .= '<input type="hidden" name="action" value="' . esc_attr( self::DEACTIVATE_ACTION ) . '">';
			$html .= '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license->id ) . '">';
			$html .= '<input type="hidden" name="site" value="' . esc_attr( $site->site ) . '">';
			$html .= wp_nonce_field( self::DEACTIVATE_ACTION . '_' . $license->id, '_wpnonce', true, false );
			$html .= '<button type="submit" class="button button-small">' . esc_html__( 'Deactivate', 'wplicense-it' ) . '</button>';
			$html .= '</form></li>';
		}

		return $html . '</ul>';
	}

	/**
	 * A simple WooCommerce product that sells the license product, for the Renew button.
	 *
	 * Variable products are not offered here: the customer renews from the product page.
	 *
	 * @param int $license_product_id WPLicense It product ID.
	 * @return int WooCommerce product ID, 0 if none.
	 */
	private function renewal_product( int $license_product_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'meta_key'       => OrderReader::META_PRODUCT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of products, shown on a customer page.
				'meta_value'     => $license_product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( $product && $product->is_type( 'simple' ) && $product->is_purchasable() ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Handles the Deactivate button.
	 */
	public function handle_deactivate(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0;

		check_admin_referer( self::DEACTIVATE_ACTION . '_' . $license_id );

		$license = is_user_logged_in() ? $this->licenses->find( $license_id ) : null;
		$site    = isset( $_POST['site'] ) ? sanitize_text_field( wp_unslash( $_POST['site'] ) ) : '';
		$result  = null;

		// Only the license's owner may free a site.
		if ( $license && $license->user_id === get_current_user_id() && '' !== $site ) {
			$result = $this->licenses->deactivate( $license->license_key, $site );
		}

		$notice = null !== $result && $result->is_success() ? 'deactivated' : 'failed';
		$back   = wc_get_account_endpoint_url( self::ENDPOINT );

		wp_safe_redirect( add_query_arg( 'wplit_notice', $notice, $back ) );
		exit;
	}
}
