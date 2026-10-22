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
use Devllo\WPLicenseIt\Api\WpProductCatalog;
use Devllo\WPLicenseIt\Database\WpdbEventLog;
use Devllo\WPLicenseIt\Database\WpdbLicenseRepository;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Reminders\ReminderService;
use Devllo\WPLicenseIt\Renewals\RenewalInvoiceService;
use Devllo\WPLicenseIt\Renewals\RenewalSettings;
use Devllo\WPLicenseIt\Reminders\ReminderSettings;
use Devllo\WPLicenseIt\Reminders\WpReminderMailer;
use Devllo\WPLicenseIt\WooCommerce\OrderReader;
use Devllo\WPLicenseIt\WooCommerce\WooRenewalOrderCreator;

/**
 * Once a day: marks licenses past their expiry as expired, sends expiry reminders (when the site owner turned them on), and
 * deletes old audit events.
 * Licenses are valid or not at the moment of each check regardless, so this only keeps the
 * stored status and the admin list accurate.
 */
final class Maintenance {

	public const HOOK            = 'wplit_daily_maintenance';
	public const LAST_RUN_OPTION = 'wplit_last_maintenance';

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

		$reminders = new ReminderService( new WpdbLicenseRepository( $wpdb ), new WpdbEventLog( $wpdb ), new WpReminderMailer( new WpProductCatalog() ) );

		if ( ReminderSettings::enabled() ) {
			$reminders->send_due( ReminderSettings::days() );
		}

		if ( ReminderSettings::after_enabled() ) {
			$reminders->send_after( ReminderSettings::after_days() );
		}

		if ( RenewalSettings::enabled() && class_exists( 'WooCommerce' ) ) {
			( new RenewalInvoiceService( new WpdbLicenseRepository( $wpdb ), new WpdbEventLog( $wpdb ), new WooRenewalOrderCreator( new OrderReader() ) ) )->create_due( RenewalSettings::days() );
		}

		// Written down for the Overview screen, which warns when the daily job has stopped running.
		update_option( self::LAST_RUN_OPTION, time(), false );

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
			$done   = $licenses->expire_due_licenses( $batch );
			$total += $done;

			if ( $done < $batch ) {
				break;
			}
		}

		return $total;
	}
}
