<?php
/**
 * Plan storage.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * Where the plans of a software product are kept.
 */
interface PlanRepository {

	/**
	 * The plans of a software product, in display order.
	 *
	 * @param int $software_id Post ID of the wplit_product.
	 * @return Plan[]
	 */
	public function all( int $software_id ): array;

	/**
	 * Replaces the plans of a software product.
	 *
	 * @param int    $software_id Post ID of the wplit_product.
	 * @param Plan[] $plans       Plans, in display order.
	 */
	public function save( int $software_id, array $plans ): void;
}
