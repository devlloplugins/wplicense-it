<?php
/**
 * Keeps WooCommerce products in step with the plans.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

use Devllo\WPLicenseIt\WooCommerce\Money;

/**
 * The plugin owns the plans. Saving them creates one draft WooCommerce product per plan and keeps its price
 * and license terms current.
 *
 * Price rules:
 * - A price changed in the plan is pushed to WooCommerce.
 * - A price changed in WooCommerce is never pushed back or accepted silently. It is flagged, and the seller
 *   chooses: keep the plugin's price (restore) or take the WooCommerce price (accept).
 * - If both changed, the plan wins.
 */
final class PlanSync {

	/**
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * @var ProductGateway
	 */
	private $woo;

	/**
	 * Constructor.
	 *
	 * @param PlanRepository $plans Plan storage.
	 * @param ProductGateway $woo   WooCommerce.
	 */
	public function __construct( PlanRepository $plans, ProductGateway $woo ) {
		$this->plans = $plans;
		$this->woo   = $woo;
	}

	/**
	 * Saves new plans, retires the ones that were removed and syncs the rest.
	 *
	 * @param int    $software_id   License product ID.
	 * @param string $software_name License product name, used in WooCommerce product titles.
	 * @param Plan[] $plans         The new list, already checked with PlanInput.
	 * @return PlanResult[]
	 */
	public function save( int $software_id, string $software_name, array $plans ): array {
		$removed = PlanInput::removed( $this->plans->all( $software_id ), $plans );
		$this->plans->save( $software_id, $plans );

		$results = array();
		foreach ( $removed as $plan ) {
			$results[] = $this->retire( $plan );
		}

		return array_merge( $results, $this->sync( $software_id, $software_name ) );
	}

	/**
	 * Syncs every plan of a software product.
	 *
	 * @param int    $software_id   License product ID.
	 * @param string $software_name License product name.
	 * @return PlanResult[]
	 */
	public function sync( int $software_id, string $software_name ): array {
		$plans   = $this->plans->all( $software_id );
		$results = array();

		foreach ( $plans as $plan ) {
			try {
				$results[] = $this->sync_plan( $plan, $software_id, $software_name );
			} catch ( \Exception $e ) {
				$results[] = new PlanResult( $plan->id, $plan->name, PlanResult::ERROR, $e->getMessage() );
			}
		}

		$this->plans->save( $software_id, $plans );

		return $results;
	}

	/**
	 * Takes the WooCommerce price into the plan.
	 *
	 * @param int    $software_id License product ID.
	 * @param string $plan_id     Plan ID.
	 * @throws PlanException If there is nothing to accept.
	 */
	public function accept_woo_price( int $software_id, string $plan_id ): void {
		$plans    = $this->plans->all( $software_id );
		$plan     = $this->find_plan( $plans, $plan_id );
		$currency = $this->woo->currency();
		$remote   = $this->woo->find( $plan->woo_product_id );

		if ( null === $remote || $remote->is_trashed() || null === $remote->price_minor ) {
			throw new PlanException( 'WooCommerce has no price for this plan to accept. Restore the plan price instead.' );
		}

		$plan->price           = Money::from_minor( $remote->price_minor, $currency );
		$plan->synced_minor    = $remote->price_minor;
		$plan->synced_currency = $currency;
		$plan->conflict_minor  = null;

		$this->plans->save( $software_id, $plans );
	}

	/**
	 * Puts the plan's price back on the WooCommerce product.
	 *
	 * @param int    $software_id License product ID.
	 * @param string $plan_id     Plan ID.
	 * @throws PlanException If WooCommerce cannot be changed.
	 */
	public function restore_plan_price( int $software_id, string $plan_id ): void {
		$plans    = $this->plans->all( $software_id );
		$plan     = $this->find_plan( $plans, $plan_id );
		$currency = $this->woo->currency();
		$minor    = Money::to_minor( $plan->price, $currency );

		$remote = $this->woo->find( $plan->woo_product_id );
		if ( null === $remote || $remote->is_trashed() ) {
			throw new PlanException( __( 'The WooCommerce product for this plan is gone. Create it again first.', 'wplicense-it' ) );
		}

		$this->woo->update_price( $plan->woo_product_id, $minor );

		$plan->synced_minor    = $minor;
		$plan->synced_currency = $currency;
		$plan->conflict_minor  = null;

		$this->plans->save( $software_id, $plans );
	}

	/**
	 * Makes the product public in the shop. Only allowed while a passed test purchase still matches the plan.
	 *
	 * @param int    $software_id License product ID.
	 * @param string $plan_id     Plan ID.
	 * @param string $fingerprint Fingerprint of the plan as it is now (see TestPurchase\Fingerprint).
	 * @throws PlanException If the plan has not passed a test purchase, has changed since, or has no product.
	 */
	public function publish( int $software_id, string $plan_id, string $fingerprint ): void {
		$plans = $this->plans->all( $software_id );
		$plan  = $this->find_plan( $plans, $plan_id );

		if ( null === $plan->tested_hash || null === $plan->tested_at ) {
			throw new PlanException( __( 'Run a test purchase of this plan before publishing it.', 'wplicense-it' ) );
		}

		if ( ! hash_equals( $plan->tested_hash, $fingerprint ) ) {
			throw new PlanException( __( 'This plan, its product or its zip changed since the test purchase passed. Run the test again before publishing.', 'wplicense-it' ) );
		}

		$remote = $this->woo->find( $plan->woo_product_id );
		if ( null === $remote || $remote->is_trashed() ) {
			throw new PlanException( __( 'The WooCommerce product for this plan is gone. Create it again first.', 'wplicense-it' ) );
		}

		$this->woo->set_status( $plan->woo_product_id, 'publish' );
	}

	/**
	 * Remembers the outcome of a test purchase. A pass is stored with the fingerprint of what was tested, and a
	 * failure clears any earlier pass, so a plan that stopped working cannot be published on an old result.
	 *
	 * @param int    $software_id License product ID.
	 * @param string $plan_id     Plan ID.
	 * @param bool   $passed      Whether the test purchase passed.
	 * @param string $fingerprint Fingerprint of what was tested.
	 * @param string $when        Time of the test (UTC, Y-m-d H:i:s).
	 */
	public function record_test( int $software_id, string $plan_id, bool $passed, string $fingerprint, string $when ): void {
		$plans = $this->plans->all( $software_id );
		$plan  = $this->find_plan( $plans, $plan_id );

		$plan->tested_at   = $passed ? $when : null;
		$plan->tested_hash = $passed ? $fingerprint : null;

		$this->plans->save( $software_id, $plans );
	}

	/**
	 * Forgets a WooCommerce product that was deleted, so the next sync creates a new draft.
	 *
	 * @param int    $software_id License product ID.
	 * @param string $plan_id     Plan ID.
	 */
	public function forget_product( int $software_id, string $plan_id ): void {
		$plans = $this->plans->all( $software_id );
		$plan  = $this->find_plan( $plans, $plan_id );

		$plan->woo_product_id  = 0;
		$plan->synced_minor    = null;
		$plan->synced_currency = '';
		$plan->conflict_minor  = null;
		$plan->tested_at       = null;
		$plan->tested_hash     = null;

		$this->plans->save( $software_id, $plans );
	}

	/**
	 * Syncs one plan.
	 *
	 * @param Plan   $plan          Plan, updated in place.
	 * @param int    $software_id   License product ID.
	 * @param string $software_name License product name.
	 * @throws PlanException When WooCommerce refuses.
	 */
	private function sync_plan( Plan $plan, int $software_id, string $software_name ): PlanResult {
		$currency = $this->woo->currency();
		$minor    = Money::to_minor( $plan->price, $currency );

		if ( 0 === $plan->woo_product_id ) {
			$plan->woo_product_id  = $this->woo->create( $plan->id, $software_name . ' - ' . $plan->name, $minor, $software_id, $plan->sites, $plan->period );
			$plan->synced_minor    = $minor;
			$plan->synced_currency = $currency;
			$plan->conflict_minor  = null;

			return new PlanResult( $plan->id, $plan->name, PlanResult::CREATED, __( 'Created as a draft product in WooCommerce.', 'wplicense-it' ) );
		}

		$remote = $this->woo->find( $plan->woo_product_id );
		if ( null === $remote || $remote->is_trashed() ) {
			return new PlanResult( $plan->id, $plan->name, PlanResult::MISSING, __( 'The WooCommerce product was deleted. Create it again to sell this plan.', 'wplicense-it' ) );
		}

		$changed = false;

		if ( $remote->software_id !== $software_id || $remote->sites !== (string) $plan->sites || $remote->period !== $plan->period ) {
			$this->woo->update_terms( $plan->woo_product_id, $software_id, $plan->sites, $plan->period );
			$changed = true;
		}

		$plan_changed = $minor !== $plan->synced_minor || $currency !== $plan->synced_currency;

		if ( $plan_changed ) {
			if ( $remote->price_minor !== $minor ) {
				$this->woo->update_price( $plan->woo_product_id, $minor );
			}
			$plan->synced_minor    = $minor;
			$plan->synced_currency = $currency;
			$plan->conflict_minor  = null;

			return new PlanResult( $plan->id, $plan->name, PlanResult::UPDATED, __( 'Price and terms sent to WooCommerce.', 'wplicense-it' ) );
		}

		if ( $remote->price_minor !== $plan->synced_minor ) {
			$plan->conflict_minor = $remote->price_minor;

			return new PlanResult( $plan->id, $plan->name, PlanResult::PRICE_CONFLICT, __( 'The price in WooCommerce was changed. Choose which price to keep.', 'wplicense-it' ) );
		}

		$plan->conflict_minor = null;

		return $changed
			? new PlanResult( $plan->id, $plan->name, PlanResult::UPDATED, __( 'License terms sent to WooCommerce.', 'wplicense-it' ) )
			: new PlanResult( $plan->id, $plan->name, PlanResult::IN_SYNC, 'In sync.' );
	}

	/**
	 * Takes a removed plan off sale. The product is kept, so old orders still point at something.
	 *
	 * @param Plan $plan Removed plan.
	 */
	private function retire( Plan $plan ): PlanResult {
		if ( 0 === $plan->woo_product_id ) {
			return new PlanResult( $plan->id, $plan->name, PlanResult::IN_SYNC, 'Removed.' );
		}

		try {
			$remote = $this->woo->find( $plan->woo_product_id );
			if ( null !== $remote && ! $remote->is_trashed() && 'draft' !== $remote->status ) {
				$this->woo->set_status( $plan->woo_product_id, 'draft' );
			}
		} catch ( \Exception $e ) {
			return new PlanResult( $plan->id, $plan->name, PlanResult::ERROR, __( 'The plan was removed, but its WooCommerce product could not be taken off sale:', 'wplicense-it' ) . ' ' . $e->getMessage() );
		}

		return new PlanResult( $plan->id, $plan->name, PlanResult::UPDATED, 'Removed. Its WooCommerce product was set back to draft, so it can no longer be bought.' );
	}

	/**
	 * Finds a plan by ID.
	 *
	 * @param Plan[] $plans   Plans.
	 * @param string $plan_id Plan ID.
	 * @throws PlanException If there is no such plan.
	 */
	private function find_plan( array $plans, string $plan_id ): Plan {
		foreach ( $plans as $plan ) {
			if ( $plan->id === $plan_id ) {
				return $plan;
			}
		}

		throw new PlanException( __( 'That plan no longer exists.', 'wplicense-it' ) );
	}
}
