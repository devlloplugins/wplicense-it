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
		// WooCommerce sends the order emails on woocommerce_order_status_{status} (priority 10), before status_changed fires.
		// Issue first, at priority 5, so the keys exist when the email is built.
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_status_reached' ), 5, 2 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_status_reached' ), 5, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
		add_filter( 'woocommerce_order_item_needs_processing', array( $this, 'item_needs_processing' ), 10, 2 );
	}

	/**
	 * A virtual licensed product needs no processing, like a virtual downloadable one: an order paid online with only such
	 * items (and other items that need none) is completed by WooCommerce at once, so the license is issued with the
	 * default "completed" setting. Plan products are virtual but not downloadable, and would otherwise wait in
	 * Processing until someone completes the order. Payment methods that do not take the money online (cash on
	 * delivery, bank transfer) never complete an order on their own, so those still wait.
	 *
	 * @param mixed $needs   Whether WooCommerce thinks the item needs processing.
	 * @param mixed $product The item's product or variation.
	 * @return mixed
	 */
	public function item_needs_processing( $needs, $product ) {
		if ( ! $needs || ! $product instanceof \WC_Product || ! $product->is_virtual() ) {
			return $needs;
		}

		return $this->reader->current_terms( $product, $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0 )['product'] > 0 ? false : $needs;
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
			$this->process( $order, fn( OrderData $data ): ProcessReport => $this->processor->issue( $data ), true );
			WooRenewalOrderCreator::cancel_open_invoices( $order );
		} elseif ( 'refunded' === $to ) {
			$this->process( $order, fn( OrderData $data ): ProcessReport => $this->processor->revoke( $data, Status::REFUNDED ) );
		} elseif ( 'cancelled' === $to ) {
			$this->process( $order, fn( OrderData $data ): ProcessReport => $this->processor->revoke( $data, Status::REVOKED ) );
		}
	}

	/**
	 * Processes an order again, for the Try again button.
	 *
	 * @param WC_Order $order Order.
	 */
	public function retry( WC_Order $order ): void {
		$this->on_status_changed( (int) $order->get_id(), '', $order->get_status(), $order );
	}

	/**
	 * An order reached completed or processing, before WooCommerce sends its emails.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 */
	public function on_status_reached( int $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$this->on_status_changed( $order_id, '', $order->get_status(), $order );
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
	 * @param bool     $issuing   Whether this is the step that gives the customer their license. Then a problem is remembered on the
	 *                            order (and the administrator told once), and a success forgets an earlier problem.
	 */
	private function process( WC_Order $order, callable $operation, bool $issuing = false ): void {
		$lock = 'wplit_woo_order_' . $order->get_id();

		if ( get_transient( $lock ) ) {
			return;
		}

		set_transient( $lock, 1, self::LOCK_SECONDS );

		$problems = array();
		$late     = $issuing && '' !== IssueProblems::problem_of( $order );
		$report   = null;
		// The ProcessReport, once the operation ran.

		try {
			/** @var ProcessReport $report */
			$report = $operation( $this->reader->read( $order ) );

			if ( $report->changed() ) {
				$order->add_order_note( $this->note( $report ) );
			}

			$problems = $report->problems;
		} catch ( Throwable $e ) {
			error_log( 'WPLicense It: order ' . $order->get_id() . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need to see this.
			/* translators: %s: what went wrong. */
			$order->add_order_note( sprintf( __( 'WPLicense It error: %s Change the order status again to retry.', 'wplicense-it' ), $e->getMessage() ) );
			/* translators: %s: what went wrong. */
			$problems = array( sprintf( __( 'WPLicense It error: %s', 'wplicense-it' ), $e->getMessage() ) );
		} finally {
			delete_transient( $lock );
		}

		if ( ! $issuing ) {
			return;
		}

		if ( array() === $problems ) {
			IssueProblems::clear( $order );

			// The order had a problem, so the customer's email went out without a key: send it now.
			if ( $late && $report instanceof ProcessReport && $report->issued + $report->renewed > 0 ) {
				do_action( 'wplicense_it_licenses_issued_late', $order );
				// LicenseDisplay::LATE_ACTION.
			}
		} elseif ( IssueProblems::flag( $order, $problems ) ) {
			IssueProblems::notify_admin( $order, $problems );
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
		if ( $report->reinstated > 0 ) {
			/* translators: %d: number of licenses. */
			$parts[] = sprintf( _n( 'brought back %d license', 'brought back %d licenses', $report->reinstated, 'wplicense-it' ), $report->reinstated );
		}
		if ( $report->revoked > 0 ) {
			/* translators: %d: number of licenses. */
			$parts[] = sprintf( _n( 'ended %d license', 'ended %d licenses', $report->revoked, 'wplicense-it' ), $report->revoked );
		}
		if ( $report->taken_back > 0 ) {
			/* translators: %d: number of licenses. */
			$parts[] = sprintf( _n( 'took back the renewal of %d license', 'took back the renewal of %d licenses', $report->taken_back, 'wplicense-it' ), $report->taken_back );
		}

		$note = 'WPLicense It' . ( array() === $parts ? '' : ': ' . implode( ', ', $parts ) . '.' );

		return array() === $report->notes ? $note : $note . "\n" . implode( "\n", $report->notes );
	}
}
