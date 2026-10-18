<?php
/**
 * Tests for the Overview screen's checklist and problem list.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Overview\OverviewReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Overview\OverviewReport
 */
final class OverviewReportTest extends TestCase {

	/**
	 * The done state of every step, by key.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, bool>
	 */
	private function done( array $facts ): array {
		$out = array();

		foreach ( OverviewReport::checklist( $facts )['items'] as $item ) {
			$out[ $item['key'] ] = $item['done'];
		}

		return $out;
	}

	public function test_a_new_install_has_everything_to_do(): void {
		$checklist = OverviewReport::checklist( array() );

		$this->assertFalse( $checklist['complete'] );
		$this->assertSame( array( 'product', 'release', 'license', 'install_woo', 'connect', 'reminders' ), array_column( $checklist['items'], 'key' ) );
		$this->assertSame( array_fill( 0, 6, false ), array_column( $checklist['items'], 'done' ) );
	}

	public function test_steps_are_done_as_the_site_gets_set_up(): void {
		$done = $this->done(
			array(
				'products'                 => 2,
				'products_without_release' => 1,
				'licenses_total'           => 3,
				'sdk_downloaded'           => true,
			)
		);

		$this->assertTrue( $done['product'] );
		$this->assertTrue( $done['release'], 'one product with a release is enough' );
		$this->assertTrue( $done['license'] );
		$this->assertTrue( $done['connect'] );
		$this->assertFalse( $done['reminders'] );
	}

	public function test_a_release_step_is_not_done_while_every_product_lacks_one(): void {
		$done = $this->done(
			array(
				'products'                 => 2,
				'products_without_release' => 2,
			)
		);

		$this->assertFalse( $done['release'] );
	}

	public function test_the_checklist_is_complete_when_the_required_steps_are_done(): void {
		$checklist = OverviewReport::checklist(
			array(
				'products'                 => 1,
				'products_without_release' => 0,
				'licenses_total'           => 1,
			)
		);

		$this->assertTrue( $checklist['complete'], 'the optional steps do not hold it back' );
	}

	public function test_woocommerce_steps_depend_on_woocommerce(): void {
		$without = $this->done( array( 'woo_active' => false ) );
		$with    = $this->done(
			array(
				'woo_active' => true,
				'selling'    => true,
			)
		);

		$this->assertArrayHasKey( 'install_woo', $without );
		$this->assertArrayNotHasKey( 'invoices', $without );
		$this->assertArrayHasKey( 'sell', $with );
		$this->assertTrue( $with['sell'] );
		$this->assertArrayHasKey( 'invoices', $with );
		$this->assertArrayNotHasKey( 'install_woo', $with );
	}

	public function test_a_healthy_site_has_no_problems(): void {
		$this->assertSame(
			array(),
			OverviewReport::problems(
				array(
					'now'              => 1000000,
					'storage_writable' => true,
					'last_maintenance' => 1000000 - 3600,
					'next_maintenance' => 1000000 + 82800,
				)
			)
		);
	}

	public function test_problems_are_listed_with_the_serious_ones_first(): void {
		$problems = OverviewReport::problems(
			array(
				'migration_attention'      => true,
				'storage_writable'         => false,
				'products_without_release' => 3,
				'cron_disabled'            => true,
			)
		);

		$this->assertSame( array( 'migration', 'storage', 'no_release', 'cron_disabled' ), array_column( $problems, 'key' ) );
		$this->assertSame( array( 'error', 'error', 'warning', 'warning' ), array_column( $problems, 'level' ) );
		$this->assertSame( 3, $problems[2]['count'] );
	}

	public function test_paid_orders_without_their_license_come_first(): void {
		$problems = OverviewReport::problems(
			array(
				'order_problems'      => 2,
				'migration_attention' => true,
			)
		);

		$this->assertSame( array( 'orders', 'migration' ), array_column( $problems, 'key' ) );
		$this->assertSame( 2, $problems[0]['count'] );
		$this->assertSame( array(), OverviewReport::problems( array( 'order_problems' => 0 ) ) );
	}

	public function test_a_daily_job_that_stopped_is_reported(): void {
		$now = 10000000;

		$stale = OverviewReport::problems(
			array(
				'now'              => $now,
				'last_maintenance' => $now - OverviewReport::STALE_AFTER - 10,
			)
		);
		$fresh = OverviewReport::problems(
			array(
				'now'              => $now,
				'last_maintenance' => $now - OverviewReport::STALE_AFTER + 10,
			)
		);

		$this->assertSame( array( 'job_stale' ), array_column( $stale, 'key' ) );
		$this->assertSame( array(), $fresh );
	}

	public function test_a_daily_job_that_never_ran_is_reported_only_when_it_is_long_overdue(): void {
		$now = 10000000;

		$overdue = OverviewReport::problems(
			array(
				'now'              => $now,
				'last_maintenance' => 0,
				'next_maintenance' => $now - 90000,
			)
		);
		$recent  = OverviewReport::problems(
			array(
				'now'              => $now,
				'last_maintenance' => 0,
				'next_maintenance' => $now - 3600,
			)
		);

		$this->assertSame( array( 'job_never' ), array_column( $overdue, 'key' ) );
		$this->assertSame( array(), $recent, 'a new install has not had its first run yet' );
	}

	public function test_switched_off_cron_replaces_the_job_age_warnings(): void {
		$problems = OverviewReport::problems(
			array(
				'now'              => 10000000,
				'cron_disabled'    => true,
				'last_maintenance' => 1,
			)
		);

		$this->assertSame( array( 'cron_disabled' ), array_column( $problems, 'key' ) );
	}
}
