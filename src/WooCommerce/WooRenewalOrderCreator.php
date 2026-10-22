<?php
/**
 * Renewal invoices as WooCommerce orders.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Renewals\RenewalOrderCreator;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Makes an unpaid WooCommerce order for the renewal of one license, marked as a renewal of that license, and sends the customer
 * WooCommerce's own invoice email with the link to pay it. When the order is paid and reaches the status that issues licenses,
 * the existing renewal logic extends the license; no second license is made.
 */
final class WooRenewalOrderCreator implements RenewalOrderCreator {

	public const META_LICENSE = '_wplit_invoice_license_id';
	public const META_EXPIRES = '_wplit_invoice_expires_at';
	public const CREATED_VIA  = 'wplicense_renewal';

	/**
	 * Constructor.
	 *
	 * @param OrderReader $reader Order reader, for the licensing terms of a product.
	 */
	public function __construct( private OrderReader $reader ) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $license_product_id WPLicense It product ID.
	 */
	public function sells( int $license_product_id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( RenewalProduct::find( $license_product_id ) );

		return $product && (float) $product->get_price() > 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param License $license The license.
	 */
	public function create( License $license ): ?int {
		$product = wc_get_product( RenewalProduct::for_license( $license ) );

		if ( ! $product || (float) $product->get_price() <= 0 || null === $license->expires_at ) {
			return null;
		}

		try {
			$order = wc_create_order(
				array(
					'customer_id' => (int) $license->user_id,
					'status'      => 'pending',
					'created_via' => self::CREATED_VIA,
				)
			);

			if ( ! $order instanceof WC_Order ) {
				return null;
			}

			$item_id = $order->add_product( $product, 1 );
			$item    = $item_id ? $order->get_item( (int) $item_id ) : null;

			if ( ! $item instanceof WC_Order_Item_Product ) {
				$order->delete( true );

				return null;
			}

			$terms = $this->reader->current_terms( $product, 0 );
			$item->update_meta_data( OrderReader::META_PRODUCT, $terms['product'] );
			$item->update_meta_data( OrderReader::META_LIMIT, $terms['limit'] );
			$item->update_meta_data( OrderReader::META_PERIOD, $terms['period'] );
			$item->update_meta_data( OrderReader::META_RENEW, $license->id );
			$item->save();

			if ( (int) $license->user_id > 0 && class_exists( 'WC_Customer' ) ) {
				$order->set_address( ( new \WC_Customer( (int) $license->user_id ) )->get_billing(), 'billing' );
			}

			$order->set_billing_email( $license->email );
			$order->update_meta_data( self::META_LICENSE, $license->id );
			$order->update_meta_data( self::META_EXPIRES, $license->expires_at->format( DATE_ATOM ) );
			$order->calculate_totals();
			$order->add_order_note(
				sprintf(
					/* translators: 1: license ID, 2: expiry date. */
					__( 'Renewal invoice for license #%1$d, which expires on %2$s. When this order is paid and completed, the license is renewed.', 'wplicense-it' ),
					$license->id,
					wp_date( 'Y-m-d', $license->expires_at->getTimestamp() )
				)
			);
			$order->save();

			$this->send_invoice( $order );

			return (int) $order->get_id();
		} catch ( Throwable $e ) {
			error_log( 'WPLicense It renewal invoice error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need to see this.

			return null;
		}//end try
	}

	/**
	 * Sends WooCommerce's invoice email, with the link to pay the order.
	 *
	 * @param WC_Order $order The order.
	 */
	private function send_invoice( WC_Order $order ): void {
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['WC_Email_Customer_Invoice'] ?? null;

		if ( $email instanceof \WC_Email_Customer_Invoice ) {
			$email->trigger( $order->get_id(), $order );
			$order->add_order_note( __( 'Invoice emailed to the customer.', 'wplicense-it' ) );
		}
	}

	/**
	 * Cancels the unpaid renewal invoices of the licenses an order has just renewed, so the customer is not asked to pay twice.
	 *
	 * @param WC_Order $order The order that renewed licenses.
	 */
	public static function cancel_open_invoices( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			foreach ( WooItemLicenseStore::ids( $item ) as $license_id ) {
				// Not a meta query: WooCommerce's classic order storage ignores one, which would return every pending order.
				// The orders this plugin made carry its own "created via" value; the license is checked on each.
				$open = wc_get_orders(
					array(
						'status'      => array( 'pending' ),
						'limit'       => 50,
						'exclude'     => array( $order->get_id() ),
						'created_via' => self::CREATED_VIA,
					)
				);

				foreach ( $open as $invoice ) {
					if ( $invoice instanceof WC_Order && $license_id === (int) $invoice->get_meta( self::META_LICENSE, true ) ) {
						/* translators: %s: order number. */
						$invoice->update_status( 'cancelled', sprintf( __( 'Cancelled: the license was renewed by order %s.', 'wplicense-it' ), $order->get_order_number() ) );
					}
				}
			}//end foreach
		}//end foreach
	}
}
