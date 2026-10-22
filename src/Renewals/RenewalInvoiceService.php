<?php
/**
 * Renewal invoices.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Renewals;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseRepository;
use Devllo\WPLicenseIt\Privacy\PrivacyService;

/**
 * A set number of days before a license expires, creates the order that renews it and sends it to the customer, so they can
 * pay with any payment method the store offers.
 *
 * One invoice is made per expiry date and written to the license history, so none is made twice, and a renewal (a new
 * expiry date) starts a new cycle. An invoice that was cancelled or deleted is not made again for the same expiry date.
 */
final class RenewalInvoiceService {

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository   $licenses Licenses.
	 * @param EventLog            $events   License history.
	 * @param RenewalOrderCreator $creator  Makes the order and sends it.
	 * @param callable|null       $clock    Returns the current time (UTC). Tests pass a fixed one.
	 */
	public function __construct(
		private LicenseRepository $licenses,
		private EventLog $events,
		private RenewalOrderCreator $creator,
		private $clock = null
	) {
	}

	/**
	 * Creates every invoice that is due.
	 *
	 * @param int $days  Days before expiry.
	 * @param int $max   Most invoices in one run. The rest are made on the next run.
	 * @param int $batch Licenses read at a time.
	 * @return int Invoices made.
	 */
	public function create_due( int $days, int $max = 100, int $batch = 200 ): int {
		if ( $days < 1 || $max < 1 ) {
			return 0;
		}

		$now      = $this->now();
		$until    = $now->modify( "+{$days} days" );
		$after    = null;
		$made     = 0;
		$fetched  = $batch;
		$sellable = array();

		while ( $fetched === $batch ) {
			$licenses = $this->licenses->find_expiring( $now, $until, $after, $batch );
			$fetched  = count( $licenses );

			foreach ( $licenses as $license ) {
				$after = $license;

				if ( $made >= $max ) {
					return $made;
				}

				if ( ! isset( $sellable[ $license->product_id ] ) ) {
					$sellable[ $license->product_id ] = $this->creator->sells( $license->product_id );
				}

				if ( $sellable[ $license->product_id ] && $this->invoice( $license ) ) {
					++$made;
				}
			}
		}

		return $made;
	}

	/**
	 * Makes the invoice for one license, unless it has one for this expiry date or should not get one.
	 *
	 * @param License $license The license.
	 */
	private function invoice( License $license ): bool {
		$email = trim( $license->email );

		if ( null === $license->expires_at || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) || PrivacyService::is_placeholder( $email ) ) {
			return false;
		}

		$expires = $license->expires_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM );

		foreach ( $this->events->of_type( $license->id, EventLog::INVOICED, 50 ) as $event ) {
			if ( ( $event['data']['expires_at'] ?? null ) === $expires ) {
				return false;
			}
		}

		// Lets a site owner skip some licenses.
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'wplicense_it_create_renewal_invoice', true, $license ) ) {
			return false;
		}

		$order_id = $this->creator->create( $license );

		if ( null === $order_id ) {
			return false;
		}

		$this->events->record(
			$license->id,
			EventLog::INVOICED,
			array(
				'order_id'   => $order_id,
				'expires_at' => $expires,
			)
		);

		return true;
	}

	/**
	 * Current time in UTC.
	 */
	private function now(): DateTimeImmutable {
		return null === $this->clock ? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) : ( $this->clock )();
	}
}
