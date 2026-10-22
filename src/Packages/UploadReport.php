<?php
/**
 * Tells the seller what a product save did.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Packages;

use Devllo\WPLicenseIt\Products\SaveReport;

/**
 * Keeps the result of a product save for the next page load and prints it. Some of the text comes from the
 * uploaded zip, so it is always escaped when printed.
 */
final class UploadReport {

	private const TRANSIENT = 'wplit_package_report_';

	/**
	 * Keeps a report for the next page load of the current user.
	 *
	 * @param SaveReport $report Report.
	 */
	public static function queue( SaveReport $report ): void {
		set_transient( self::TRANSIENT . get_current_user_id(), $report->to_array(), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears the queued report, if any.
	 */
	public static function render(): void {
		$key  = self::TRANSIENT . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );

		$report = SaveReport::from_array( $data );

		self::notice( 'error', $report->errors, false );
		self::notice( 'success', $report->messages, false );
		self::notice( 'warning', $report->warnings, true );
	}

	/**
	 * One notice box.
	 *
	 * @param string   $type   error, success or warning.
	 * @param string[] $lines  Text lines.
	 * @param bool     $titled Whether to introduce the list.
	 */
	private static function notice( string $type, array $lines, bool $titled ): void {
		if ( ! $lines ) {
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible">';
		if ( $titled ) {
			echo '<p><strong>' . esc_html__( 'Worth a look:', 'wplicense-it' ) . '</strong></p>';
		}
		echo '<ul style="list-style:disc;margin:0.5em 0 0.5em 20px">';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul></div>';
	}
}
