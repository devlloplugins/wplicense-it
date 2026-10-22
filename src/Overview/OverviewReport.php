<?php
/**
 * What the Overview screen tells the site owner.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Overview;

/**
 * Turns a few facts about the site into the setup checklist and the list of problems. It knows nothing about WordPress, so it
 * can be tested on its own; the screen adds the wording and the links.
 */
final class OverviewReport {

	public const STALE_AFTER = 172800;
	// Two days without the daily job.

	/**
	 * The setup checklist.
	 *
	 * Required steps are the ones a working install needs; the others are suggestions.
	 *
	 * @param array<string, mixed> $facts See facts() in OverviewScreen.
	 * @return array{items: list<array{key:string,done:bool,required:bool}>, complete: bool}
	 */
	public static function checklist( array $facts ): array {
		$products   = (int) ( $facts['products'] ?? 0 );
		$no_release = (int) ( $facts['products_without_release'] ?? 0 );
		$woo        = ! empty( $facts['woo_active'] );
		$items      = array(
			array(
				'key'      => 'product',
				'done'     => $products > 0,
				'required' => true,
			),
			array(
				'key'      => 'release',
				'done'     => $products > 0 && $no_release < $products,
				'required' => true,
			),
			array(
				'key'      => 'license',
				'done'     => (int) ( $facts['licenses_total'] ?? 0 ) > 0,
				'required' => true,
			),
			array(
				'key'      => $woo ? 'sell' : 'install_woo',
				'done'     => $woo && ! empty( $facts['selling'] ),
				'required' => false,
			),
			array(
				'key'      => 'connect',
				'done'     => ! empty( $facts['sdk_downloaded'] ),
				'required' => false,
			),
			array(
				'key'      => 'reminders',
				'done'     => ! empty( $facts['reminders_on'] ),
				'required' => false,
			),
		);

		if ( $woo ) {
			$items[] = array(
				'key'      => 'invoices',
				'done'     => ! empty( $facts['invoices_on'] ),
				'required' => false,
			);
		}

		$complete = true;

		foreach ( $items as $item ) {
			if ( $item['required'] && ! $item['done'] ) {
				$complete = false;
			}
		}

		return array(
			'items'    => $items,
			'complete' => $complete,
		);
	}

	/**
	 * Things that need the owner's attention, most serious first.
	 *
	 * @param array<string, mixed> $facts See facts() in OverviewScreen.
	 * @return list<array{level:string,key:string,count:int}>
	 */
	public static function problems( array $facts ): array {
		$now      = (int) ( $facts['now'] ?? time() );
		$problems = array();

		$orders = (int) ( $facts['order_problems'] ?? 0 );

		if ( $orders > 0 ) {
			$problems[] = self::problem( 'error', 'orders', $orders );
		}

		if ( ! empty( $facts['migration_attention'] ) ) {
			$problems[] = self::problem( 'error', 'migration' );
		}

		if ( array_key_exists( 'storage_writable', $facts ) && ! $facts['storage_writable'] ) {
			$problems[] = self::problem( 'error', 'storage' );
		}

		$no_release = (int) ( $facts['products_without_release'] ?? 0 );

		if ( $no_release > 0 ) {
			$problems[] = self::problem( 'warning', 'no_release', $no_release );
		}

		if ( ! empty( $facts['cron_disabled'] ) ) {
			$problems[] = self::problem( 'warning', 'cron_disabled' );
		} else {
			$last = isset( $facts['last_maintenance'] ) ? (int) $facts['last_maintenance'] : 0;
			$next = isset( $facts['next_maintenance'] ) ? (int) $facts['next_maintenance'] : 0;

			if ( $last > 0 && $now - $last > self::STALE_AFTER ) {
				$problems[] = self::problem( 'warning', 'job_stale' );
			} elseif ( 0 === $last && $next > 0 && $now - $next > 86400 ) {
				$problems[] = self::problem( 'warning', 'job_never' );
			}
		}

		return $problems;
	}

	/**
	 * One problem.
	 *
	 * @param string $level 'error' or 'warning'.
	 * @param string $key   What is wrong.
	 * @param int    $count A number for the message, if it has one.
	 * @return array{level:string,key:string,count:int}
	 */
	private static function problem( string $level, string $key, int $count = 0 ): array {
		return array(
			'level' => $level,
			'key'   => $key,
			'count' => $count,
		);
	}
}
