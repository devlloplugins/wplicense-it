<?php
/**
 * Tests for the admin screens' helper logic.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Admin\DateInput;
use Devllo\WPLicenseIt\Admin\LicenseView;
use Devllo\WPLicenseIt\Admin\Maintenance;
use Devllo\WPLicenseIt\Admin\SettingsScreen;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';

/**
 * @covers \Devllo\WPLicenseIt\Admin\DateInput
 * @covers \Devllo\WPLicenseIt\Admin\Maintenance
 * @covers \Devllo\WPLicenseIt\Admin\LicenseView
 * @covers \Devllo\WPLicenseIt\Admin\SettingsScreen
 */
final class AdminHelpersTest extends TestCase {

	public function test_a_typed_date_is_valid_through_the_end_of_that_day_on_the_site(): void {
		$zone = new DateTimeZone( 'America/New_York' );

		$summer = DateInput::end_of_day_utc( '2026-07-01', $zone );
		$winter = DateInput::end_of_day_utc( '2026-01-01', $zone );

		$this->assertSame( '2026-07-02 03:59:59', $summer->format( 'Y-m-d H:i:s' ) ); // UTC-4 in summer.
		$this->assertSame( '2026-01-02 04:59:59', $winter->format( 'Y-m-d H:i:s' ) ); // UTC-5 in winter.
		$this->assertSame( 'UTC', $summer->getTimezone()->getName() );
	}

	public function test_bad_dates_are_rejected(): void {
		$zone = new DateTimeZone( 'UTC' );

		foreach ( array( '', 'tomorrow', '2026-13-01', '2026-02-30', '26-01-01', '2026-1-1', '2026-01-01 10:00' ) as $bad ) {
			$this->assertNull( DateInput::end_of_day_utc( $bad, $zone ), $bad );
		}
	}

	public function test_the_date_field_round_trips(): void {
		$zone    = new DateTimeZone( 'Asia/Tokyo' );
		$stored  = DateInput::end_of_day_utc( '2026-12-31', $zone );

		$this->assertSame( '2026-12-31', DateInput::to_field( $stored, $zone ) );
		$this->assertSame( '', DateInput::to_field( null, $zone ) );
	}

	public function test_expiring_everything_due_works_in_batches(): void {
		$clock   = new FixedClock( '2026-01-01 00:00:00' );
		$service = new LicenseService( new InMemoryLicenseRepository(), new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), $clock );
		$soon    = new DateTimeImmutable( '2026-02-01 00:00:00', new DateTimeZone( 'UTC' ) );

		for ( $i = 0; $i < 7; $i++ ) {
			$service->issue_license( 7, "user{$i}@example.com", null, null, 1, $soon );
		}
		$service->issue_license( 7, 'later@example.com', null, null, 1, new DateTimeImmutable( '2027-01-01', new DateTimeZone( 'UTC' ) ) );
		$clock->advance( '+2 months' );

		$this->assertSame( 7, Maintenance::expire_all( $service, 3, 10 ) ); // Three batches of 3, 3 and 1.
		$this->assertSame( 0, Maintenance::expire_all( $service, 3, 10 ) );
		$this->assertSame( 7, $service->status_counts()[ Status::EXPIRED ] );
	}

	public function test_a_huge_backlog_is_capped_per_run(): void {
		$clock   = new FixedClock( '2026-01-01 00:00:00' );
		$service = new LicenseService( new InMemoryLicenseRepository(), new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), $clock );
		$soon    = new DateTimeImmutable( '2026-02-01 00:00:00', new DateTimeZone( 'UTC' ) );

		for ( $i = 0; $i < 10; $i++ ) {
			$service->issue_license( 7, "user{$i}@example.com", null, null, 1, $soon );
		}
		$clock->advance( '+2 months' );

		$this->assertSame( 6, Maintenance::expire_all( $service, 2, 3 ) ); // 3 batches of 2; the rest waits for tomorrow.
	}

	public function test_history_details_are_readable(): void {
		$this->assertSame( 'email: a@x.com → b@x.com, activation_limit: 1 → 5', LicenseView::event_details( array( 'email' => array( 'a@x.com', 'b@x.com' ), 'activation_limit' => array( 1, 5 ) ) ) );
		$this->assertSame( 'site: one.com', LicenseView::event_details( array( 'site' => 'one.com' ) ) );
		$this->assertSame( 'expires_at: — → 2027-01-01 00:00:00', LicenseView::event_details( array( 'expires_at' => array( null, '2027-01-01 00:00:00' ) ) ) );
		$this->assertSame( '', LicenseView::event_details( array() ) );
	}

	public function test_settings_values_are_clamped(): void {
		$this->assertSame( 20, SettingsScreen::clamp( 'abc', 1, 1000, 20 ) );
		$this->assertSame( 1000, SettingsScreen::clamp( '99999', 1, 1000, 20 ) );
		$this->assertSame( 1, SettingsScreen::clamp( '-5', 1, 1000, 20 ) );
		$this->assertSame( 0, SettingsScreen::clamp( '0', 0, 20, 2 ) );
		$this->assertSame( 'processing', SettingsScreen::sanitize_issue_on( 'processing' ) );
		$this->assertSame( 'completed', SettingsScreen::sanitize_issue_on( 'anything else' ) );
	}
}
