<?php
/**
 * WP-CLI commands for the migration.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use WP_CLI;

/**
 * Migrates WPLicense It 1.x data to the 2.0 tables.
 *
 * ## EXAMPLES
 *
 *     wp wplit migration status
 *     wp wplit migration run
 *     wp wplit migration verify
 */
final class Command {

	/**
	 * Runs the migration until it finishes or needs attention.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Rows per batch. Default 200.
	 *
	 * [--accept-errors]
	 * : Switch to 2.0 even if verification found problems.
	 *
	 * [--restart]
	 * : Start from the first row. Rows that were already migrated are skipped. Not possible after the switch-over.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function run( array $args, array $assoc_args ): void {
		$batch  = max( 1, (int) ( $assoc_args['batch'] ?? 200 ) );
		$accept = isset( $assoc_args['accept-errors'] );

		$migrator = MigrationRunner::migrator();

		if ( ! ( new WpdbLegacySource( $GLOBALS['wpdb'] ) )->has_tables() ) {
			update_option( 'wplit_db_version', '2.0.0', true );
			WP_CLI::success( 'There is no 1.x data. 2.0 is now active.' );

			return;
		}

		if ( isset( $assoc_args['restart'] ) ) {
			try {
				$migrator->restart();
			} catch ( \LogicException $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		do {
			$state = $migrator->run( 30, $batch, $accept );
			WP_CLI::log( sprintf( '%s: %d licenses, %d orders (skipped: %d, %d)', $state->phase, $state->licenses_migrated, $state->orders_migrated, $state->licenses_skipped, $state->orders_skipped ) );
		} while ( MigrationState::RUNNING === $state->status );

		foreach ( $state->problems as $problem ) {
			WP_CLI::warning( $problem );
		}

		if ( MigrationState::DONE === $state->status ) {
			WP_CLI::success( 'Migration finished. 2.0 is now active.' );
		} else {
			WP_CLI::error( 'Verification found problems. Fix them and run again, or use --accept-errors.' );
		}
	}

	/**
	 * Shows migration progress.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		$state = ( new OptionStateStore() )->load();

		WP_CLI::log( 'Status: ' . $state->status . ' (phase: ' . $state->phase . ( $state->finalized ? ', switched over' : '' ) . ')' );
		WP_CLI::log( sprintf( 'Licenses: %d migrated, %d skipped. Orders: %d migrated, %d skipped. Products updated: %d. Warnings: %d.', $state->licenses_migrated, $state->licenses_skipped, $state->orders_migrated, $state->orders_skipped, $state->products_updated, $state->warnings ) );

		foreach ( $state->problems as $problem ) {
			WP_CLI::log( ' - ' . $problem );
		}
	}

	/**
	 * Checks that all 1.x data arrived and that migrated licenses validate.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function verify( array $args, array $assoc_args ): void {
		$problems = MigrationRunner::migrator()->verify();

		foreach ( $problems as $problem ) {
			WP_CLI::warning( $problem );
		}

		if ( array() === $problems ) {
			WP_CLI::success( 'All 1.x licenses and orders are present, and sampled licenses validate.' );
		} else {
			WP_CLI::error( count( $problems ) . ' problem(s) found.' );
		}
	}
}
