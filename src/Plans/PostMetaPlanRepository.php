<?php
/**
 * Plan storage in post meta.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * Keeps a software product's plans as one JSON value in the product's post meta.
 */
final class PostMetaPlanRepository implements PlanRepository {

	public const META_KEY = 'wplit_plans';

	public function all( int $software_id ): array {
		$raw  = get_post_meta( $software_id, self::META_KEY, true );
		$rows = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$plans = array();
		foreach ( $rows as $row ) {
			$plan = Plan::from_array( $row );
			if ( null !== $plan ) {
				$plans[] = $plan;
			}
		}

		return $plans;
	}

	public function save( int $software_id, array $plans ): void {
		$rows = array_map(
			static function ( Plan $plan ): array {
				return $plan->to_array();
			},
			array_values( $plans )
		);

		// update_post_meta removes slashes, so the JSON is slashed first to keep quotes in plan names intact.
		update_post_meta( $software_id, self::META_KEY, wp_slash( (string) wp_json_encode( $rows ) ) );
	}
}
