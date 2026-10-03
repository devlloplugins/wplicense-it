<?php
/**
 * WooCommerce adapter.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Licenses\Status;
use Throwable;
use WC_Order;

/**
 * Connects WooCommerce to the licensing core: licenses are issued when an order reaches
 * "completed" (or "processing", see below), and ended when it is refunded or cancelled.
 *
 * Failures never break checkout or an order status change: they are logged and written
 * to the order as a note, and the order can simply be processed again.
 */
final class WooAdapter {

	public const OPTION_ISSUE_ON = 'wplit_woo_issue_on';
	private const LOCK_SECONDS   = 30;

	/**
	 * Constructor.
	 *
	 * @param OrderProcessor $processor Order logic.
	 * @param OrderReader    $reader    Order reader.
	 */
	public function __construct( private OrderProcessor $processor, private OrderReader $reader ) {
	}

	/**
	 * Declares compatibility with WooCommerce's high-performance order storage.
	 * Must run on before_woocommerce_init, so it is registered separately from register().
	 */
	public static function declare_hpos_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPLICENSE_IT_FILE, true );
		}
	}

	/**
	 * Registers the order hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
	}

	/**
	 * Statuses that trigger issuing. "completed" by default; set the wplit_woo_issue_on option to
	 * "processing" to issue as soon as payment is received. Filterable.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	public function issue_statuses( WC_Order $order ): array {
		$statuses = 'processing' === get_option( self::OPTION_ISSUE_ON, 'completed' ) ? array( 'processing', 'completed' ) : array( 'completed' );

		return (array) apply_filters( 'wplicense_it_woo_issue_statuses', $statuses, $order );
	}

	/**
	 * Order status changed.
	 *
	 * @param int           $order_id Order ID.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 */
	public function on_status_changed( int $order_id, string $from, string $to, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( in_array( $to, $this->issue_statuses( $order ), true ) ) {
			$this->process( $order, fn( OrderData $data ): ProcessReport => $this->processor->issue( $data ) );
		} elseif ( 'refunded' === $to ) {
			$this->process( $order, fn( OrderData $data ): ProcessReport => $this->processor->revoke( $data, Status::REFUNDED ) );
		} elseif ( 'cancelled' === $to ) {
			$this->process( $order, fn( OrderData $data ): ProcessReport => $this->processor->revoke( $data, Status::REVOKED ) );
		}
	}

	/**
	 * A refund was created. Ends licenses for the refunded units.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_refunded( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$this->process( $order, fn( OrderData $data ): ProcessReport => $this->processor->revoke_refunded_units( $data ) );
		}
	}

	/**
	 * Runs an operation for an order, with a short lock against duplicate events, and writes the outcome to the order.
	 *
	 * @param WC_Order $order     Order.
	 * @param callable $operation Receives OrderData, returns a ProcessReport.
	 */
	private function process( WC_Order $order, callable $operation ): void {
		$lock = 'wplit_woo_order_' . $order->get_id();

		if ( get_transient( $lock ) ) {
			return;
		}

		set_transient( $lock, 1, self::LOCK_SECONDS );

		try {
			$report = $operation( $this->reader->read( $order ) );

			if ( $report->changed() ) {
				$order->add_order_note( $this->note( $report ) );
			}
		} catch ( Throwable $e ) {
			error_log( 'WPLicense It: order ' . $order->get_id() . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need to see this.
			$order->add_order_note( 'WPLicense It error: ' . $e->getMessage() . ' Change the order status again to retry.' );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * The order note for a report.
	 *
	 * @param ProcessReport $report Report.
	 */
	private function note( ProcessReport $report ): string {
		$parts = array();

		if ( $report->issued > 0 ) {
			/* translators: %d: number of licenses. */
			$parts[] = sprintf( _n( 'issued %d license', 'issued %d licenses', $report->issued, 'wplicense-it' ), $report->issued );
		}
		if ( $report->renewed > 0 ) {
			/* translators: %d: number of licenses. */
			$parts[] = sprintf( _n( 'renewed %d license', 'renewed %d licenses', $report->renewed, 'wplicense-it' ), $report->renewed );
		}
		if ( $report->revoked > 0 ) {
			/* translators: %d: number of licenses. */
			$parts[] = sprintf( _n( 'ended %d license', 'ended %d licenses', $report->revoked, 'wplicense-it' ), $report->revoked );
		}

		$note = 'WPLicense It' . ( array() === $parts ? '' : ': ' . implode( ', ', $parts ) . '.' );

		return array() === $report->notes ? $note : $note . "\n" . implode( "\n", $report->notes );
	}
}
