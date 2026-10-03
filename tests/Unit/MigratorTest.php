<?php
/**
 * Tests for the migration orchestration.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Licenses\ValidationResult;
use Devllo\WPLicenseIt\Migration\LegacyConverter;
use Devllo\WPLicenseIt\Migration\MigrationState;
use Devllo\WPLicenseIt\Migration\Migrator;
use Devllo\WPLicenseIt\Tests\Support\ArrayLegacySource;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryMigrationTarget;
use Devllo\WPLicenseIt\Tests\Support\InMemoryStateStore;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Migration\Migrator
 * @covers \Devllo\WPLicenseIt\Migration\MigrationState
 */
final class MigratorTest extends TestCase {

	private ArrayLegacySource $source;
	private InMemoryLicenseRepository $repo;
	private InMemoryMigrationTarget $target;
	private InMemoryStateStore $store;
	private LicenseService $service;
	private FixedClock $clock;

	/**
	 * How many times the switch-over ran.
	 *
	 * @var int
	 */
	private int $switched = 0;

	/**
	 * Called when the migration switches over, lets tests simulate 1.x writing meanwhile.
	 *
	 * @var callable|null
	 */
	private $on_switch = null;

	/**
	 * Fake time, advanced by the time source.
	 *
	 * @var float
	 */
	private float $now = 0.0;

	protected function setUp(): void {
		$this->clock   = new FixedClock( '2026-06-01 00:00:00' );
		$this->source  = new ArrayLegacySource();
		$this->repo    = new InMemoryLicenseRepository();
		$this->target  = new InMemoryMigrationTarget( $this->repo );
		$this->store   = new InMemoryStateStore();
		$this->service = new LicenseService( $this->repo, new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), $this->clock );
	}

	private function migrator( bool $keep_billing = true, ?callable $api_key_for = null, float $tick = 0.0 ): Migrator {
		return new Migrator(
			$this->source,
			$this->target,
			$this->store,
			$this->service,
			new LegacyConverter( new DateTimeZone( 'UTC' ) ),
			static fn(): int => 3,
			function (): void {
				++$this->switched;
				if ( null !== $this->on_switch ) {
					( $this->on_switch )();
				}
			},
			$keep_billing,
			$api_key_for,
			function () use ( $tick ): float {
				$this->now += $tick;

				return $this->now;
			},
			$this->clock
		);
	}

	private function seed( int $licenses = 3, int $orders = 2 ): void {
		for ( $i = 1; $i <= $licenses; $i++ ) {
			$this->source->add_license( $i, array( 'user_id' => (string) ( 10 + $i ) ) );
		}
		for ( $i = 1; $i <= $orders; $i++ ) {
			$this->source->add_order( $i, array( 'user_id' => (string) ( 10 + $i ) ) );
		}
	}

	public function test_a_full_migration_copies_everything_verifies_and_switches_over(): void {
		$this->seed( 3, 2 );

		$state = $this->migrator()->run();

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertTrue( $state->finalized );
		$this->assertSame( 1, $this->switched );
		$this->assertSame( 3, $state->licenses_migrated );
		$this->assertSame( 2, $state->orders_migrated );
		$this->assertSame( 3, $state->products_updated );
		$this->assertSame( array(), $state->problems );
		$this->assertSame( 3, $this->target->count_migrated_licenses() );
	}

	public function test_migrated_licenses_work_with_their_1x_keys(): void {
		$this->seed( 2, 0 );
		$this->migrator()->run();

		$result = $this->service->validate( 'KEY2', 7, 'buyer2@example.com' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 0, $result->license->activation_limit );
	}

	public function test_orders_are_linked_to_their_licenses(): void {
		$this->seed( 2, 2 );
		$this->migrator()->run();

		$this->assertSame( 1, $this->repo->find_by_key( 'KEY1' )->order_id );
		$this->assertSame( 2, $this->repo->find_by_key( 'KEY2' )->order_id );
	}

	public function test_running_again_changes_nothing(): void {
		$this->seed( 3, 2 );
		$this->migrator()->run();

		$state = $this->migrator()->run();

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertSame( 1, $this->switched );
		$this->assertSame( 3, $this->target->count_migrated_licenses() );
		$this->assertSame( 2, $this->target->count_migrated_orders() );
	}

	public function test_it_stops_at_the_time_budget_and_resumes(): void {
		$this->seed( 5, 3 );

		$first = $this->migrator( true, null, 10.0 )->run( 15, 1 ); // Each clock read costs 10 s, so only a batch or two fits.

		$this->assertSame( MigrationState::RUNNING, $first->status );
		$this->assertTrue( $first->licenses_migrated < 5 );

		$guard = 0;
		do {
			$state = $this->migrator( true, null, 10.0 )->run( 15, 1 );
		} while ( MigrationState::RUNNING === $state->status && ++$guard < 100 );

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertSame( 5, $this->target->count_migrated_licenses() );
		$this->assertSame( 3, $this->target->count_migrated_orders() );
		$this->assertSame( 1, $this->switched );
	}

	public function test_rows_that_cannot_be_migrated_stop_the_switch_over_until_accepted(): void {
		$this->seed( 2, 0 );
		$this->source->add_license( 3, array( 'license_key' => '' ) );

		$state = $this->migrator()->run();

		$this->assertSame( MigrationState::NEEDS_ATTENTION, $state->status );
		$this->assertSame( 0, $this->switched );
		$this->assertFalse( $state->finalized );
		$this->assertSame( 1, $state->licenses_skipped );
		$this->assertStringContainsString( 'License 3', $state->problems[0] );

		$accepted = $this->migrator()->run( 20, 200, true );

		$this->assertSame( MigrationState::DONE, $accepted->status );
		$this->assertSame( 1, $this->switched );
	}

	public function test_a_duplicate_key_is_a_conflict_not_a_second_license(): void {
		$this->seed( 1, 0 );
		$this->source->add_license( 2, array( 'license_key' => 'KEY1' ) );

		$state = $this->migrator()->run();

		$this->assertSame( 1, $state->licenses_skipped );
		$this->assertSame( 1, $this->target->count_migrated_licenses() );
		$this->assertSame( MigrationState::NEEDS_ATTENTION, $state->status );
	}

	public function test_a_license_that_2_0_already_issued_is_adopted_not_duplicated(): void {
		$this->seed( 1, 0 );
		$issued = $this->service->issue_license( 7, 'buyer1@example.com' );
		$this->source->licenses[1]['license_key'] = $issued->license_key; // 1.x kept its own copy of the same key.

		$state = $this->migrator()->run();

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertSame( 0, $state->licenses_skipped );
		$this->assertSame( 1, $this->target->count_migrated_licenses() );
	}

	public function test_colliding_order_numbers_get_a_suffix(): void {
		$this->seed( 0, 0 );
		$this->source->add_order( 1, array( 'order_number' => '#SAME' ) );
		$this->source->add_order( 2, array( 'order_number' => '#SAME' ) );
		$this->source->add_order( 3, array( 'order_number' => '#SAME' ) );

		$this->migrator()->run();

		$numbers = array_map( static fn( $order ): string => $order->order_number, $this->target->orders );
		$this->assertSame( array( '#SAME', '#SAME-2', '#SAME-3' ), array_values( $numbers ) );
	}

	public function test_what_1x_writes_while_switching_over_is_picked_up_by_the_last_pass(): void {
		$this->seed( 2, 1 );
		$this->on_switch = function (): void {
			$this->source->add_license( 3 );
			$this->source->add_order( 2 );
		};

		$state = $this->migrator()->run();

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertSame( 3, $this->target->count_migrated_licenses() );
		$this->assertSame( 2, $this->target->count_migrated_orders() );
		$this->assertTrue( $this->service->validate( 'KEY3', 7, 'buyer3@example.com' )->is_valid() );
	}

	public function test_expired_licenses_migrate_as_expired(): void {
		$this->seed( 1, 0 );
		$this->source->add_license( 2, array( 'valid_until' => '2026-01-01 00:00:00' ) );

		$state = $this->migrator()->run();

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertSame( Status::EXPIRED, $this->repo->find_by_key( 'KEY2' )->status );
		$this->assertSame( ValidationResult::EXPIRED, $this->service->validate( 'KEY2' )->code );
	}

	public function test_verification_catches_licenses_that_would_not_validate(): void {
		$this->seed( 2, 0 );
		$this->migrator()->run();

		// Break a migrated license behind the migrator's back.
		$license = $this->repo->find_by_key( 'KEY2' );
		$this->repo->update( $license->with_status( Status::REVOKED, $this->clock->__invoke() ) );

		$problems = $this->migrator()->verify();

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'does not validate', $problems[0] );
	}

	public function test_old_product_api_keys_are_reported_as_warnings(): void {
		$this->seed( 2, 0 );
		$this->source->licenses[2]['product_api_key'] = 'rotated-away';

		$state = $this->migrator( true, static fn( int $product_id ): string => 'prod-key' )->run();

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertSame( 1, $state->warnings );
		$this->assertStringContainsString( 'License 2', $state->problems[0] );
	}

	public function test_restart_is_refused_after_the_switch_over(): void {
		$this->seed( 1, 0 );
		$this->migrator()->run();

		$this->expectException( \LogicException::class );
		$this->migrator()->restart();
	}

	public function test_restart_before_the_switch_over_starts_from_the_beginning_without_duplicates(): void {
		$this->seed( 2, 0 );
		$this->source->add_license( 3, array( 'license_key' => '' ) );
		$this->migrator()->run(); // Needs attention.

		$state = $this->migrator()->restart();
		$this->assertSame( 0, $state->license_cursor );
		$this->assertSame( MigrationState::PENDING, $state->status );

		$this->source->licenses[3]['license_key'] = 'FIXED';
		$done = $this->migrator()->run();

		$this->assertSame( MigrationState::DONE, $done->status );
		$this->assertSame( 3, $this->target->count_migrated_licenses() );
	}

	public function test_an_empty_install_switches_over_cleanly(): void {
		$state = $this->migrator()->run();

		$this->assertSame( MigrationState::DONE, $state->status );
		$this->assertSame( 1, $this->switched );
	}

	public function test_state_round_trips_through_storage(): void {
		$state                   = new MigrationState();
		$state->phase            = MigrationState::PHASE_ORDERS;
		$state->license_cursor   = 42;
		$state->finalized        = true;
		$state->problems         = array( 'a', 'b' );

		$copy = MigrationState::from_array( $state->to_array() );

		$this->assertSame( $state->to_array(), $copy->to_array() );
		$this->assertSame( 42, $copy->license_cursor );
		$this->assertTrue( $copy->finalized );
		$this->assertSame( MigrationState::PENDING, MigrationState::from_array( 'garbage' )->status );
	}
}
