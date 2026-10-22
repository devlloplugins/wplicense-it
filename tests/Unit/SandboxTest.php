<?php
/**
 * Tests for the throwaway licensing sandbox.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\DryRun\Sandbox;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\WooCommerce\OrderData;
use Devllo\WPLicenseIt\WooCommerce\OrderItemData;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\DryRun\Sandbox
 * @covers \Devllo\WPLicenseIt\DryRun\MemoryEventLog
 * @covers \Devllo\WPLicenseIt\DryRun\MemoryLicenseRepository
 * @covers \Devllo\WPLicenseIt\DryRun\MemoryActivationRepository
 * @covers \Devllo\WPLicenseIt\DryRun\MemoryLicenseOrderRepository
 * @covers \Devllo\WPLicenseIt\DryRun\MemoryItemLicenseStore
 */
final class SandboxTest extends TestCase {

	private function order( int $limit = 2, string $period = 'P1Y' ): OrderData {
		return new OrderData( 1, '1', null, 'test@example.com', 'USD', 4900, array( new OrderItemData( 10, 'SEO Pro - 2 sites', 1, 7, $limit, $period, null, array(), 0 ) ) );
	}

	public function test_an_order_through_the_sandbox_issues_a_working_license(): void {
		$sandbox = new Sandbox( new FixedClock( '2026-03-01 00:00:00' ) );

		$report = $sandbox->processor()->issue( $this->order() );

		$this->assertSame( 1, $report->issued );

		$license = $sandbox->licenses()->find( $sandbox->links->links[10][0] );
		$this->assertSame( 7, $license->product_id );
		$this->assertSame( 2, $license->activation_limit );
		$this->assertSame( Status::ACTIVE, $license->status );
		$this->assertSame( '2027-03-01', $license->expires_at->format( 'Y-m-d' ) );
	}

	public function test_the_license_can_be_activated_up_to_its_limit(): void {
		$sandbox = new Sandbox();
		$sandbox->processor()->issue( $this->order( 2 ) );
		$license = $sandbox->licenses()->find( $sandbox->links->links[10][0] );

		$this->assertTrue( $sandbox->licenses()->activate( $license->license_key, 'one.example.com', 7 )->is_success() );
		$this->assertTrue( $sandbox->licenses()->activate( $license->license_key, 'two.example.com', 7 )->is_success() );

		$third = $sandbox->licenses()->activate( $license->license_key, 'three.example.com', 7 );
		$this->assertFalse( $third->is_success() );
		$this->assertSame( 'limit_reached', $third->code );
	}

	public function test_two_sandboxes_share_nothing(): void {
		$a = new Sandbox();
		$b = new Sandbox();

		$a->processor()->issue( $this->order() );

		$this->assertCount( 1, $a->links->links );
		$this->assertCount( 0, $b->links->links );
		$this->assertCount( 0, $b->orders->records );
		$this->assertNull( $b->license_rows->find( 1 ) );
	}

	public function test_it_records_what_happened_with_real_times(): void {
		$sandbox = new Sandbox();
		$sandbox->processor()->issue( $this->order() );
		$id = $sandbox->links->links[10][0];

		$history = $sandbox->events->for_license( $id );

		$this->assertTrue( count( $history ) >= 1 );
		$age = abs( time() - $history[0]['created_at']->getTimestamp() );
		$this->assertLessThanOrEqual( 5, $age );
	}

	public function test_it_never_touches_the_database(): void {
		$GLOBALS['wpdb'] = null;

		( new Sandbox() )->processor()->issue( $this->order() );

		$this->assertNull( $GLOBALS['wpdb'] );
		unset( $GLOBALS['wpdb'] );
	}
}
