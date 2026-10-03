<?php
/**
 * Turns WooCommerce orders into licenses.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseException;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use InvalidArgumentException;

/**
 * Payment adapter logic: issue, renew and revoke licenses for an order.
 *
 * It only calls the licensing core and knows nothing about WooCommerce classes, so it
 * is fully unit tested. WooCommerce specifics live in WooAdapter and OrderReader.
 *
 * Every method is safe to repeat: licenses created so far are recorded per order line,
 * so a second "order completed" event, or a retry after a failure, issues nothing twice.
 */
final class OrderProcessor {

	private const MAX_PER_LINE = 100;

	/**
	 * Returns the current UTC time as a DateTimeImmutable.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param LicenseService         $licenses Licensing core.
	 * @param LicenseOrderRepository $orders   License order records.
	 * @param ItemLicenseStore       $store    Order line to license links.
	 * @param callable|null          $clock    Optional, returns the current UTC DateTimeImmutable.
	 */
	public function __construct(
		private LicenseService $licenses,
		private LicenseOrderRepository $orders,
		private ItemLicenseStore $store,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Issues the licenses an order is owed, and renews licenses the customer chose to renew.
	 *
	 * @param OrderData $order Order.
	 */
	public function issue( OrderData $order ): ProcessReport {
		$report   = new ProcessReport();
		$order_id = null;

		foreach ( $order->items as $item ) {
			if ( $item->wplit_product_id <= 0 ) {
				continue;
			}

			$owed = min( $item->quantity, self::MAX_PER_LINE ) - count( $item->license_ids );
			if ( $owed <= 0 ) {
				continue;
			}

			try {
				$interval = PeriodParser::parse( $item->period );
			} catch ( InvalidArgumentException $e ) {
				$report->notes[] = "{$item->name}: {$e->getMessage()} No license was issued.";
				continue;
			}

			if ( null === $order_id ) {
				$order_id = $this->orders->record( $order );
			}

			$renewal = $this->renewable_license( $order, $item, $report );

			if ( null !== $renewal && $owed > 0 ) {
				try {
					$renewed = null === $interval ? $renewal : $this->licenses->renew_license( $renewal->id, $interval );
					$this->store->append( $item->item_id, $renewed->id );
					++$report->renewed;
					--$owed;
					$report->notes[] = "{$item->name}: renewed license {$renewed->license_key}" . ( null === $renewed->expires_at ? '.' : ' until ' . $renewed->expires_at->format( 'Y-m-d' ) . '.' );
				} catch ( LicenseException $e ) {
					$report->notes[] = "{$item->name}: could not renew license {$renewal->id} ({$e->getMessage()}). A new license is issued instead.";
				}
			}

			for ( $i = 0; $i < $owed; $i++ ) {
				try {
					$expires = null === $interval ? null : ( $this->clock )()->add( $interval );
					$license = $this->licenses->issue_license( $item->wplit_product_id, $order->email, $order->user_id, $order_id, $item->activation_limit, $expires );
					$this->store->append( $item->item_id, $license->id );
					++$report->issued;
				} catch ( LicenseException $e ) {
					$report->notes[] = "{$item->name}: could not issue a license ({$e->getMessage()}).";
					break;
				}
			}
		}

		return $report;
	}

	/**
	 * Ends every license of an order, for a refund or cancellation.
	 *
	 * @param OrderData $order  Order.
	 * @param string    $status Status::REFUNDED or Status::REVOKED.
	 */
	public function revoke( OrderData $order, string $status ): ProcessReport {
		$report = new ProcessReport();

		foreach ( $order->items as $item ) {
			foreach ( $item->license_ids as $license_id ) {
				$report->revoked += $this->end_license( $license_id, $status, $report );
			}
		}

		$this->orders->set_status( $order->id, Status::REFUNDED === $status ? 'refunded' : 'cancelled' );

		return $report;
	}

	/**
	 * Ends licenses for refunded units of a partial refund, newest licenses first.
	 *
	 * Uses the total refunded quantity per line, so repeating it revokes nothing extra.
	 *
	 * @param OrderData $order Order, with each line's refunded_quantity set.
	 */
	public function revoke_refunded_units( OrderData $order ): ProcessReport {
		$report = new ProcessReport();

		foreach ( $order->items as $item ) {
			if ( $item->refunded_quantity <= 0 || array() === $item->license_ids ) {
				continue;
			}

			$already_ended = 0;
			$still_open    = array();

			foreach ( array_reverse( $item->license_ids ) as $license_id ) {
				$license = $this->licenses->find( $license_id );
				if ( null === $license ) {
					continue;
				}

				if ( in_array( $license->status, Status::terminal(), true ) ) {
					++$already_ended;
				} else {
					$still_open[] = $license_id;
				}
			}

			$to_end = min( $item->refunded_quantity - $already_ended, count( $still_open ) );

			for ( $i = 0; $i < $to_end; $i++ ) {
				$report->revoked += $this->end_license( $still_open[ $i ], Status::REFUNDED, $report );
			}
		}

		return $report;
	}

	/**
	 * Revokes one license.
	 *
	 * @param int           $license_id License ID.
	 * @param string        $status     Terminal status.
	 * @param ProcessReport $report     Report to add problems to.
	 * @return int 1 if the license was ended now, 0 otherwise.
	 */
	private function end_license( int $license_id, string $status, ProcessReport $report ): int {
		$license = $this->licenses->find( $license_id );

		if ( null === $license || $license->status === $status ) {
			return 0;
		}

		// A license that was already ended for another reason stays as it is.
		if ( in_array( $license->status, Status::terminal(), true ) ) {
			return 0;
		}

		try {
			$this->licenses->revoke_license( $license_id, $status );

			return 1;
		} catch ( LicenseException $e ) {
			$report->notes[] = "License {$license_id}: {$e->getMessage()}";

			return 0;
		}
	}

	/**
	 * The license the customer asked to renew, if the request is still valid.
	 *
	 * The license must belong to the buyer (same user, or same email for guests), be for the
	 * same product, and not be revoked or refunded. Anything else is reported and ignored.
	 *
	 * @param OrderData     $order  Order.
	 * @param OrderItemData $item   Order line.
	 * @param ProcessReport $report Report to add problems to.
	 */
	private function renewable_license( OrderData $order, OrderItemData $item, ProcessReport $report ): ?License {
		if ( null === $item->renew_license_id || in_array( $item->renew_license_id, $item->license_ids, true ) ) {
			return null;
		}

		$license = $this->licenses->find( $item->renew_license_id );

		$owned = null !== $license && (
			( null !== $order->user_id && $license->user_id === $order->user_id )
			|| strtolower( trim( $order->email ) ) === $license->email
		);

		if ( ! $owned || $license->product_id !== $item->wplit_product_id ) {
			$report->notes[] = "{$item->name}: the license chosen for renewal does not belong to this customer or product. A new license is issued instead.";

			return null;
		}

		if ( in_array( $license->status, Status::terminal(), true ) ) {
			$report->notes[] = "{$item->name}: license {$license->license_key} was revoked or refunded and cannot be renewed. A new license is issued instead.";

			return null;
		}

		return $license;
	}
}
