<?php
/**
 * Result of syncing one plan.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * What happened to one plan.
 */
final class PlanResult {

	public const CREATED        = 'created';
	public const UPDATED        = 'updated';
	public const IN_SYNC        = 'in_sync';
	public const PRICE_CONFLICT = 'price_conflict';
	public const MISSING        = 'missing';
	public const ERROR          = 'error';

	/**
	 * Constructor.
	 *
	 * @param string $plan_id Plan ID.
	 * @param string $name    Plan name.
	 * @param string $status  One of the constants above.
	 * @param string $message Short text for the seller.
	 */
	public function __construct(
		public string $plan_id,
		public string $name,
		public string $status,
		public string $message
	) {
	}

	/**
	 * Whether the seller has to do something.
	 */
	public function needs_attention(): bool {
		return in_array( $this->status, array( self::PRICE_CONFLICT, self::MISSING, self::ERROR ), true );
	}
}
