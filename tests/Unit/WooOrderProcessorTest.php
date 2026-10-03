<?php
/**
 * Tests for the WooCommerce adapter logic.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateInterval;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryItemLicenseStore;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseOrderRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use Devllo\WPLicenseIt\WooCommerce\LicenseTerms;
use Devllo\WPLicenseIt\WooCommerce\OrderData;
use Devllo\WPLicenseIt\WooCommerce\OrderItemData;
use Devllo\WPLicenseIt\WooCommerce\OrderProcessor;
use Devllo\WPLicenseIt\WooCommerce\PeriodParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\WooCommerce\OrderProcessor
 * @covers \Devllo\WPLicenseIt\WooCommerce\PeriodParser
 * @covers \Devllo\WPLicenseIt\WooCommerce\LicenseTerms
 */
final class WooOrderProcessorTest extends TestCase {

	private LicenseService $service;
	private InMemoryItemLicenseStore $store;
	private InMemoryLicenseOrderRepository $orders;
	private OrderProcessor $processor;
	private FixedClock $clock;

	protected function setUp(): void {
		$this->clock     = new FixedClock( '2026-03-01 00:00:00' );
		$this->service   = new LicenseService( new InMemoryLicenseRepository(), new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), $this->clock );
		$this->store     = new InMemoryItemLicenseStore();
		$this->orders    = new InMemoryLicenseOrderRepository();
		$this->processor = new OrderProcessor( $this->service, $this->orders, $this->store, $this->clock );
	}

	/**
	 * Builds the order as WooCommerce would report it now, including links stored so far.
	 *
	 * @param array<int, array<string, mixed>> $lines Line overrides, keyed by item ID.
	 */
	private function order( array $lines = array( 10 => array() ), ?int $user_id = 5, string $email = 'Buyer@Example.com', int $id = 100 ): OrderData {
		$items = array();

		foreach ( $lines as $item_id => $line ) {
			$line    += array(
				'name'     => 'Great Plugin',
				'quantity' => 1,
				'product'  => 7,
				'limit'    => 1,
				'period'   => 'P1Y',
				'renew'    => null,
				'refunded' => 0,
			);
			$items[] = new OrderItemData( $item_id, $line['name'], $line['quantity'], $line['product'], $line['limit'], $line['period'], $line['renew'], $this->store->links[ $item_id ] ?? array(), $line['refunded'] );
		}

		return new OrderData( $id, (string) $id, $user_id, $email, 'USD', 2999, $items );
	}

	// Issuing.

	public function test_a_completed_order_issues_a_license_with_the_line_terms(): void {
		$report = $this->processor->issue( $this->order( array( 10 => array( 'limit' => 3 ) ) ) );

		$this->assertSame( 1, $report->issued );

		$license = $this->service->find( $this->store->links[10][0] );
		$this->assertSame( 7, $license->product_id );
		$this->assertSame( 5, $license->user_id );
		$this->assertSame( 'buyer@example.com', $license->email );
		$this->assertSame( 3, $license->activation_limit );
		$this->assertSame( '2027-03-01', $license->expires_at->format( 'Y-m-d' ) );
		$this->assertSame( 1, $license->order_id );
		$this->assertSame( 'WC-100', $this->orders->records[100]['number'] );
	}

	public function test_lifetime_and_unlimited_lines(): void {
		$this->processor->issue( $this->order( array( 10 => array( 'period' => 'lifetime', 'limit' => 0 ) ) ) );

		$license = $this->service->find( $this->store->links[10][0] );
		$this->assertNull( $license->expires_at );
		$this->assertSame( 0, $license->activation_limit );
	}

	public function test_one_license_per_unit_bought(): void {
		$report = $this->processor->issue( $this->order( array( 10 => array( 'quantity' => 3 ) ) ) );

		$this->assertSame( 3, $report->issued );
		$this->assertCount( 3, array_unique( $this->store->links[10] ) );
	}

	public function test_lines_without_a_linked_product_are_ignored_and_leave_no_record(): void {
		$report = $this->processor->issue( $this->order( array( 10 => array( 'product' => 0 ) ) ) );

		$this->assertSame( 0, $report->issued );
		$this->assertFalse( $report->changed() );
		$this->assertSame( array(), $this->orders->records );
	}

	public function test_processing_an_order_twice_issues_nothing_twice(): void {
		$this->processor->issue( $this->order( array( 10 => array( 'quantity' => 2 ) ) ) );
		$again = $this->processor->issue( $this->order( array( 10 => array( 'quantity' => 2 ) ) ) );

		$this->assertSame( 0, $again->issued );
		$this->assertCount( 2, $this->store->links[10] );
		$this->assertCount( 1, $this->orders->records );
	}

	public function test_a_retry_only_issues_what_is_still_owed(): void {
		$this->store->links[10] = array( $this->service->issue_license( 7, 'buyer@example.com' )->id ); // One of three made it before a failure.

		$report = $this->processor->issue( $this->order( array( 10 => array( 'quantity' => 3 ) ) ) );

		$this->assertSame( 2, $report->issued );
		$this->assertCount( 3, $this->store->links[10] );
	}

	public function test_a_bad_period_never_becomes_a_free_lifetime_license(): void {
		$report = $this->processor->issue( $this->order( array( 10 => array( 'period' => 'forever-ish' ) ) ) );

		$this->assertSame( 0, $report->issued );
		$this->assertCount( 1, $report->notes );
		$this->assertStringContainsString( 'No license was issued', $report->notes[0] );
		$this->assertSame( array(), $this->store->links );
	}

	public function test_a_missing_email_is_reported_not_fatal(): void {
		$report = $this->processor->issue( $this->order( array( 10 => array() ), 5, '' ) );

		$this->assertSame( 0, $report->issued );
		$this->assertStringContainsString( 'could not issue', $report->notes[0] );
	}

	public function test_several_lines_are_handled_independently(): void {
		$report = $this->processor->issue( $this->order( array( 10 => array(), 11 => array( 'product' => 0 ), 12 => array( 'product' => 8, 'period' => 'lifetime' ) ) ) );

		$this->assertSame( 2, $report->issued );
		$this->assertArrayNotHasKey( 11, $this->store->links );
		$this->assertSame( 8, $this->service->find( $this->store->links[12][0] )->product_id );
	}

	// Renewal by repurchase.

	public function test_a_renewal_extends_the_existing_license_instead_of_issuing_a_new_one(): void {
		$first = $this->service->issue_license( 7, 'buyer@example.com', 5, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P2M' ) ) );

		$report = $this->processor->issue( $this->order( array( 10 => array( 'renew' => $first->id ) ), 5, 'buyer@example.com', 101 ) );

		$this->assertSame( 0, $report->issued );
		$this->assertSame( 1, $report->renewed );
		$this->assertSame( array( $first->id ), $this->store->links[10] );
		$this->assertSame( '2027-05-01', $this->service->find( $first->id )->expires_at->format( 'Y-m-d' ) ); // 2026-05-01 plus a year.
	}

	public function test_renewing_an_expired_license_starts_from_today_and_reactivates_it(): void {
		$first = $this->service->issue_license( 7, 'buyer@example.com', 5, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P1M' ) ) );
		$this->clock->advance( '+6 months' );
		$this->service->expire_due_licenses();

		$this->processor->issue( $this->order( array( 10 => array( 'renew' => $first->id ) ) ) );

		$license = $this->service->find( $first->id );
		$this->assertSame( Status::ACTIVE, $license->status );
		$this->assertSame( '2027-09-01', $license->expires_at->format( 'Y-m-d' ) );
	}

	public function test_a_renewal_with_extra_units_renews_one_and_issues_the_rest(): void {
		$first = $this->service->issue_license( 7, 'buyer@example.com', 5, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P1M' ) ) );

		$report = $this->processor->issue( $this->order( array( 10 => array( 'renew' => $first->id, 'quantity' => 3 ) ) ) );

		$this->assertSame( 1, $report->renewed );
		$this->assertSame( 2, $report->issued );
		$this->assertCount( 3, $this->store->links[10] );
	}

	public function test_a_guest_can_renew_by_email(): void {
		$first = $this->service->issue_license( 7, 'buyer@example.com', null, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P1M' ) ) );

		$report = $this->processor->issue( $this->order( array( 10 => array( 'renew' => $first->id ) ), null, 'BUYER@example.com' ) );

		$this->assertSame( 1, $report->renewed );
	}

	public function test_renewing_someone_elses_license_is_refused(): void {
		$theirs = $this->service->issue_license( 7, 'other@example.com', 99, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P1M' ) ) );

		$report = $this->processor->issue( $this->order( array( 10 => array( 'renew' => $theirs->id ) ) ) );

		$this->assertSame( 0, $report->renewed );
		$this->assertSame( 1, $report->issued );
		$this->assertStringContainsString( 'does not belong', $report->notes[0] );
		$this->assertSame( '2026-04-01', $this->service->find( $theirs->id )->expires_at->format( 'Y-m-d' ) ); // Untouched.
	}

	public function test_renewing_a_license_for_another_product_or_a_revoked_one_issues_a_new_license(): void {
		$other_product = $this->service->issue_license( 8, 'buyer@example.com', 5, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P1M' ) ) );
		$revoked       = $this->service->issue_license( 7, 'buyer@example.com', 5, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P1M' ) ) );
		$this->service->revoke_license( $revoked->id );

		$a = $this->processor->issue( $this->order( array( 10 => array( 'renew' => $other_product->id ) ), 5, 'buyer@example.com', 101 ) );
		$b = $this->processor->issue( $this->order( array( 20 => array( 'renew' => $revoked->id ) ), 5, 'buyer@example.com', 102 ) );

		$this->assertSame( 1, $a->issued );
		$this->assertSame( 1, $b->issued );
		$this->assertStringContainsString( 'cannot be renewed', $b->notes[0] );
		$this->assertSame( Status::REVOKED, $this->service->find( $revoked->id )->status );
	}

	public function test_a_renewal_is_not_applied_twice(): void {
		$first = $this->service->issue_license( 7, 'buyer@example.com', 5, null, 1, $this->clock->__invoke()->add( new DateInterval( 'P1M' ) ) );

		$this->processor->issue( $this->order( array( 10 => array( 'renew' => $first->id ) ) ) );
		$again = $this->processor->issue( $this->order( array( 10 => array( 'renew' => $first->id ) ) ) );

		$this->assertSame( 0, $again->renewed );
		$this->assertSame( '2027-04-01', $this->service->find( $first->id )->expires_at->format( 'Y-m-d' ) );
	}

	// Refunds and cancellations.

	public function test_a_refund_ends_every_license_of_the_order(): void {
		$this->processor->issue( $this->order( array( 10 => array( 'quantity' => 2 ) ) ) );

		$report = $this->processor->revoke( $this->order( array( 10 => array( 'quantity' => 2 ) ) ), Status::REFUNDED );

		$this->assertSame( 2, $report->revoked );
		foreach ( $this->store->links[10] as $id ) {
			$this->assertSame( Status::REFUNDED, $this->service->find( $id )->status );
		}
		$this->assertSame( 'refunded', $this->orders->records[100]['status'] );
	}

	public function test_a_cancellation_revokes_and_repeating_it_does_nothing(): void {
		$this->processor->issue( $this->order() );

		$first  = $this->processor->revoke( $this->order(), Status::REVOKED );
		$second = $this->processor->revoke( $this->order(), Status::REVOKED );

		$this->assertSame( 1, $first->revoked );
		$this->assertSame( 0, $second->revoked );
		$this->assertSame( 'cancelled', $this->orders->records[100]['status'] );
	}

	public function test_a_refund_does_not_overwrite_an_earlier_revocation(): void {
		$this->processor->issue( $this->order() );
		$this->service->revoke_license( $this->store->links[10][0] ); // Revoked by hand, for abuse.

		$report = $this->processor->revoke( $this->order(), Status::REFUNDED );

		$this->assertSame( 0, $report->revoked );
		$this->assertSame( Status::REVOKED, $this->service->find( $this->store->links[10][0] )->status );
	}

	public function test_a_partial_refund_ends_that_many_licenses_newest_first(): void {
		$this->processor->issue( $this->order( array( 10 => array( 'quantity' => 3 ) ) ) );
		list( $oldest, $middle, $newest ) = $this->store->links[10];

		$one = $this->processor->revoke_refunded_units( $this->order( array( 10 => array( 'quantity' => 3, 'refunded' => 1 ) ) ) );

		$this->assertSame( 1, $one->revoked );
		$this->assertSame( Status::REFUNDED, $this->service->find( $newest )->status );
		$this->assertSame( Status::ACTIVE, $this->service->find( $middle )->status );
		$this->assertSame( Status::ACTIVE, $this->service->find( $oldest )->status );

		// The same refund again changes nothing.
		$repeat = $this->processor->revoke_refunded_units( $this->order( array( 10 => array( 'quantity' => 3, 'refunded' => 1 ) ) ) );
		$this->assertSame( 0, $repeat->revoked );

		// A second refund ends one more.
		$two = $this->processor->revoke_refunded_units( $this->order( array( 10 => array( 'quantity' => 3, 'refunded' => 2 ) ) ) );
		$this->assertSame( 1, $two->revoked );
		$this->assertSame( Status::REFUNDED, $this->service->find( $middle )->status );
		$this->assertSame( Status::ACTIVE, $this->service->find( $oldest )->status );
	}

	public function test_refunding_more_units_than_licenses_is_capped(): void {
		$this->processor->issue( $this->order( array( 10 => array( 'quantity' => 2 ) ) ) );

		$report = $this->processor->revoke_refunded_units( $this->order( array( 10 => array( 'quantity' => 2, 'refunded' => 5 ) ) ) );

		$this->assertSame( 2, $report->revoked );
	}

	// Terms and periods.

	public function test_the_most_specific_setting_wins(): void {
		$this->assertSame( 5, LicenseTerms::activation_limit( '5', '2', '9' ) );
		$this->assertSame( 2, LicenseTerms::activation_limit( '', '2', '9' ) );
		$this->assertSame( 9, LicenseTerms::activation_limit( '', '', '9' ) );
		$this->assertSame( 1, LicenseTerms::activation_limit( '', '', '' ) );
		$this->assertSame( 0, LicenseTerms::activation_limit( '0', '2', '9' ) ); // Zero means unlimited, and is a real answer.
		$this->assertSame( 1, LicenseTerms::activation_limit( 'abc', '', '' ) );

		$this->assertSame( 'P6M', LicenseTerms::period( 'P6M', 'P1Y', 'lifetime' ) );
		$this->assertSame( 'P1Y', LicenseTerms::period( '', 'P1Y', 'lifetime' ) );
		$this->assertSame( 'P1M', LicenseTerms::period( '', '', 'P1M' ) );
		$this->assertSame( 'lifetime', LicenseTerms::period( '', '', '' ) );
	}

	public function test_period_parsing(): void {
		$this->assertNull( PeriodParser::parse( 'lifetime' ) );
		$this->assertNull( PeriodParser::parse( ' Lifetime ' ) );
		$this->assertSame( 1, PeriodParser::parse( 'P1Y' )->y );
		$this->assertSame( 6, PeriodParser::parse( 'P6M' )->m );
		$this->assertSame( 14, PeriodParser::parse( 'P14D' )->d );
		$this->assertSame( 2, PeriodParser::parse( 'P1Y2M' )->m );

		foreach ( array( '', 'P', 'P0Y', 'P0D', '1Y', 'PT5M', 'P1W', 'forever', 'P1Y-1M' ) as $bad ) {
			try {
				PeriodParser::parse( $bad );
				$this->fail( "Expected '{$bad}' to be rejected." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'Invalid license period', $e->getMessage() );
			}
		}
	}
}
