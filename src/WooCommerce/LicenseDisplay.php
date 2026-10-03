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
 * Shows the licenses an order produced to the customer: on the order page and in the
 * "processing" and "completed" emails. Never shown to administrators' copies of emails.
 */
final class LicenseDisplay {

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 10, 4 );
	}

	/**
	 * Licenses on the order page and thank-you page.
	 *
	 * @param WC_Order $order Order.
	 */
	public function render_order_page( WC_Order $order ): void {
		$rows = $this->rows( $order );

		if ( array() === $rows ) {
			return;
		}

		echo '<section class="wplit-order-licenses"><h2>' . esc_html__( 'Your licenses', 'wplicense-it' ) . '</h2>';
		echo '<table class="woocommerce-table shop_table"><thead><tr><th>' . esc_html__( 'Product', 'wplicense-it' ) . '</th><th>' . esc_html__( 'License key', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Expires', 'wplicense-it' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['product'] ) . '</td><td><code>' . esc_html( $row['key'] ) . '</code></td><td>' . esc_html( $row['expires'] ) . '</td></tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * Licenses in customer emails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the shop owner.
	 * @param bool     $plain_text    Whether this is the plain text version.
	 * @param WC_Email $email         Email object.
	 */
	public function render_email( WC_Order $order, bool $sent_to_admin, bool $plain_text, WC_Email $email ): void {
		if ( $sent_to_admin || ! in_array( $email->id, array( 'customer_completed_order', 'customer_processing_order' ), true ) ) {
			return;
		}

		$rows = $this->rows( $order );

		if ( array() === $rows ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Your licenses', 'wplicense-it' ) . "\n";
			foreach ( $rows as $row ) {
				echo esc_html( $row['product'] . ': ' . $row['key'] . ' (' . $row['expires'] . ')' ) . "\n";
			}
			echo "\n";

			return;
		}

		echo '<h2>' . esc_html__( 'Your licenses', 'wplicense-it' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5; margin-bottom: 20px;" border="1"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['product'] ) . '</td><td><code>' . esc_html( $row['key'] ) . '</code></td><td>' . esc_html( $row['expires'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The active licenses an order produced.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{product:string,key:string,expires:string}>
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
					'expires' => null === $license->expires_at
						? __( 'Never', 'wplicense-it' )
						: wp_date( get_option( 'date_format' ), $license->expires_at->getTimestamp() ),
				);
			}
		}

		return $rows;
	}
}
