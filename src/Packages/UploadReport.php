<?php
/**
 * Tells the seller what was read from an uploaded package.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Packages;

/**
 * Builds the message shown after a zip is uploaded, keeps it for the next page load and prints it.
 * The text comes from the zip, so it is always escaped when printed.
 */
final class UploadReport {

	private const TRANSIENT = 'wplit_package_report_';

	/**
	 * The report for a zip that was read.
	 *
	 * @param PackageInfo $info Details read from the zip.
	 * @return array{ok:bool,message:string,warnings:string[]}
	 */
	public static function success( PackageInfo $info ): array {
		$parts = array();
		if ( '' !== $info->requires_wp ) {
			$parts[] = 'WordPress ' . $info->requires_wp;
		}
		if ( '' !== $info->requires_php ) {
			$parts[] = 'PHP ' . $info->requires_php;
		}

		$message = sprintf( 'Read from the zip: %1$s %2$s (%3$s).', $info->name, $info->version, $info->type );
		if ( $parts ) {
			$message .= ' Needs ' . implode( ' and ', $parts ) . '.';
		}
		$message .= ' The version and requirements below were filled in from the file.';

		return array(
			'ok'       => true,
			'message'  => $message,
			'warnings' => $info->warnings,
		);
	}

	/**
	 * The report for a zip that was refused.
	 *
	 * @param PackageException $error Why.
	 * @return array{ok:bool,message:string,warnings:string[]}
	 */
	public static function failure( PackageException $error ): array {
		return array(
			'ok'       => false,
			'message'  => 'The file was not saved. ' . $error->getMessage(),
			'warnings' => array(),
		);
	}

	/**
	 * Keeps a report for the next page load of the current user.
	 *
	 * @param array{ok:bool,message:string,warnings:string[]} $report Report.
	 */
	public static function queue( array $report ): void {
		set_transient( self::TRANSIENT . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears the queued report, if any.
	 */
	public static function render(): void {
		$key    = self::TRANSIENT . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) || ! isset( $report['message'] ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p>',
			! empty( $report['ok'] ) ? 'success' : 'error',
			esc_html( (string) $report['message'] )
		);
		if ( ! empty( $report['warnings'] ) && is_array( $report['warnings'] ) ) {
			echo '<ul style="list-style:disc;margin-left:20px">';
			foreach ( $report['warnings'] as $warning ) {
				echo '<li>' . esc_html( (string) $warning ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
