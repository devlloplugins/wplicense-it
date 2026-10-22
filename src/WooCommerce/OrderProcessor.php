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
use Devllo\WPLicenseIt\Licenses\EventLog;
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

		$this->reinstate( $order, $report );

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
				$report->problem(
					sprintf(
						/* translators: 1: order line name, 2: what is wrong. */
						__( '%1$s: %2$s No license was issued.', 'wplicense-it' ),
						$item->name,
						$e->getMessage()
					)
				);
				continue;
			}

			if ( null === $order_id ) {
				$order_id = $this->orders->record( $order );
			}

			$renewal = $this->renewable_license( $order, $item, $report );

			if ( null !== $renewal ) {
				try {
					$renewed = null === $interval ? $renewal : $this->licenses->renew_license( $renewal->id, $interval, self::context( $order ) );
					$this->store->append( $item->item_id, $renewed->id );
					++$report->renewed;
					--$owed;
					$report->notes[] = null === $renewed->expires_at
						? sprintf(
							/* translators: 1: order line name, 2: license key. */
							__( '%1$s: renewed license %2$s.', 'wplicense-it' ),
							$item->name,
							$renewed->license_key
						)
						: sprintf(
							/* translators: 1: order line name, 2: license key, 3: new expiry date. */
							__( '%1$s: renewed license %2$s until %3$s.', 'wplicense-it' ),
							$item->name,
							$renewed->license_key,
							$renewed->expires_at->format( 'Y-m-d' )
						);
				} catch ( LicenseException $e ) {
					$report->notes[] = sprintf(
						/* translators: 1: order line name, 2: license ID, 3: why. */
						__( '%1$s: could not renew license %2$d (%3$s). A new license is issued instead.', 'wplicense-it' ),
						$item->name,
						$renewal->id,
						$e->getMessage()
					);
				}//end try
			}//end if

			for ( $i = 0; $i < $owed; $i++ ) {
				try {
					$expires = null === $interval ? null : ( $this->clock )()->add( $interval );
					$license = $this->licenses->issue_license( $item->wplit_product_id, $order->email, $order->user_id, $order_id, $item->activation_limit, $expires );
					$this->store->append( $item->item_id, $license->id );
					++$report->issued;
				} catch ( LicenseException $e ) {
					$report->problem(
						sprintf(
							/* translators: 1: order line name, 2: why. */
							__( '%1$s: could not issue a license (%2$s).', 'wplicense-it' ),
							$item->name,
							$e->getMessage()
						)
					);
					break;
				}
			}
		}//end foreach

		return $report;
	}

	/**
	 * Ends every license of an order, for a refund or cancellation. A license this order renewed is not ended: only the
	 * time the renewal added is taken back, because the time before it was paid for by another order.
	 *
	 * @param OrderData $order  Order.
	 * @param string    $status Status::REFUNDED or Status::REVOKED.
	 */
	public function revoke( OrderData $order, string $status ): ProcessReport {
		$report = new ProcessReport();

		foreach ( $order->items as $item ) {
			foreach ( $item->license_ids as $license_id ) {
				$this->end_or_take_back( $license_id, $status, $report, $order );
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

				if ( in_array( $license->status, Status::terminal(), true ) || $this->licenses->renewal_taken_back( $license_id, self::context( $order )['order'] ) ) {
					++$already_ended;
				} else {
					$still_open[] = $license_id;
				}
			}

			$to_end = min( $item->refunded_quantity - $already_ended, count( $still_open ) );

			for ( $i = 0; $i < $to_end; $i++ ) {
				$this->end_or_take_back( $still_open[ $i ], Status::REFUNDED, $report, $order );
			}
		}//end foreach

		return $report;
	}

	/**
	 * Brings back the licenses this order ended, when it is completed (or processing) again: an order cancelled while
	 * unpaid and paid after all, or set to refunded by mistake. Only licenses whose latest refund or revocation names
	 * this order come back, so one an administrator revoked stays revoked. Units the order's refunds still cover stay
	 * refunded (newest licenses first, as a partial refund ends them). A license past its expiry comes back expired.
	 *
	 * @param OrderData     $order  Order.
	 * @param ProcessReport $report Report.
	 */
	private function reinstate( OrderData $order, ProcessReport $report ): void {
		$reference = self::context( $order )['order'];
		$restored  = 0;

		foreach ( $order->items as $item ) {
			$ended = array();

			foreach ( $item->license_ids as $license_id ) {
				$license = $this->licenses->find( $license_id );
				if ( null === $license ) {
					continue;
				}

				if ( $this->licenses->renewal_taken_back( $license_id, $reference ) ) {
					$ended[] = $license_id;
					continue;
				}

				if ( ! in_array( $license->status, Status::terminal(), true ) ) {
					continue;
				}

				$event = $this->licenses->last_event( $license_id, Status::REFUNDED === $license->status ? EventLog::REFUNDED : EventLog::REVOKED );
				if ( null !== $event && ( $event['data']['order'] ?? null ) === $reference ) {
					$ended[] = $license_id;
				}
			}

			// The newest licenses are the ones a refund of some units ended; they stay ended while those units are refunded.
			$keep = min( count( $ended ), max( 0, $item->refunded_quantity ) );
			$back = array_slice( $ended, 0, count( $ended ) - $keep );

			foreach ( $back as $license_id ) {
				try {
					if ( $this->licenses->renewal_taken_back( $license_id, $reference ) ) {
						$this->renew_again( $license_id, $item, $order, $report );
						++$restored;
						continue;
					}

					$this->licenses->reinstate_license( $license_id, self::context( $order ) );
					++$report->reinstated;
					++$restored;
				} catch ( LicenseException $e ) {
					$report->notes[] = sprintf(
						/* translators: 1: license ID, 2: what went wrong. */
						__( 'License %1$d: %2$s', 'wplicense-it' ),
						$license_id,
						$e->getMessage()
					);
				}
			}

			$kept_renewals = count( array_filter( array_slice( $ended, count( $ended ) - $keep ), fn( int $id ): bool => $this->licenses->renewal_taken_back( $id, $reference ) ) );
			if ( $kept_renewals > 0 ) {
				$report->notes[] = sprintf(
					/* translators: 1: order line name, 2: number of licenses. */
					_n( '%1$s: %2$d license\'s renewal stays taken back, because its unit is still refunded.', '%1$s: %2$d licenses\' renewals stay taken back, because their units are still refunded.', $kept_renewals, 'wplicense-it' ),
					$item->name,
					$kept_renewals
				);
			}
			if ( $keep > $kept_renewals ) {
				$report->notes[] = sprintf(
					/* translators: 1: order line name, 2: number of licenses. */
					_n( '%1$s: %2$d license stays refunded, because its unit is still refunded.', '%1$s: %2$d licenses stay refunded, because their units are still refunded.', $keep - $kept_renewals, 'wplicense-it' ),
					$item->name,
					$keep - $kept_renewals
				);
			}
		}//end foreach

		if ( $restored > 0 ) {
			$this->orders->set_status( $order->id, 'completed' );
		}
	}

	/**
	 * Applies a renewal again that a refund or cancellation of this order had taken back, now that it is paid after all.
	 *
	 * @param int           $license_id License ID.
	 * @param OrderItemData $item       The renewal line.
	 * @param OrderData     $order      Order.
	 * @param ProcessReport $report     Report.
	 * @throws LicenseException If the license cannot be renewed.
	 */
	private function renew_again( int $license_id, OrderItemData $item, OrderData $order, ProcessReport $report ): void {
		try {
			$interval = PeriodParser::parse( $item->period );
		} catch ( InvalidArgumentException $e ) {
			$interval = null;
		}

		if ( null === $interval ) {
			return;
		}

		$renewed = $this->licenses->renew_license( $license_id, $interval, self::context( $order ) );
		++$report->renewed;

		if ( null !== $renewed->expires_at ) {
			$report->notes[] = sprintf(
				/* translators: 1: order line name, 2: license key, 3: new expiry date. */
				__( '%1$s: renewed license %2$s again until %3$s.', 'wplicense-it' ),
				$item->name,
				$renewed->license_key,
				$renewed->expires_at->format( 'Y-m-d' )
			);
		}
	}

	/**
	 * Ends a license for a refund or cancellation, or, when this order renewed it, takes back only that renewal.
	 *
	 * @param int           $license_id License ID.
	 * @param string        $status     Terminal status for a license this order issued.
	 * @param ProcessReport $report     Report.
	 * @param OrderData     $order      Order.
	 */
	private function end_or_take_back( int $license_id, string $status, ProcessReport $report, OrderData $order ): void {
		$reference = self::context( $order )['order'];

		if ( ! $this->licenses->renewed_by( $license_id, $reference ) ) {
			$report->revoked += $this->end_license( $license_id, $status, $report, self::context( $order ) );

			return;
		}

		try {
			$license = $this->licenses->take_back_renewal( $license_id, $reference );
		} catch ( LicenseException $e ) {
			$license = null;
		}

		if ( null === $license ) {
			return;
		}

		++$report->taken_back;
		$report->notes[] = sprintf(
			/* translators: 1: license key, 2: expiry date. */
			__( 'License %1$s: the renewal is taken back; it expires on %2$s again.', 'wplicense-it' ),
			$license->license_key,
			null === $license->expires_at ? '' : $license->expires_at->format( 'Y-m-d' )
		);
	}

	/**
	 * What a license's history records about the order that changed it.
	 *
	 * @param OrderData $order Order.
	 * @return array{order:string}
	 */
	private static function context( OrderData $order ): array {
		return array( 'order' => 'WC-' . $order->number );
	}

	/**
	 * Revokes one license.
	 *
	 * @param int                  $license_id License ID.
	 * @param string               $status     Terminal status.
	 * @param ProcessReport        $report     Report to add problems to.
	 * @param array<string, mixed> $context    Recorded in the license's history: the order that ended it.
	 * @return int 1 if the license was ended now, 0 otherwise.
	 */
	private function end_license( int $license_id, string $status, ProcessReport $report, array $context ): int {
		$license = $this->licenses->find( $license_id );

		if ( null === $license || $license->status === $status ) {
			return 0;
		}

		// A license that was already ended for another reason stays as it is.
		if ( in_array( $license->status, Status::terminal(), true ) ) {
			return 0;
		}

		try {
			$this->licenses->revoke_license( $license_id, $status, $context );

			return 1;
		} catch ( LicenseException $e ) {
			$report->notes[] = sprintf(
				/* translators: 1: license ID, 2: what went wrong. */
				__( 'License %1$d: %2$s', 'wplicense-it' ),
				$license_id,
				$e->getMessage()
			);

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
			$report->notes[] = sprintf(
				/* translators: %s: order line name. */
				__( '%s: the license chosen for renewal does not belong to this customer or product. A new license is issued instead.', 'wplicense-it' ),
				$item->name
			);

			return null;
		}

		if ( in_array( $license->status, Status::terminal(), true ) ) {
			$report->notes[] = sprintf(
				/* translators: 1: order line name, 2: license key. */
				__( '%1$s: license %2$s was revoked or refunded and cannot be renewed. A new license is issued instead.', 'wplicense-it' ),
				$item->name,
				$license->license_key
			);

			return null;
		}

		return $license;
	}
}
