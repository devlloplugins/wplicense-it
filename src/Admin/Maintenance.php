<?php
/**
 * Daily maintenance.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Database\WpdbEventLog;
use Devllo\WPLicenseIt\Licenses\LicenseService;

/**
 * Once a day: marks licenses past their expiry as expired, and deletes old audit events.
 * Licenses are valid or not at the moment of each check regardless, so this only keeps the
 * stored status and the admin list accurate.
 */
final class Maintenance {

	public const HOOK = 'wplit_daily_maintenance';

	/**
	 * Registers the schedule and the callback.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily job if it is not scheduled yet.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Runs the maintenance.
	 */
	public static function run(): void {
		global $wpdb;

		$plugin = \Devllo\WPLicenseIt\Plugin::instance();

		self::expire_all( $plugin->licenses() );

		// Two years by default, changeable in Licensing Settings and with the filter.
		$years = (int) apply_filters( 'wplicense_it_event_retention_years', (int) get_option( 'wplit_event_retention_years', 2 ) );

		if ( $years > 0 ) {
			( new WpdbEventLog( $wpdb ) )->prune( new DateTimeImmutable( "-{$years} years", new DateTimeZone( 'UTC' ) ) );
		}
	}

	/**
	 * Expires every license that is due, in batches so one run cannot take too long.
	 *
	 * @param LicenseService $licenses    Licensing core.
	 * @param int            $batch       Licenses per batch.
	 * @param int            $max_batches Batches per run. A busy backlog continues tomorrow.
	 * @return int Licenses expired.
	 */
	public static function expire_all( LicenseService $licenses, int $batch = 100, int $max_batches = 20 ): int {
		$total = 0;

		for ( $i = 0; $i < $max_batches; $i++ ) {
			$done = $licenses->expire_due_licenses( $batch );
			$total += $done;

			if ( $done < $batch ) {
				break;
			}
		}

		return $total;
	}
}
