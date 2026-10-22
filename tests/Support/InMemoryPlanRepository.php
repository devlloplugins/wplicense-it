<?php
/**
 * In-memory plan storage for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Plans\PlanRepository;

/**
 * Keeps plans in an array, as copies, the way a database would.
 */
final class InMemoryPlanRepository implements PlanRepository {

	/**
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private $rows = array();

	public function all( int $software_id ): array {
		$plans = array();
		foreach ( $this->rows[ $software_id ] ?? array() as $row ) {
			$plan = Plan::from_array( $row );
			if ( null !== $plan ) {
				$plans[] = $plan;
			}
		}

		return $plans;
	}

	public function save( int $software_id, array $plans ): void {
		$this->rows[ $software_id ] = array_map(
			static function ( Plan $plan ): array {
				return $plan->to_array();
			},
			array_values( $plans )
		);
	}
}
