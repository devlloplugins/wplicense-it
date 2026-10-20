<?php
/**
 * Calls to the old 1.x API addresses, counted per day and product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Keeps how many calls the 1.x API addresses got in the last 30 days, per product, and when the last one came. The site owner
 * reads it to know when no plugin or theme in the field uses those addresses any more, so they can be switched off.
 *
 * Pure: the state is an array that the caller loads and saves (the wplit_legacy_api_usage option). Two calls at the same
 * moment can both read the same state, so a count can be one or two short now and then; the dates are exact enough.
 */
final class LegacyUsage {

	public const OPTION = 'wplit_legacy_api_usage';
	public const DAYS   = 30;

	/**
	 * Counts one call.
	 *
	 * @param array<string, mixed> $state      Saved state.
	 * @param int                  $product_id Product the call named, 0 when it named none.
	 * @param int                  $now        Unix time.
	 * @return array{days: array<string, array<int, int>>, last: int}
	 */
	public static function record( array $state, int $product_id, int $now ): array {
		$days = self::days( $state, $now );
		$day  = gmdate( 'Y-m-d', $now );

		$days[ $day ][ $product_id ] = ( $days[ $day ][ $product_id ] ?? 0 ) + 1;

		$last = isset( $state['last'] ) && is_int( $state['last'] ) ? max( $state['last'], $now ) : $now;

		return array(
			'days' => $days,
			'last' => $last,
		);
	}

	/**
	 * What the last 30 days add up to.
	 *
	 * @param array<string, mixed> $state Saved state.
	 * @param int                  $now   Unix time.
	 * @return array{calls: int, products: array<int, int>, last: int|null}
	 */
	public static function summary( array $state, int $now ): array {
		$calls    = 0;
		$products = array();

		foreach ( self::days( $state, $now ) as $counts ) {
			foreach ( $counts as $product_id => $count ) {
				$calls                  += $count;
				$products[ $product_id ] = ( $products[ $product_id ] ?? 0 ) + $count;
			}
		}

		arsort( $products );
		$last = isset( $state['last'] ) && is_int( $state['last'] ) && $calls > 0 ? $state['last'] : null;

		return array(
			'calls'    => $calls,
			'products' => $products,
			'last'     => $last,
		);
	}

	/**
	 * The days kept, without the ones older than 30 days or anything malformed.
	 *
	 * @param array<string, mixed> $state Saved state.
	 * @param int                  $now   Unix time.
	 * @return array<string, array<int, int>>
	 */
	private static function days( array $state, int $now ): array {
		$oldest = gmdate( 'Y-m-d', $now - ( self::DAYS - 1 ) * 86400 );
		$days   = array();

		foreach ( is_array( $state['days'] ?? null ) ? $state['days'] : array() as $day => $counts ) {
			if ( ! is_string( $day ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $day < $oldest || ! is_array( $counts ) ) {
				continue;
			}

			foreach ( $counts as $product_id => $count ) {
				if ( is_int( $count ) && $count > 0 ) {
					$days[ $day ][ (int) $product_id ] = $count;
				}
			}
		}

		return $days;
	}
}
