<?php
/**
 * Tests for the test purchase fingerprint.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\TestPurchase\CheckResult;
use Devllo\WPLicenseIt\TestPurchase\Fingerprint;
use Devllo\WPLicenseIt\TestPurchase\TestReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\TestPurchase\Fingerprint
 * @covers \Devllo\WPLicenseIt\TestPurchase\TestReport
 */
final class FingerprintTest extends TestCase {

	private function plan(): Plan {
		return new Plan( 'aaaaaaaa', 'Plan', '99', 5, 'P1Y', 500 );
	}

	public function test_the_same_inputs_give_the_same_fingerprint(): void {
		$this->assertSame( Fingerprint::of( 7, $this->plan(), '/u/a.zip' ), Fingerprint::of( 7, $this->plan(), '/u/a.zip' ) );
	}

	public function test_each_tested_thing_changes_it(): void {
		$base = Fingerprint::of( 7, $this->plan(), '/u/a.zip' );

		$this->assertNotSame( $base, Fingerprint::of( 8, $this->plan(), '/u/a.zip' ), 'software' );
		$this->assertNotSame( $base, Fingerprint::of( 7, $this->plan(), '/u/b.zip' ), 'zip' );
		$this->assertNotSame( $base, Fingerprint::of( 7, $this->plan(), null ), 'no zip' );

		$product                 = $this->plan();
		$product->woo_product_id = 501;
		$this->assertNotSame( $base, Fingerprint::of( 7, $product, '/u/a.zip' ), 'product' );

		$sites        = $this->plan();
		$sites->sites = 6;
		$this->assertNotSame( $base, Fingerprint::of( 7, $sites, '/u/a.zip' ), 'sites' );

		$period         = $this->plan();
		$period->period = 'P2Y';
		$this->assertNotSame( $base, Fingerprint::of( 7, $period, '/u/a.zip' ), 'period' );
	}

	public function test_the_price_and_name_do_not_change_it(): void {
		$base = Fingerprint::of( 7, $this->plan(), '/u/a.zip' );

		$other        = $this->plan();
		$other->price = '149';
		$other->name  = 'Renamed';

		$this->assertSame( $base, Fingerprint::of( 7, $other, '/u/a.zip' ) );
	}

	public function test_is_current_needs_a_stored_pass_that_matches(): void {
		$plan  = $this->plan();
		$print = Fingerprint::of( 7, $plan, '/u/a.zip' );

		$this->assertFalse( Fingerprint::is_current( $plan, $print ) );

		$plan->tested_at   = '2026-10-11 12:00:00';
		$plan->tested_hash = $print;
		$this->assertTrue( Fingerprint::is_current( $plan, $print ) );
		$this->assertFalse( Fingerprint::is_current( $plan, Fingerprint::of( 7, $plan, '/u/b.zip' ) ) );

		$plan->tested_at = null;
		$this->assertFalse( Fingerprint::is_current( $plan, $print ) );
	}

	public function test_a_report_survives_being_stored(): void {
		$report = new TestReport( array( new CheckResult( 'product', 'The product', CheckResult::PASS, 'Fine.' ), new CheckResult( 'reachable', 'Reach', CheckResult::WARN, 'Blocked.' ) ) );

		$again = TestReport::from_array( $report->to_array() );

		$this->assertSame( $report->to_array(), $again->to_array() );
		$this->assertTrue( $again->passed() );
		$this->assertTrue( $again->has_warnings() );
	}

	public function test_damaged_stored_reports_are_dropped(): void {
		$this->assertCount( 0, TestReport::from_array( 'junk' )->results );
		$this->assertCount(
			0,
			TestReport::from_array(
				array(
					array( 'id' => 'x' ),
					array(
						'id'      => 'x',
						'label'   => 'X',
						'status'  => 'bogus',
						'message' => '',
					),
				)
			)->results
		);
	}
}
