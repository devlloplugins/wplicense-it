<?php
/**
 * Tests for the licensing core.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\ActivationResult;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseException;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Licenses\ValidationResult;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Licenses\LicenseService
 * @covers \Devllo\WPLicenseIt\Licenses\License
 */
final class LicenseServiceTest extends TestCase {

	private LicenseService $service;
	private FixedClock $clock;
	private RecordingEventLog $events;

	protected function setUp(): void {
		$this->clock   = new FixedClock( '2026-01-01 00:00:00' );
		$this->events  = new RecordingEventLog();
		$this->service = new LicenseService(
			new InMemoryLicenseRepository(),
			new InMemoryActivationRepository(),
			$this->events,
			new KeyGenerator(),
			$this->clock
		);
	}

	private function utc( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
	}

	// Issuing.

	public function test_issue_creates_an_active_license_and_logs_it(): void {
		$license = $this->service->issue_license( 7, '  Buyer@Example.COM ', 3, 11, 2, $this->utc( '2027-01-01 00:00:00' ) );

		$this->assertSame( 1, $license->id );
		$this->assertSame( 7, $license->product_id );
		$this->assertSame( 'buyer@example.com', $license->email );
		$this->assertSame( Status::ACTIVE, $license->status );
		$this->assertSame( 2, $license->activation_limit );
		$this->assertSame( array( EventLog::ISSUED ), $this->events->types() );
	}

	public function test_issue_generates_unique_keys(): void {
		$a = $this->service->issue_license( 7, 'a@example.com' );
		$b = $this->service->issue_license( 7, 'b@example.com' );

		$this->assertNotSame( $a->license_key, $b->license_key );
	}

	public function test_issue_rejects_bad_input(): void {
		foreach (
			array(
				array( 0, 'a@example.com', 1 ),
				array( 7, 'not-an-email', 1 ),
				array( 7, '', 1 ),
				array( 7, 'a@example.com', -1 ),
			) as $args
		) {
			try {
				$this->service->issue_license( $args[0], $args[1], null, null, $args[2] );
				$this->fail( 'Expected an exception.' );
			} catch ( LicenseException $e ) {
				$this->assertSame( LicenseException::INVALID_INPUT, $e->reason() );
			}
		}
	}

	// Abuse limits.

	public function test_local_sites_are_capped_so_one_key_cannot_create_endless_rows(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1 );

		for ( $i = 1; $i <= LicenseService::MAX_LOCAL_SITES; $i++ ) {
			$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, "site{$i}.test" )->code );
		}

		$this->assertSame( ActivationResult::LIMIT_REACHED, $this->service->activate( $license->license_key, 'one-too-many.test' )->code );

		// Freeing one makes room again, and real sites are unaffected by the local count.
		$this->service->deactivate( $license->license_key, 'site1.test' );
		$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, 'one-too-many.test' )->code );
		$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, 'live.com' )->code );
	}

	public function test_absurd_activation_limits_are_rejected(): void {
		foreach ( array( LicenseService::MAX_ACTIVATION_LIMIT + 1, PHP_INT_MAX ) as $limit ) {
			try {
				$this->service->issue_license( 7, 'a@example.com', null, null, $limit );
				$this->fail( 'Expected an exception.' );
			} catch ( LicenseException $e ) {
				$this->assertSame( LicenseException::INVALID_INPUT, $e->reason() );
			}
		}

		$this->assertSame( LicenseService::MAX_ACTIVATION_LIMIT, $this->service->issue_license( 7, 'a@example.com', null, null, LicenseService::MAX_ACTIVATION_LIMIT )->activation_limit );
	}

	// Validation.

	public function test_validate_accepts_a_good_license(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		$result = $this->service->validate( $license->license_key, 7, 'A@example.com' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( $license->id, $result->license->id );
	}

	public function test_validate_reports_each_failure(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );

		$this->assertSame( ValidationResult::NOT_FOUND, $this->service->validate( 'nope' )->code );
		$this->assertSame( ValidationResult::NOT_FOUND, $this->service->validate( '' )->code );
		$this->assertSame( ValidationResult::PRODUCT_MISMATCH, $this->service->validate( $license->license_key, 8 )->code );
		$this->assertSame( ValidationResult::EMAIL_MISMATCH, $this->service->validate( $license->license_key, 7, 'b@example.com' )->code );

		$this->clock->advance( '+2 months' );
		$this->assertSame( ValidationResult::EXPIRED, $this->service->validate( $license->license_key )->code );
	}

	public function test_a_lifetime_license_never_expires(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		$this->clock->advance( '+50 years' );

		$this->assertTrue( $this->service->validate( $license->license_key )->is_valid() );
	}

	public function test_expiry_is_exclusive_at_the_exact_moment(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );

		$this->clock->advance( '+31 days -1 second' );
		$this->assertTrue( $this->service->validate( $license->license_key )->is_valid() );

		$this->clock->advance( '+1 second' );
		$this->assertSame( ValidationResult::EXPIRED, $this->service->validate( $license->license_key )->code );
	}

	// Renewal.

	public function test_renew_extends_from_the_current_expiry(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );

		$renewed = $this->service->renew_license( $license->id, new DateInterval( 'P1Y' ) );

		$this->assertSame( '2027-02-01 00:00:00', $renewed->expires_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( EventLog::RENEWED, $this->events->types()[1] );
	}

	public function test_renew_an_expired_license_starts_from_now_and_reactivates_it(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );
		$this->clock->advance( '+6 months' ); // Now 2026-07-01.
		$this->service->expire_due_licenses();

		$renewed = $this->service->renew_license( $license->id, new DateInterval( 'P1M' ) );

		$this->assertSame( Status::ACTIVE, $renewed->status );
		$this->assertSame( '2026-08-01 00:00:00', $renewed->expires_at->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( $this->service->validate( $license->license_key )->is_valid() );
	}

	public function test_renew_leaves_a_lifetime_license_alone(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		$renewed = $this->service->renew_license( $license->id, new DateInterval( 'P1Y' ) );

		$this->assertNull( $renewed->expires_at );
	}

	public function test_renew_refuses_revoked_and_refunded_licenses(): void {
		$revoked = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );
		$this->service->revoke_license( $revoked->id );

		$this->expectException( LicenseException::class );
		$this->service->renew_license( $revoked->id, new DateInterval( 'P1Y' ) );
	}

	public function test_renew_unknown_license_fails(): void {
		try {
			$this->service->renew_license( 999, new DateInterval( 'P1Y' ) );
			$this->fail( 'Expected an exception.' );
		} catch ( LicenseException $e ) {
			$this->assertSame( LicenseException::NOT_FOUND, $e->reason() );
		}
	}

	// Revocation.

	public function test_revoke_and_refund_invalidate_the_license(): void {
		$a = $this->service->issue_license( 7, 'a@example.com' );
		$b = $this->service->issue_license( 7, 'b@example.com' );

		$this->service->revoke_license( $a->id );
		$this->service->revoke_license( $b->id, Status::REFUNDED );

		$this->assertSame( ValidationResult::REVOKED, $this->service->validate( $a->license_key )->code );
		$this->assertSame( ValidationResult::REFUNDED, $this->service->validate( $b->license_key )->code );
		$this->assertContains( EventLog::REVOKED, $this->events->types() );
		$this->assertContains( EventLog::REFUNDED, $this->events->types() );
	}

	public function test_revoking_twice_logs_once(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		$this->service->revoke_license( $license->id );
		$this->service->revoke_license( $license->id );

		$this->assertSame( array( EventLog::ISSUED, EventLog::REVOKED ), $this->events->types() );
	}

	public function test_revoke_only_accepts_terminal_statuses(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		$this->expectException( LicenseException::class );
		$this->service->revoke_license( $license->id, Status::ACTIVE );
	}

	// Activations.

	public function test_activate_uses_a_slot_and_is_idempotent_per_site(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1 );

		$first  = $this->service->activate( $license->license_key, 'https://www.Example.com/', 7, '1.2.0' );
		$again  = $this->service->activate( $license->license_key, 'example.com', 7 );

		$this->assertSame( ActivationResult::ACTIVATED, $first->code );
		$this->assertSame( 'example.com', $first->activation->site );
		$this->assertSame( ActivationResult::ALREADY_ACTIVE, $again->code );
		$this->assertTrue( $again->is_success() );
		$this->assertSame( '1.2.0', $again->activation->product_version );
	}

	public function test_activation_limit_is_enforced(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 2 );

		$this->service->activate( $license->license_key, 'one.com' );
		$this->service->activate( $license->license_key, 'two.com' );
		$third = $this->service->activate( $license->license_key, 'three.com' );

		$this->assertSame( ActivationResult::LIMIT_REACHED, $third->code );
		$this->assertFalse( $third->is_success() );
	}

	public function test_unlimited_licenses_have_no_cap(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 0 );

		for ( $i = 1; $i <= 25; $i++ ) {
			$this->assertTrue( $this->service->activate( $license->license_key, "site{$i}.com" )->is_success() );
		}
	}

	public function test_local_and_staging_sites_do_not_use_a_slot(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1 );

		$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, 'localhost:8080' )->code );
		$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, 'shop.test' )->code );
		$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, 'staging.example.com' )->code );
		$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, 'live.com' )->code );
		$this->assertSame( ActivationResult::LIMIT_REACHED, $this->service->activate( $license->license_key, 'other-live.com' )->code );
	}

	public function test_deactivating_frees_the_slot_and_the_site_can_come_back(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1 );
		$this->service->activate( $license->license_key, 'one.com' );

		$gone = $this->service->deactivate( $license->license_key, 'https://one.com' );
		$this->assertSame( ActivationResult::DEACTIVATED, $gone->code );

		$this->assertSame( ActivationResult::ACTIVATED, $this->service->activate( $license->license_key, 'two.com' )->code );
		$this->assertSame( ActivationResult::LIMIT_REACHED, $this->service->activate( $license->license_key, 'one.com' )->code );

		$this->service->deactivate( $license->license_key, 'two.com' );
		$back = $this->service->activate( $license->license_key, 'one.com' );
		$this->assertSame( ActivationResult::ACTIVATED, $back->code );
		$this->assertNull( $back->activation->deactivated_at );
	}

	public function test_deactivate_unknown_site_or_key(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		$this->assertSame( ActivationResult::NOT_ACTIVE, $this->service->deactivate( $license->license_key, 'never.com' )->code );
		$this->assertSame( ValidationResult::NOT_FOUND, $this->service->deactivate( 'nope', 'never.com' )->code );
		$this->assertSame( ActivationResult::INVALID_SITE, $this->service->deactivate( $license->license_key, '   ' )->code );
	}

	public function test_activate_rejects_invalid_licenses_and_sites(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );

		$this->assertSame( ActivationResult::INVALID_SITE, $this->service->activate( $license->license_key, '' )->code );
		$this->assertSame( ValidationResult::NOT_FOUND, $this->service->activate( 'nope', 'one.com' )->code );
		$this->assertSame( ValidationResult::PRODUCT_MISMATCH, $this->service->activate( $license->license_key, 'one.com', 99 )->code );

		$this->clock->advance( '+2 months' );
		$this->assertSame( ValidationResult::EXPIRED, $this->service->activate( $license->license_key, 'one.com' )->code );
	}

	public function test_an_expired_license_can_still_deactivate_sites(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );
		$this->service->activate( $license->license_key, 'one.com' );
		$this->clock->advance( '+2 months' );

		$this->assertSame( ActivationResult::DEACTIVATED, $this->service->deactivate( $license->license_key, 'one.com' )->code );
	}

	public function test_activations_are_logged(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		$this->service->activate( $license->license_key, 'one.com' );
		$this->service->activate( $license->license_key, 'one.com' ); // Already active: no new event.
		$this->service->deactivate( $license->license_key, 'one.com' );

		$this->assertSame( array( EventLog::ISSUED, EventLog::ACTIVATED, EventLog::DEACTIVATED ), $this->events->types() );
		$this->assertSame( 'one.com', $this->events->events[1]['data']['site'] );
	}

	// Expiry sweep.

	public function test_expire_due_licenses_only_touches_due_active_licenses(): void {
		$due      = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );
		$later    = $this->service->issue_license( 7, 'b@example.com', null, null, 1, $this->utc( '2027-02-01 00:00:00' ) );
		$lifetime = $this->service->issue_license( 7, 'c@example.com' );
		$revoked  = $this->service->issue_license( 7, 'd@example.com', null, null, 1, $this->utc( '2026-02-01 00:00:00' ) );
		$this->service->revoke_license( $revoked->id );

		$this->clock->advance( '+2 months' );

		$this->assertSame( 1, $this->service->expire_due_licenses() );
		$this->assertSame( 0, $this->service->expire_due_licenses() ); // Nothing left to do.

		$this->assertSame( ValidationResult::EXPIRED, $this->service->validate( $due->license_key )->code );
		$this->assertTrue( $this->service->validate( $later->license_key )->is_valid() );
		$this->assertTrue( $this->service->validate( $lifetime->license_key )->is_valid() );
		$this->assertSame( ValidationResult::REVOKED, $this->service->validate( $revoked->license_key )->code );
	}
}
