<?php
/**
 * Tests for the WooCommerce glue, run against stand-ins for WooCommerce's classes.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseOrderRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use Devllo\WPLicenseIt\WooCommerce\Money;
use Devllo\WPLicenseIt\WooCommerce\OrderProcessor;
use Devllo\WPLicenseIt\WooCommerce\OrderReader;
use Devllo\WPLicenseIt\WooCommerce\ProductFields;
use Devllo\WPLicenseIt\WooCommerce\WooAdapter;
use Devllo\WPLicenseIt\WooCommerce\WooItemLicenseStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/wp-transient-stubs.php';
require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';

/**
 * @covers \Devllo\WPLicenseIt\WooCommerce\WooAdapter
 * @covers \Devllo\WPLicenseIt\WooCommerce\OrderReader
 * @covers \Devllo\WPLicenseIt\WooCommerce\WooItemLicenseStore
 * @covers \Devllo\WPLicenseIt\WooCommerce\Money
 * @covers \Devllo\WPLicenseIt\WooCommerce\ProductFields
 */
final class WooAdapterTest extends TestCase {

	private LicenseService $service;
	private WooAdapter $adapter;
	private \WC_Order $order;
	private \WC_Order_Item_Product $item;
	private \WC_Product $product;

	protected function setUp(): void {
		$GLOBALS['wplit_test_orders']    = array();
		$GLOBALS['wplit_test_products']  = array();
		$GLOBALS['wplit_test_options']   = array();
		$GLOBALS['wplit_test_post_meta'] = array( 7 => array( 'wplit_period' => 'P1Y' ) );
		$store                           = &wplit_test_transients();
		$store                           = array();
		\WC_Order_Factory::$items        = array();

		$clock         = new FixedClock( '2026-03-01 00:00:00' );
		$this->service = new LicenseService( new InMemoryLicenseRepository(), new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), $clock );
		$reader        = new OrderReader();
		$processor     = new OrderProcessor( $this->service, new InMemoryLicenseOrderRepository(), new WooItemLicenseStore(), $clock );
		$this->adapter = new WooAdapter( $processor, $reader );

		// A WooCommerce product linked to license product 7, selling 2 sites per license.
		$this->product                       = new \WC_Product();
		$this->product->meta                 = array(
			OrderReader::META_PRODUCT => '7',
			OrderReader::META_LIMIT   => '2',
		);
		$GLOBALS['wplit_test_products'][55] = $this->product;

		$this->item            = new \WC_Order_Item_Product();
		$this->item->id        = 10;
		$this->item->product   = $this->product;
		\WC_Order_Factory::$items[10] = $this->item;

		$this->order                          = new \WC_Order();
		$this->order->items                   = array( 10 => $this->item );
		$GLOBALS['wplit_test_orders'][100]    = $this->order;
	}

	private function complete(): void {
		$this->adapter->on_status_changed( 100, 'processing', 'completed', $this->order );
	}

	public function test_completing_an_order_issues_a_license_from_the_product_settings(): void {
		$this->complete();

		$ids = WooItemLicenseStore::ids( $this->item );
		$this->assertCount( 1, $ids );

		$license = $this->service->find( $ids[0] );
		$this->assertSame( 7, $license->product_id );
		$this->assertSame( 5, $license->user_id );
		$this->assertSame( 'buyer@example.com', $license->email );
		$this->assertSame( 2, $license->activation_limit ); // From the WooCommerce product.
		$this->assertSame( '2027-03-01', $license->expires_at->format( 'Y-m-d' ) ); // Period inherited from the license product.
		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'issued 1 license', $this->order->notes[0] );
	}

	public function test_completing_twice_does_not_issue_twice(): void {
		$this->complete();
		$this->complete();

		$this->assertCount( 1, WooItemLicenseStore::ids( $this->item ) );
		$this->assertCount( 1, $this->order->notes );
	}

	public function test_a_snapshot_on_the_order_line_wins_over_later_product_changes(): void {
		$this->item->meta = array(
			OrderReader::META_PRODUCT => '7',
			OrderReader::META_LIMIT   => '5',
			OrderReader::META_PERIOD  => 'P6M',
		);
		$this->product->meta[ OrderReader::META_LIMIT ] = '1'; // The shop owner changes the product afterwards.

		$this->complete();

		$license = $this->service->find( WooItemLicenseStore::ids( $this->item )[0] );
		$this->assertSame( 5, $license->activation_limit );
		$this->assertSame( '2026-09-01', $license->expires_at->format( 'Y-m-d' ) );
	}

	public function test_a_variation_overrides_the_parent_product(): void {
		$variation                         = new \WC_Product();
		$variation->id                     = 56;
		$variation->meta                   = array( OrderReader::META_LIMIT => '0', OrderReader::META_PERIOD => 'lifetime' );
		$GLOBALS['wplit_test_products'][56] = $variation;
		$this->item->variation_id          = 56;
		$this->item->product               = $variation;

		$this->complete();

		$license = $this->service->find( WooItemLicenseStore::ids( $this->item )[0] );
		$this->assertSame( 0, $license->activation_limit );
		$this->assertNull( $license->expires_at );
	}

	public function test_products_that_are_not_linked_are_left_alone(): void {
		$this->product->meta = array();

		$this->complete();

		$this->assertSame( array(), WooItemLicenseStore::ids( $this->item ) );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_issuing_on_processing_is_opt_in(): void {
		$this->adapter->on_status_changed( 100, 'pending', 'processing', $this->order );
		$this->assertSame( array(), WooItemLicenseStore::ids( $this->item ));

		$GLOBALS['wplit_test_options'][ WooAdapter::OPTION_ISSUE_ON ] = 'processing';
		$this->adapter->on_status_changed( 100, 'pending', 'processing', $this->order );
		$this->assertCount( 1, WooItemLicenseStore::ids( $this->item ) );

		// Completing it afterwards does not issue a second license.
		$store = &wplit_test_transients();
		$store = array();
		$this->complete();
		$this->assertCount( 1, WooItemLicenseStore::ids( $this->item ) );
	}

	public function test_refunding_and_cancelling_end_the_license(): void {
		$this->complete();
		$id = WooItemLicenseStore::ids( $this->item )[0];

		$this->adapter->on_status_changed( 100, 'completed', 'refunded', $this->order );
		$this->assertSame( Status::REFUNDED, $this->service->find( $id )->status );
		$this->assertStringContainsString( 'ended 1 license', $this->order->notes[1] );

		// A cancellation does not overwrite the refund.
		$this->adapter->on_status_changed( 100, 'refunded', 'cancelled', $this->order );
		$this->assertSame( Status::REFUNDED, $this->service->find( $id )->status );
	}

	public function test_a_partial_refund_ends_one_of_two_licenses(): void {
		$this->item->quantity = 2;
		$this->complete();
		$ids = WooItemLicenseStore::ids( $this->item );
		$this->assertCount( 2, $ids );

		$this->order->refunded[10] = 1;
		$this->adapter->on_refunded( 100, 1 );

		$this->assertSame( Status::ACTIVE, $this->service->find( $ids[0] )->status );
		$this->assertSame( Status::REFUNDED, $this->service->find( $ids[1] )->status );
	}

	public function test_a_failure_is_written_to_the_order_and_does_not_throw(): void {
		$this->order->email = '';

		$this->complete();

		$this->assertCount( 1, $this->order->notes );
		$this->assertStringContainsString( 'could not issue', $this->order->notes[0] );
	}

	public function test_the_order_data_is_read_correctly(): void {
		$data = ( new OrderReader() )->read( $this->order );

		$this->assertSame( 100, $data->id );
		$this->assertSame( 5, $data->user_id );
		$this->assertSame( 2999, $data->total_minor );
		$this->assertSame( 7, $data->items[0]->wplit_product_id );
		$this->assertSame( 2, $data->items[0]->activation_limit );
		$this->assertSame( 'P1Y', $data->items[0]->period );
	}

	public function test_money_uses_each_currencys_decimals(): void {
		$this->assertSame( 2999, Money::to_minor( '29.99', 'USD' ) );
		$this->assertSame( 2999, Money::to_minor( 2999, 'jpy' ) );
		$this->assertSame( 12345, Money::to_minor( '12.345', 'KWD' ) );
		$this->assertSame( 0, Money::to_minor( '', 'USD' ) );
	}

	public function test_product_field_values_are_cleaned(): void {
		$this->assertSame( '', ProductFields::clean_limit( '' ) );
		$this->assertSame( '0', ProductFields::clean_limit( '0' ) );
		$this->assertSame( '5', ProductFields::clean_limit( ' 5 ' ) );
		$this->assertSame( '', ProductFields::clean_limit( '-1' ) );
		$this->assertSame( '', ProductFields::clean_limit( '1.5' ) );
		$this->assertSame( '', ProductFields::clean_limit( 'abc' ) );

		$this->assertSame( 'P1Y', ProductFields::clean_period( 'P1Y' ) );
		$this->assertSame( 'lifetime', ProductFields::clean_period( 'lifetime' ) );
		$this->assertSame( '', ProductFields::clean_period( 'P99Y' ) );
		$this->assertSame( '', ProductFields::clean_period( '' ) );
	}
}
