<?php
/**
 * Tests for the WooCommerce plan gateway, against a stand-in for the WooCommerce product classes.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';

use Devllo\WPLicenseIt\Plans\PlanException;
use Devllo\WPLicenseIt\Plans\PlanResult;
use Devllo\WPLicenseIt\Plans\PlanSync;
use Devllo\WPLicenseIt\Plans\WcProductGateway;
use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Tests\Support\InMemoryPlanRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Plans\WcProductGateway
 */
final class WcProductGatewayTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wplit_test_products'] = array();
		$GLOBALS['wplit_test_currency'] = 'USD';
	}

	public function test_creates_a_draft_virtual_simple_product_with_the_license_settings(): void {
		$id = ( new WcProductGateway() )->create( 'aaaaaaaa', 'SEO Pro - 5 sites', 9950, 7, 5, 'P1Y' );

		$product = $GLOBALS['wplit_test_products'][ $id ];
		$this->assertSame( 'SEO Pro - 5 sites', $product->name );
		$this->assertSame( 'draft', $product->status );
		$this->assertTrue( $product->virtual );
		$this->assertSame( '99.50', $product->regular_price );
		$this->assertSame( 7, $product->meta['_wplit_product_id'] );
		$this->assertSame( '5', $product->meta['_wplit_activation_limit'] );
		$this->assertSame( 'P1Y', $product->meta['_wplit_period'] );
		$this->assertSame( 'aaaaaaaa', $product->meta['_wplit_plan_id'] );
	}

	public function test_unlimited_sites_are_stored_as_zero(): void {
		$id = ( new WcProductGateway() )->create( 'aaaaaaaa', 'X', 100, 7, 0, 'lifetime' );

		$this->assertSame( '0', $GLOBALS['wplit_test_products'][ $id ]->meta['_wplit_activation_limit'] );
	}

	public function test_reads_a_product_back(): void {
		$gateway = new WcProductGateway();
		$id      = $gateway->create( 'aaaaaaaa', 'X', 4950, 7, 3, 'P6M' );

		$remote = $gateway->find( $id );

		$this->assertSame( 4950, $remote->price_minor );
		$this->assertSame( 'draft', $remote->status );
		$this->assertSame( 7, $remote->software_id );
		$this->assertSame( '3', $remote->sites );
		$this->assertSame( 'P6M', $remote->period );
	}

	public function test_a_product_without_a_price_reads_as_null(): void {
		$gateway = new WcProductGateway();
		$id      = $gateway->create( 'aaaaaaaa', 'X', 100, 7, 1, 'P1Y' );
		$GLOBALS['wplit_test_products'][ $id ]->regular_price = '';

		$this->assertNull( $gateway->find( $id )->price_minor );
	}

	public function test_a_missing_product_is_null(): void {
		$this->assertNull( ( new WcProductGateway() )->find( 12345 ) );
		$this->assertNull( ( new WcProductGateway() )->find( 0 ) );
	}

	public function test_updates(): void {
		$gateway = new WcProductGateway();
		$id      = $gateway->create( 'aaaaaaaa', 'X', 100, 7, 1, 'P1Y' );

		$gateway->update_price( $id, 5999 );
		$gateway->update_terms( $id, 8, 2, 'P2Y' );
		$gateway->set_status( $id, 'publish' );

		$product = $GLOBALS['wplit_test_products'][ $id ];
		$this->assertSame( '59.99', $product->regular_price );
		$this->assertSame( 8, $product->meta['_wplit_product_id'] );
		$this->assertSame( '2', $product->meta['_wplit_activation_limit'] );
		$this->assertSame( 'P2Y', $product->meta['_wplit_period'] );
		$this->assertSame( 'publish', $product->status );
	}

	public function test_only_draft_and_publish_are_allowed(): void {
		$gateway = new WcProductGateway();
		$id      = $gateway->create( 'aaaaaaaa', 'X', 100, 7, 1, 'P1Y' );

		$this->expectException( PlanException::class );
		$gateway->set_status( $id, 'trash' );
	}

	public function test_changing_a_missing_product_is_an_error(): void {
		$this->expectException( PlanException::class );
		( new WcProductGateway() )->update_price( 999, 100 );
	}

	public function test_woocommerce_exceptions_become_plan_exceptions_with_the_reason(): void {
		$gateway = new WcProductGateway();
		$id      = $gateway->create( 'aaaaaaaa', 'X', 100, 7, 1, 'P1Y' );
		$GLOBALS['wplit_test_products'][ $id ]->fail_on_save = true;

		try {
			$gateway->update_price( $id, 200 );
			$this->fail( 'Expected an exception' );
		} catch ( PlanException $e ) {
			$this->assertStringContainsString( 'duplicated SKU', $e->getMessage() );
		}
	}

	public function test_prices_follow_the_store_currency(): void {
		$GLOBALS['wplit_test_currency'] = 'JPY';
		$gateway                        = new WcProductGateway();
		$id                             = $gateway->create( 'aaaaaaaa', 'X', 4900, 7, 1, 'P1Y' );

		$this->assertSame( '4900', $GLOBALS['wplit_test_products'][ $id ]->regular_price );
		$this->assertSame( 4900, $gateway->find( $id )->price_minor );
	}

	public function test_the_whole_sync_runs_against_the_gateway(): void {
		$repo = new InMemoryPlanRepository();
		$sync = new PlanSync( $repo, new WcProductGateway() );

		$results = $sync->save( 7, 'SEO Pro', array( new Plan( 'aaaaaaaa', 'One site', '49', 1, 'P1Y' ) ) );
		$this->assertSame( PlanResult::CREATED, $results[0]->status );

		$id = $repo->all( 7 )[0]->woo_product_id;
		$GLOBALS['wplit_test_products'][ $id ]->regular_price = '39.00';

		$this->assertSame( PlanResult::PRICE_CONFLICT, $sync->sync( 7, 'SEO Pro' )[0]->status );
		$sync->accept_woo_price( 7, 'aaaaaaaa' );
		$this->assertSame( '39.00', $repo->all( 7 )[0]->price );
		$this->assertSame( PlanResult::IN_SYNC, $sync->sync( 7, 'SEO Pro' )[0]->status );
	}
}
