<?php
/**
 * Fingerprint of what a test purchase tested.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

use Devllo\WPLicenseIt\Plans\Plan;

/**
 * A hash of the things the test purchase depends on: the software, the plan's WooCommerce product, its sites and
 * period, and the stored zip file. If any of them changes the hash changes, and an earlier pass stops counting.
 * The price is left out on purpose: the test does not look at it.
 */
final class Fingerprint {

	/**
	 * Computes the fingerprint.
	 *
	 * @param int         $software_id  License product ID.
	 * @param Plan        $plan         The plan.
	 * @param string|null $package_file Path of the stored zip, null if there is none. Every upload gets a new path.
	 */
	public static function of( int $software_id, Plan $plan, ?string $package_file ): string {
		return hash(
			'sha256',
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress dependency in this class.
				array(
					'software' => $software_id,
					'product'  => $plan->woo_product_id,
					'sites'    => $plan->sites,
					'period'   => $plan->period,
					'package'  => $package_file ?? '',
				)
			)
		);
	}

	/**
	 * Whether a plan has a passed test that still matches.
	 *
	 * @param Plan   $plan        The plan.
	 * @param string $fingerprint The plan's fingerprint now.
	 */
	public static function is_current( Plan $plan, string $fingerprint ): bool {
		return null !== $plan->tested_at && null !== $plan->tested_hash && hash_equals( $plan->tested_hash, $fingerprint );
	}
}
