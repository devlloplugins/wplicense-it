<?php
/**
 * License keys on order pages and emails.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Shows the licenses an order produced to the customer: on the order page and in the "processing" and "completed" emails,
 * with the sites each covers, where to manage them and how to activate. While an order still waits for its licenses (paid
 * by bank transfer, say) its emails say the key follows. When a license is issued late, after an administrator fixed a
 * problem and pressed Try again, the customer gets an email with it. Never shown in administrators' copies of emails.
 */
final class LicenseDisplay {

	public const LATE_ACTION = 'wplicense_it_licenses_issued_late';

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 * @param OrderReader    $reader   Reads which order lines sell a license.
	 */
	public function __construct( private LicenseService $licenses, private OrderReader $reader ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 10, 4 );
		add_action( self::LATE_ACTION, array( $this, 'send_late' ) );
	}

	/**
	 * Licenses on the order page and thank-you page.
	 *
	 * @param WC_Order $order Order.
	 */
	public function render_order_page( WC_Order $order ): void {
		$rows = $this->rows( $order );

		if ( array() === $rows ) {
			if ( $this->waiting( $order ) ) {
				echo '<section class="wplit-order-licenses"><h2>' . esc_html__( 'Your licenses', 'wplicense-it' ) . '</h2><p>' . esc_html( self::waiting_text() ) . '</p></section>';
			}

			return;
		}

		echo '<section class="wplit-order-licenses"><h2>' . esc_html__( 'Your licenses', 'wplicense-it' ) . '</h2>';
		echo $this->table( $rows, 'woocommerce-table shop_table', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in table().
		echo $this->help_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_html().
		echo '</section>';
	}

	/**
	 * Licenses in customer emails, or the note that they follow.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the shop owner.
	 * @param bool     $plain_text    Whether this is the plain text version.
	 * @param WC_Email $email         Email object.
	 */
	public function render_email( WC_Order $order, bool $sent_to_admin, bool $plain_text, WC_Email $email ): void {
		if ( $sent_to_admin || ! in_array( $email->id, array( 'customer_completed_order', 'customer_processing_order', 'customer_on_hold_order' ), true ) ) {
			return;
		}

		$rows = 'customer_on_hold_order' === $email->id ? array() : $this->rows( $order );

		if ( array() === $rows ) {
			if ( $this->waiting( $order ) ) {
				echo $plain_text // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
					? "\n" . esc_html( self::waiting_text() ) . "\n\n"
					: '<h2>' . esc_html__( 'Your licenses', 'wplicense-it' ) . '</h2><p>' . esc_html( self::waiting_text() ) . '</p>';
			}

			return;
		}

		if ( $plain_text ) {
			echo $this->plain( $order, $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in plain().

			return;
		}

		echo '<h2>' . esc_html__( 'Your licenses', 'wplicense-it' ) . '</h2>';
		echo $this->table( $rows, '', 'width: 100%; border: 1px solid #e5e5e5; margin-bottom: 20px;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in table().
		echo $this->help_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_html().
	}

	/**
	 * Emails the customer the licenses of an order that got them late (after Try again), in WooCommerce's email layout.
	 *
	 * @param WC_Order $order Order.
	 */
	public function send_late( WC_Order $order ): void {
		$rows = $this->rows( $order );
		$to   = (string) $order->get_billing_email();

		if ( array() === $rows || '' === $to || ! function_exists( 'WC' ) ) {
			return;
		}

		/* translators: %s: order number. */
		$subject = sprintf( __( 'Your license for order #%s', 'wplicense-it' ), $order->get_order_number() );
		$heading = __( 'Your license is ready', 'wplicense-it' );
		$intro   = '<p>' . esc_html( sprintf( /* translators: %s: order number. */ __( 'Sorry for the wait: the license for your order #%s is ready.', 'wplicense-it' ), $order->get_order_number() ) ) . '</p>';
		$body    = $intro . $this->table( $rows, '', 'width: 100%; border: 1px solid #e5e5e5; margin-bottom: 20px;' ) . $this->help_html( $order );

		$mailer = WC()->mailer();
		$mailer->send( $to, $subject, $mailer->wrap_message( $heading, $body ), "Content-Type: text/html; charset=UTF-8\r\n" );

		$order->add_order_note( __( 'WPLicense It: the license key was emailed to the customer.', 'wplicense-it' ) );
	}

	/**
	 * The licenses as a table: product, key, sites, expiry.
	 *
	 * @param array<int, array{product:string,key:string,sites:string,expires:string}> $rows  Rows.
	 * @param string                                                                   $class Table class.
	 * @param string                                                                   $style Inline style (emails).
	 */
	private function table( array $rows, string $class, string $style ): string {
		$html  = '<table' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : ' cellspacing="0" cellpadding="6" border="1"' ) . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		$html .= '<thead><tr><th>' . esc_html__( 'Product', 'wplicense-it' ) . '</th><th>' . esc_html__( 'License key', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Sites', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Expires', 'wplicense-it' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$html .= '<tr><td>' . esc_html( $row['product'] ) . '</td><td><code>' . esc_html( $row['key'] ) . '</code></td><td>' . esc_html( $row['sites'] ) . '</td><td>' . esc_html( $row['expires'] ) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * How to activate, and where to download, renew and manage.
	 *
	 * @param WC_Order $order Order.
	 */
	private function help_html( WC_Order $order ): string {
		$html = '<p>' . esc_html( self::activate_text() ) . '</p>';
		$url  = $this->account_url( $order );

		if ( '' !== $url ) {
			$html .= '<p>' . esc_html__( 'Download, renew and manage your licenses:', 'wplicense-it' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'My Account, Licenses', 'wplicense-it' ) . '</a></p>';
		}

		return $html;
	}

	/**
	 * The plain text email version.
	 *
	 * @param WC_Order                                                                 $order Order.
	 * @param array<int, array{product:string,key:string,sites:string,expires:string}> $rows  Rows.
	 */
	private function plain( WC_Order $order, array $rows ): string {
		$text = "\n" . esc_html__( 'Your licenses', 'wplicense-it' ) . "\n";

		foreach ( $rows as $row ) {
			$text .= esc_html( $row['product'] . ': ' . $row['key'] . ' (' . $row['sites'] . ', ' . $row['expires'] . ')' ) . "\n";
		}

		$text .= "\n" . esc_html( self::activate_text() ) . "\n";
		$url   = $this->account_url( $order );
		if ( '' !== $url ) {
			$text .= esc_html__( 'Download, renew and manage your licenses:', 'wplicense-it' ) . ' ' . esc_url_raw( $url ) . "\n";
		}

		return $text . "\n";
	}

	/**
	 * The My Account licenses page, for an order placed with an account.
	 *
	 * @param WC_Order $order Order.
	 */
	private function account_url( WC_Order $order ): string {
		return $order->get_customer_id() > 0 && function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( AccountPage::ENDPOINT ) : '';
	}

	/**
	 * How to activate a key.
	 */
	private static function activate_text(): string {
		return __( 'To activate, enter the license key on the plugin or theme\'s license page in your WordPress admin.', 'wplicense-it' );
	}

	/**
	 * What an order that waits for its licenses says.
	 */
	private static function waiting_text(): string {
		return __( 'Your license key will be emailed to you once your payment is confirmed and the order is complete.', 'wplicense-it' );
	}

	/**
	 * Whether the order sells a license that it has not issued yet.
	 *
	 * @param WC_Order $order Order.
	 */
	private function waiting( WC_Order $order ): bool {
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
			return false;
		}

		foreach ( $this->reader->read( $order )->items as $item ) {
			if ( $item->wplit_product_id > 0 && array() === $item->license_ids ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The active licenses an order produced.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{product:string,key:string,sites:string,expires:string}>
	 */
	private function rows( WC_Order $order ): array {
		$rows = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			foreach ( WooItemLicenseStore::ids( $item ) as $license_id ) {
				$license = $this->licenses->find( $license_id );

				if ( ! $license || in_array( $license->status, Status::terminal(), true ) ) {
					continue;
				}

				$rows[] = array(
					'product' => $item->get_name(),
					'key'     => $license->license_key,
					'sites'   => 0 === $license->activation_limit
						? __( 'Unlimited sites', 'wplicense-it' )
						/* translators: %d: number of sites. */
						: sprintf( _n( '%d site', '%d sites', $license->activation_limit, 'wplicense-it' ), $license->activation_limit ),
					'expires' => null === $license->expires_at
						? __( 'Never', 'wplicense-it' )
						: wp_date( get_option( 'date_format' ), $license->expires_at->getTimestamp() ),
				);
			}
		}//end foreach

		return $rows;
	}
}
