<?php
/**
 * Tests for syncing plans to WooCommerce.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Plans\PlanException;
use Devllo\WPLicenseIt\Plans\PlanResult;
use Devllo\WPLicenseIt\Plans\PlanSync;
use Devllo\WPLicenseIt\Tests\Support\FakeProductGateway;
use Devllo\WPLicenseIt\Tests\Support\InMemoryPlanRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Plans\PlanSync
 * @covers \Devllo\WPLicenseIt\Plans\PlanResult
 */
final class PlanSyncTest extends TestCase {

	private const SOFTWARE = 7;

	/**
	 * @var InMemoryPlanRepository
	 */
	private $repo;

	/**
	 * @var FakeProductGateway
	 */
	private $woo;

	/**
	 * @var PlanSync
	 */
	private $sync;

	protected function setUp(): void {
		$this->repo = new InMemoryPlanRepository();
		$this->woo  = new FakeProductGateway();
		$this->sync = new PlanSync( $this->repo, $this->woo );
	}

	private function plan( string $id, string $name = 'One site', string $price = '49', int $sites = 1, string $period = 'P1Y' ): Plan {
		return new Plan( $id, $name, $price, $sites, $period );
	}

	/**
	 * @return PlanResult[]
	 */
	private function save( Plan ...$plans ): array {
		return $this->sync->save( self::SOFTWARE, 'SEO Pro', $plans );
	}

	private function stored( string $id ): Plan {
		foreach ( $this->repo->all( self::SOFTWARE ) as $plan ) {
			if ( $plan->id === $id ) {
				return $plan;
			}
		}
		$this->fail( 'No such plan ' . $id );
	}

	public function test_a_new_plan_creates_a_draft_product(): void {
		$results = $this->save( $this->plan( 'aaaaaaaa' ) );

		$this->assertSame( PlanResult::CREATED, $results[0]->status );

		$plan = $this->stored( 'aaaaaaaa' );
		$this->assertSame( 500, $plan->woo_product_id );
		$this->assertSame( 4900, $plan->synced_minor );
		$this->assertSame( 'USD', $plan->synced_currency );

		$product = $this->woo->products[500];
		$this->assertSame( 'draft', $product['status'] );
		$this->assertSame( 'SEO Pro - One site', $product['title'] );
		$this->assertSame( 4900, $product['price'] );
		$this->assertSame( self::SOFTWARE, $product['software'] );
		$this->assertSame( 1, $product['sites'] );
		$this->assertSame( 'P1Y', $product['period'] );
	}

	public function test_each_plan_gets_its_own_product(): void {
		$this->save( $this->plan( 'aaaaaaaa' ), $this->plan( 'bbbbbbbb', 'Five sites', '99.50', 5 ), $this->plan( 'cccccccc', 'Unlimited', '199', 0, 'lifetime' ) );

		$this->assertCount( 3, $this->woo->products );
		$this->assertSame( 9950, $this->woo->products[$this->stored( 'bbbbbbbb' )->woo_product_id]['price'] );
		$this->assertSame( 0, $this->woo->products[$this->stored( 'cccccccc' )->woo_product_id]['sites'] );
	}

	public function test_saving_again_changes_nothing(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->calls = array();

		$results = $this->save( $this->stored( 'aaaaaaaa' ) );

		$this->assertSame( PlanResult::IN_SYNC, $results[0]->status );
		$this->assertCount( 0, $this->woo->calls );
	}

	public function test_a_price_changed_in_the_plan_is_sent_to_woocommerce(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );

		$plan        = $this->stored( 'aaaaaaaa' );
		$plan->price = '59';
		$results     = $this->save( $plan );

		$this->assertSame( PlanResult::UPDATED, $results[0]->status );
		$this->assertSame( 5900, $this->woo->products[500]['price'] );
		$this->assertSame( 5900, $this->stored( 'aaaaaaaa' )->synced_minor );
	}

	public function test_terms_changed_in_the_plan_are_sent_to_woocommerce(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );

		$plan         = $this->stored( 'aaaaaaaa' );
		$plan->sites  = 3;
		$plan->period = 'P2Y';
		$results      = $this->save( $plan );

		$this->assertSame( PlanResult::UPDATED, $results[0]->status );
		$this->assertSame( 3, $this->woo->products[500]['sites'] );
		$this->assertSame( 'P2Y', $this->woo->products[500]['period'] );
		$this->assertSame( 4900, $this->woo->products[500]['price'] );
	}

	public function test_terms_edited_in_woocommerce_are_put_back(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->products[500]['sites'] = 50;

		$this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->assertSame( 1, $this->woo->products[500]['sites'] );
	}

	public function test_a_price_edited_in_woocommerce_is_flagged_not_overwritten(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->edit_price_in_woo( 500, 3900 );
		$this->woo->calls = array();

		$results = $this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->assertSame( PlanResult::PRICE_CONFLICT, $results[0]->status );
		$this->assertTrue( $results[0]->needs_attention() );
		$this->assertSame( 3900, $this->woo->products[500]['price'] );
		$this->assertSame( 0, $this->woo->count( 'price' ) );

		$plan = $this->stored( 'aaaaaaaa' );
		$this->assertSame( 3900, $plan->conflict_minor );
		$this->assertSame( '49', $plan->price );
		$this->assertSame( 4900, $plan->synced_minor );
	}

	public function test_the_flag_stays_until_the_seller_decides(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->edit_price_in_woo( 500, 3900 );
		$this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$results = $this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->assertSame( PlanResult::PRICE_CONFLICT, $results[0]->status );
		$this->assertSame( 3900, $this->woo->products[500]['price'] );
	}

	public function test_accepting_the_woocommerce_price_updates_the_plan(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->edit_price_in_woo( 500, 3950 );
		$this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->sync->accept_woo_price( self::SOFTWARE, 'aaaaaaaa' );

		$plan = $this->stored( 'aaaaaaaa' );
		$this->assertSame( '39.50', $plan->price );
		$this->assertSame( 3950, $plan->synced_minor );
		$this->assertNull( $plan->conflict_minor );
		$this->assertSame( PlanResult::IN_SYNC, $this->sync->sync( self::SOFTWARE, 'SEO Pro' )[0]->status );
		$this->assertSame( 3950, $this->woo->products[500]['price'] );
	}

	public function test_restoring_the_plan_price_fixes_woocommerce(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->edit_price_in_woo( 500, 3900 );
		$this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->sync->restore_plan_price( self::SOFTWARE, 'aaaaaaaa' );

		$this->assertSame( 4900, $this->woo->products[500]['price'] );
		$this->assertNull( $this->stored( 'aaaaaaaa' )->conflict_minor );
		$this->assertSame( PlanResult::IN_SYNC, $this->sync->sync( self::SOFTWARE, 'SEO Pro' )[0]->status );
	}

	public function test_when_both_sides_changed_the_plan_wins(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->edit_price_in_woo( 500, 3900 );

		$plan        = $this->stored( 'aaaaaaaa' );
		$plan->price = '59';
		$results     = $this->save( $plan );

		$this->assertSame( PlanResult::UPDATED, $results[0]->status );
		$this->assertSame( 5900, $this->woo->products[500]['price'] );
		$this->assertNull( $this->stored( 'aaaaaaaa' )->conflict_minor );
	}

	public function test_accepting_when_woocommerce_has_no_price_is_refused(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->products[500]['price'] = null;
		$this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->expectException( PlanException::class );
		$this->sync->accept_woo_price( self::SOFTWARE, 'aaaaaaaa' );
	}

	public function test_a_deleted_product_is_reported_not_recreated(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		unset( $this->woo->products[500] );
		$this->woo->calls = array();

		$results = $this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->assertSame( PlanResult::MISSING, $results[0]->status );
		$this->assertSame( 0, $this->woo->count( 'create' ) );
	}

	public function test_a_trashed_product_counts_as_deleted(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->products[500]['status'] = 'trash';

		$this->assertSame( PlanResult::MISSING, $this->sync->sync( self::SOFTWARE, 'SEO Pro' )[0]->status );
	}

	public function test_forgetting_a_deleted_product_lets_the_next_sync_create_a_new_draft(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		unset( $this->woo->products[500] );

		$this->sync->forget_product( self::SOFTWARE, 'aaaaaaaa' );
		$results = $this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->assertSame( PlanResult::CREATED, $results[0]->status );
		$this->assertSame( 501, $this->stored( 'aaaaaaaa' )->woo_product_id );
	}

	public function test_removing_a_plan_puts_its_product_back_to_draft(): void {
		$this->save( $this->plan( 'aaaaaaaa' ), $this->plan( 'bbbbbbbb', 'Five sites', '99', 5 ) );
		$this->woo->products[501]['status'] = 'publish';

		$results = $this->save( $this->stored( 'aaaaaaaa' ) );

		$this->assertSame( 'draft', $this->woo->products[501]['status'] );
		$this->assertCount( 1, $this->repo->all( self::SOFTWARE ) );
		$this->assertSame( PlanResult::UPDATED, $results[0]->status );
		$this->assertCount( 2, $this->woo->products );
	}

	public function test_removing_a_plan_that_was_never_synced_is_quiet(): void {
		$this->repo->save( self::SOFTWARE, array( $this->plan( 'aaaaaaaa' ) ) );

		$results = $this->save();

		$this->assertSame( PlanResult::IN_SYNC, $results[0]->status );
		$this->assertCount( 0, $this->woo->calls );
	}

	public function test_a_failure_on_one_plan_does_not_stop_the_others(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->fail = true;

		$first         = $this->stored( 'aaaaaaaa' );
		$first->price  = '60';
		$second        = $this->plan( 'bbbbbbbb', 'Five sites', '99', 5 );
		$results       = $this->save( $first, $second );

		$this->assertSame( PlanResult::ERROR, $results[0]->status );
		$this->assertSame( PlanResult::ERROR, $results[1]->status );
		$this->assertSame( 'WooCommerce said no.', $results[0]->message );
		$this->assertTrue( $results[0]->needs_attention() );
		$this->assertSame( 0, $this->stored( 'bbbbbbbb' )->woo_product_id );

		$this->woo->fail = false;
		$results         = $this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->assertSame( PlanResult::UPDATED, $results[0]->status );
		$this->assertSame( PlanResult::CREATED, $results[1]->status );
	}

	public function test_publishing_needs_a_passed_test_purchase(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );

		try {
			$this->sync->publish( self::SOFTWARE, 'aaaaaaaa' );
			$this->fail( 'Expected an exception' );
		} catch ( PlanException $e ) {
			$this->assertStringContainsString( 'test purchase', $e->getMessage() );
		}

		$this->assertSame( 'draft', $this->woo->products[500]['status'] );
	}

	public function test_publishing_after_a_passed_test_purchase(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$plan            = $this->stored( 'aaaaaaaa' );
		$plan->tested_at = '2026-10-11 12:00:00';
		$this->repo->save( self::SOFTWARE, array( $plan ) );

		$this->sync->publish( self::SOFTWARE, 'aaaaaaaa' );

		$this->assertSame( 'publish', $this->woo->products[500]['status'] );
	}

	public function test_the_plan_price_is_pushed_when_the_store_currency_changes(): void {
		$this->save( $this->plan( 'aaaaaaaa' ) );
		$this->woo->currency = 'JPY';

		$results = $this->sync->sync( self::SOFTWARE, 'SEO Pro' );

		$this->assertSame( PlanResult::UPDATED, $results[0]->status );
		$this->assertSame( 49, $this->woo->products[500]['price'] );
		$this->assertSame( 'JPY', $this->stored( 'aaaaaaaa' )->synced_currency );
	}

	public function test_unknown_plan_ids_are_refused(): void {
		$this->expectException( PlanException::class );
		$this->sync->restore_plan_price( self::SOFTWARE, 'ffffffff' );
	}

	public function test_plans_survive_storage(): void {
		$plan                  = $this->plan( 'aaaaaaaa' );
		$plan->woo_product_id  = 9;
		$plan->conflict_minor  = 100;
		$plan->tested_at       = '2026-10-11 12:00:00';
		$this->repo->save( self::SOFTWARE, array( $plan ) );

		$this->assertSame( $plan->to_array(), $this->repo->all( self::SOFTWARE )[0]->to_array() );
	}

	public function test_damaged_stored_rows_are_skipped(): void {
		$this->assertNull( Plan::from_array( 'nonsense' ) );
		$this->assertNull( Plan::from_array( array( 'id' => 'aaaaaaaa' ) ) );
	}
}
