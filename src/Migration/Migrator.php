<?php
/**
 * 1.x to 2.0 migration.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\LicenseService;

/**
 * Copies 1.x licenses and orders into the 2.0 tables, then switches 2.0 on.
 *
 * - Never modifies the 1.x tables.
 * - Resumable: progress is saved after every batch, and every write is safe to repeat.
 * - Switches over (mark_migrated) only after verification passes, or when told to accept errors.
 * - After the switch, one more pass picks up anything 1.x wrote in the meantime.
 *
 * Phases: licenses, orders, products, verify, (switch), licenses and orders again, done.
 */
final class Migrator {

	private const SAMPLE_SIZE    = 25;
	private const VERIFY_PREFIX  = 'Verify: ';

	/**
	 * Converts the product meta, returns the number of products updated.
	 *
	 * @var callable
	 */
	private $migrate_products;

	/**
	 * Switches 2.0 on (sets the database version).
	 *
	 * @var callable
	 */
	private $mark_migrated;

	/**
	 * Returns the product API key for a product ID, to report keys that no longer match.
	 *
	 * @var callable|null
	 */
	private $api_key_for;

	/**
	 * Returns the current time as a float (seconds), for the time budget.
	 *
	 * @var callable
	 */
	private $time;

	/**
	 * Returns the current UTC time as a DateTimeImmutable.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param LegacySource    $source           1.x tables.
	 * @param MigrationTarget $target           2.0 tables.
	 * @param StateStore      $store            Progress storage.
	 * @param LicenseService  $licenses         Licensing core, used to verify.
	 * @param LegacyConverter $converter        Row conversion.
	 * @param callable        $migrate_products Converts product meta, returns the count.
	 * @param callable        $mark_migrated    Switches 2.0 on.
	 * @param bool            $keep_billing     Keep 1.x billing details as JSON.
	 * @param callable|null   $api_key_for      Optional, product ID to API key.
	 * @param callable|null   $time             Optional, returns microtime(true).
	 * @param callable|null   $clock            Optional, returns the current UTC DateTimeImmutable.
	 */
	public function __construct(
		private LegacySource $source,
		private MigrationTarget $target,
		private StateStore $store,
		private LicenseService $licenses,
		private LegacyConverter $converter,
		callable $migrate_products,
		callable $mark_migrated,
		private bool $keep_billing = true,
		?callable $api_key_for = null,
		?callable $time = null,
		?callable $clock = null
	) {
		$this->migrate_products = $migrate_products;
		$this->mark_migrated    = $mark_migrated;
		$this->api_key_for      = $api_key_for;
		$this->time             = $time ?? static fn(): float => microtime( true );
		$this->clock            = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Current progress.
	 */
	public function state(): MigrationState {
		return $this->store->load();
	}

	/**
	 * Runs as much of the migration as fits in the time budget.
	 *
	 * @param int  $budget_seconds Stop starting new batches after this long.
	 * @param int  $batch          Rows per batch.
	 * @param bool $accept_errors  Switch over even if verification found problems.
	 */
	public function run( int $budget_seconds = 20, int $batch = 200, bool $accept_errors = false ): MigrationState {
		$state = $this->store->load();

		if ( MigrationState::DONE === $state->status ) {
			return $state;
		}

		$state->status = MigrationState::RUNNING;
		$deadline      = ( $this->time )() + $budget_seconds;

		while ( true ) {
			switch ( $state->phase ) {
				case MigrationState::PHASE_LICENSES:
					if ( ! $this->licenses_batch( $state, $batch ) ) {
						$state->phase = MigrationState::PHASE_ORDERS;
					}
					break;

				case MigrationState::PHASE_ORDERS:
					if ( ! $this->orders_batch( $state, $batch ) ) {
						if ( $state->finalized ) {
							$state->status = MigrationState::DONE;
							$this->store->save( $state );

							return $state;
						}
						$state->phase = MigrationState::PHASE_PRODUCTS;
					}
					break;

				case MigrationState::PHASE_PRODUCTS:
					$state->products_updated = (int) ( $this->migrate_products )();
					$state->phase            = MigrationState::PHASE_VERIFY;
					break;

				case MigrationState::PHASE_VERIFY:
					$problems = $this->verify();
					$state->problems = array_values(
						array_filter(
							$state->problems,
							static fn( string $problem ): bool => 0 !== strpos( $problem, self::VERIFY_PREFIX )
						)
					);

					if ( array() !== $problems && ! $accept_errors ) {
						foreach ( $problems as $problem ) {
							$state->add_problem( self::VERIFY_PREFIX . $problem );
						}
						$state->status = MigrationState::NEEDS_ATTENTION;
						$this->store->save( $state );

						return $state;
					}

					// Switch over, then pick up anything 1.x wrote since the first pass.
					( $this->mark_migrated )();
					$state->finalized = true;
					$state->phase     = MigrationState::PHASE_LICENSES;
					break;
			}

			$this->store->save( $state );

			if ( ( $this->time )() >= $deadline ) {
				return $state;
			}
		}
	}

	/**
	 * Checks that everything arrived and that migrated licenses still work.
	 *
	 * @return string[] Problems, empty if all is well.
	 */
	public function verify(): array {
		$problems = array();

		$legacy_licenses   = $this->source->count_licenses();
		$migrated_licenses = $this->target->count_migrated_licenses();
		if ( $legacy_licenses !== $migrated_licenses ) {
			$problems[] = "1.x has {$legacy_licenses} licenses but {$migrated_licenses} were migrated.";
		}

		$legacy_orders   = $this->source->count_orders();
		$migrated_orders = $this->target->count_migrated_orders();
		if ( $legacy_orders !== $migrated_orders ) {
			$problems[] = "1.x has {$legacy_orders} orders but {$migrated_orders} were migrated.";
		}

		foreach ( $this->source->sample_usable_licenses( self::SAMPLE_SIZE ) as $row ) {
			$result = $this->licenses->validate(
				trim( (string) ( $row['license_key'] ?? '' ) ),
				(int) ( $row['product_id'] ?? 0 ),
				(string) ( $row['email'] ?? '' )
			);

			if ( ! $result->is_valid() ) {
				$problems[] = 'License ' . (int) ( $row['id'] ?? 0 ) . ' worked in 1.x but does not validate now (' . $result->code . ').';
			}
		}

		return $problems;
	}

	/**
	 * Starts over from the first row. Safe: rows already migrated are recognised and skipped.
	 * Not allowed after the switch-over.
	 *
	 * @throws \LogicException If the migration has already switched over.
	 */
	public function restart(): MigrationState {
		$state = $this->store->load();

		if ( $state->finalized ) {
			throw new \LogicException( 'The migration has already switched over.' );
		}

		$state = new MigrationState();
		$this->store->save( $state );

		return $state;
	}

	/**
	 * Migrates one batch of licenses.
	 *
	 * @param MigrationState $state State, updated in place.
	 * @param int            $batch Rows per batch.
	 * @return bool False when there were no more rows.
	 */
	private function licenses_batch( MigrationState $state, int $batch ): bool {
		$rows = $this->source->licenses_after( $state->license_cursor, $batch );

		if ( array() === $rows ) {
			return false;
		}

		$now = ( $this->clock )();

		foreach ( $rows as $row ) {
			$id                   = (int) $row['id'];
			$state->license_cursor = max( $state->license_cursor, $id );

			try {
				$converted = $this->converter->license( $row, $now );
			} catch ( InvalidLegacyRow $e ) {
				++$state->licenses_skipped;
				$state->add_problem( $e->getMessage() );
				continue;
			}

			foreach ( $converted['notes'] as $note ) {
				++$state->warnings;
				$state->add_problem( 'Note: ' . $note );
			}

			$this->check_api_key( $state, $row );

			$result = $this->target->import_license( $converted['license'], $id );

			if ( MigrationTarget::CONFLICT === $result ) {
				++$state->licenses_skipped;
				$state->add_problem( "License {$id}: its key is already used by another license." );
				continue;
			}

			++$state->licenses_migrated;
		}

		return true;
	}

	/**
	 * Migrates one batch of orders.
	 *
	 * @param MigrationState $state State, updated in place.
	 * @param int            $batch Rows per batch.
	 * @return bool False when there were no more rows.
	 */
	private function orders_batch( MigrationState $state, int $batch ): bool {
		$rows = $this->source->orders_after( $state->order_cursor, $batch );

		if ( array() === $rows ) {
			return false;
		}

		$now = ( $this->clock )();

		foreach ( $rows as $row ) {
			$id                = (int) $row['id'];
			$state->order_cursor = max( $state->order_cursor, $id );

			try {
				$order = $this->converter->order( $row, $now, $this->keep_billing );
			} catch ( InvalidLegacyRow $e ) {
				++$state->orders_skipped;
				$state->add_problem( $e->getMessage() );
				continue;
			}

			// 1.x order numbers end in 3 random characters, so two orders on one day can collide.
			$base      = $order->order_number;
			$suffix    = 2;
			while ( $this->target->order_number_taken( $order->order_number ) && $suffix < 1000 ) {
				$order->order_number = $base . '-' . $suffix;
				++$suffix;
			}

			$this->target->import_order( $order );
			++$state->orders_migrated;
		}

		return true;
	}

	/**
	 * Reports licenses whose 1.x product API key no longer matches the product.
	 *
	 * @param MigrationState       $state State, updated in place.
	 * @param array<string, mixed> $row   1.x license row.
	 */
	private function check_api_key( MigrationState $state, array $row ): void {
		if ( null === $this->api_key_for || ! isset( $row['product_api_key'] ) ) {
			return;
		}

		$current = (string) ( $this->api_key_for )( (int) ( $row['product_id'] ?? 0 ) );

		if ( '' !== $current && ! hash_equals( $current, (string) $row['product_api_key'] ) ) {
			++$state->warnings;
			$state->add_problem( 'Note: License ' . (int) $row['id'] . ' has an old product API key. 1.x clients using it will stop validating.' );
		}
	}
}
