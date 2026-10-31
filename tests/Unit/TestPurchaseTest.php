<?php
/**
 * Tests for the test purchase.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';

use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Tests\Support\ConfigurableProductCatalog;
use Devllo\WPLicenseIt\Tests\Support\FakeOrderBuilder;
use Devllo\WPLicenseIt\Tests\Support\FakeProductGateway;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\TestPurchase\CheckResult;
use Devllo\WPLicenseIt\TestPurchase\OrderBuilder;
use Devllo\WPLicenseIt\TestPurchase\ReachabilityProbe;
use Devllo\WPLicenseIt\TestPurchase\TestPurchase;
use Devllo\WPLicenseIt\TestPurchase\TestReport;
use Devllo\WPLicenseIt\TestPurchase\WcOrderBuilder;
use Devllo\WPLicenseIt\WooCommerce\OrderReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\TestPurchase\TestPurchase
 * @covers \Devllo\WPLicenseIt\TestPurchase\TestReport
 * @covers \Devllo\WPLicenseIt\TestPurchase\WcOrderBuilder
 * @covers \Devllo\WPLicenseIt\DryRun\Sandbox
 */
final class TestPurchaseTest extends TestCase {

	private const SOFTWARE = 7;

	/**
	 * @var FakeProductGateway
	 */
	private $woo;

	/**
	 * @var ConfigurableProductCatalog
	 */
	private $catalog;

	/**
	 * @var array{ok:bool,message:string}
	 */
	private $probe_answer;

	/**
	 * @var string
	 */
	private $zip;

	protected function setUp(): void {
		$this->woo          = new FakeProductGateway();
		$this->catalog      = new ConfigurableProductCatalog();
		$this->probe_answer = array(
			'ok'      => true,
			'message' => 'The license API answers.',
		);

		$this->zip = tempnam( sys_get_temp_dir(), 'wplit' );
		$archive   = new \ZipArchive();
		$archive->open( $this->zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$archive->addFromString( 'seo-pro/seo-pro.php', "<?php\n/*\nPlugin Name: SEO Pro\nVersion: 1.4.2\nRequires at least: 6.2\nRequires PHP: 8.0\n*/\n" );
		$archive->close();
		$this->catalog->file = $this->zip;
	}

	protected function tearDown(): void {
		if ( is_file( $this->zip ) ) {
			unlink( $this->zip );
		}
	}

	private function plan( int $sites = 5, string $period = 'P1Y', int $woo_id = 500 ): Plan {
		return new Plan( 'aaaaaaaa', 'Plan', '99', $sites, $period, $woo_id, 9900, 'USD' );
	}

	/**
	 * Adds the WooCommerce product a plan points at.
	 *
	 * @param array<string,mixed> $change Fields to override.
	 */
	private function product( int $sites = 5, string $period = 'P1Y', array $change = array() ): void {
		$this->woo->products[500] = $change + array(
			'status'   => 'draft',
			'price'    => 9900,
			'software' => self::SOFTWARE,
			'sites'    => $sites,
			'period'   => $period,
		);
	}

	private function run_test( Plan $plan, ?OrderBuilder $builder = null ): TestReport {
		$probe = new class( $this->probe_answer ) implements ReachabilityProbe {
			/**
			 * @var array{ok:bool,message:string}
			 */
			private $answer;

			/**
			 * @param array{ok:bool,message:string} $answer Canned answer.
			 */
			public function __construct( array $answer ) {
				$this->answer = $answer;
			}

			public function probe(): array {
				return $this->answer;
			}
		};

		return ( new TestPurchase( $this->woo, $this->catalog, $builder ?? new FakeOrderBuilder( $this->woo ), $probe, new FixedClock( '2026-03-01 00:00:00' ) ) )->run( self::SOFTWARE, $plan );
	}

	/**
	 * @return string[] Statuses by check ID.
	 */
	private function statuses( TestReport $report ): array {
		$out = array();
		foreach ( $report->results as $result ) {
			$out[ $result->id ] = $result->status;
		}

		return $out;
	}

	public function test_a_correct_plan_passes_every_check(): void {
		$this->product();

		$report = $this->run_test( $this->plan() );

		$this->assertSame(
			array(
				'product'    => 'pass',
				'terms'      => 'pass',
				'package'    => 'pass',
				'purchase'   => 'pass',
				'activation' => 'pass',
				'download'   => 'pass',
				'reachable'  => 'pass',
			),
			$this->statuses( $report )
		);
		$this->assertTrue( $report->passed() );
		$this->assertFalse( $report->has_warnings() );
	}

	public function test_the_messages_say_what_was_found(): void {
		$this->product();

		$report = $this->run_test( $this->plan() );

		$this->assertStringContainsString( '5 sites for 1 year', $report->get( 'terms' )->message );
		$this->assertStringContainsString( 'expiring 2027-03-01', $report->get( 'purchase' )->message );
		$this->assertStringContainsString( 'SEO Pro 1.4.2', $report->get( 'package' )->message );
		$this->assertStringContainsString( 'the next site was refused', $report->get( 'activation' )->message );
		$this->assertStringContainsString( basename( $this->zip ), $report->get( 'download' )->message );
	}

	public function test_it_writes_nothing_to_woocommerce(): void {
		$this->product();

		$this->run_test( $this->plan() );
		$this->run_test( $this->plan() );

		$this->assertCount( 0, $this->woo->calls );
		$this->assertCount( 1, $this->woo->products );
		$this->assertSame( 'draft', $this->woo->products[500]['status'] );
	}

	public function test_a_lifetime_plan_never_expires(): void {
		$this->product( 5, 'lifetime' );

		$report = $this->run_test( $this->plan( 5, 'lifetime' ) );

		$this->assertTrue( $report->passed() );
		$this->assertStringContainsString( 'never expiring', $report->get( 'purchase' )->message );
	}

	public function test_an_unlimited_plan_skips_the_limit_and_says_so(): void {
		$this->product( 0 );

		$report = $this->run_test( $this->plan( 0 ) );

		$this->assertTrue( $report->passed() );
		$this->assertStringContainsString( 'the plan is unlimited', $report->get( 'activation' )->message );
	}

	public function test_a_very_large_plan_skips_the_limit_and_says_so(): void {
		$this->product( 50 );

		$report = $this->run_test( $this->plan( 50 ) );

		$this->assertTrue( $report->passed() );
		$this->assertStringContainsString( 'more than 10 sites', $report->get( 'activation' )->message );
	}

	public function test_a_one_site_plan_tries_the_limit(): void {
		$this->product( 1 );

		$report = $this->run_test( $this->plan( 1 ) );

		$this->assertStringContainsString( 'activated on 1 site, the next site was refused', $report->get( 'activation' )->message );
	}

	/**
	 * @dataProvider broken_products
	 */
	public function test_a_broken_woocommerce_product_fails_and_later_steps_are_skipped( ?array $product, int $woo_id, string $fragment ): void {
		if ( null !== $product ) {
			$this->woo->products[500] = $product;
		}

		$report = $this->run_test( $this->plan( 5, 'P1Y', $woo_id ) );

		$this->assertSame( CheckResult::FAIL, $report->get( 'product' )->status );
		$this->assertStringContainsString( $fragment, $report->get( 'product' )->message );
		$this->assertSame( CheckResult::SKIP, $report->get( 'terms' )->status );
		$this->assertSame( CheckResult::SKIP, $report->get( 'purchase' )->status );
		$this->assertSame( CheckResult::SKIP, $report->get( 'activation' )->status );
		$this->assertSame( CheckResult::SKIP, $report->get( 'download' )->status );
		$this->assertSame( CheckResult::PASS, $report->get( 'package' )->status );
		$this->assertFalse( $report->passed() );
	}

	/**
	 * @return array<string, array{array<string,mixed>|null,int,string}>
	 */
	public function broken_products(): array {
		$base = array(
			'status'   => 'draft',
			'price'    => 9900,
			'software' => 7,
			'sites'    => 5,
			'period'   => 'P1Y',
		);

		return array(
			'never created' => array( null, 0, 'no WooCommerce product yet' ),
			'deleted'       => array( null, 500, 'was deleted' ),
			'variable'      => array( $base + array( 'type' => 'variable' ), 500, 'variable product' ),
			'not linked'    => array( array( 'software' => 0 ) + $base, 500, 'not linked' ),
			'other product' => array( array( 'software' => 8 ) + $base, 500, 'different software' ),
		);
	}

	public function test_a_trashed_product_counts_as_deleted(): void {
		$this->product( 5, 'P1Y', array( 'status' => 'trash' ) );

		$report = $this->run_test( $this->plan() );

		$this->assertStringContainsString( 'was deleted', $report->get( 'product' )->message );
	}

	public function test_terms_edited_in_woocommerce_fail_with_both_sides_shown(): void {
		$this->product( 1, 'P6M' );

		$report = $this->run_test( $this->plan( 5, 'P1Y' ) );

		$this->assertSame( CheckResult::PASS, $report->get( 'product' )->status );
		$this->assertSame( CheckResult::FAIL, $report->get( 'terms' )->status );
		$this->assertStringContainsString( 'plan says 5 sites for 1 year', $report->get( 'terms' )->message );
		$this->assertStringContainsString( '1 site for 6 months', $report->get( 'terms' )->message );
		$this->assertSame( CheckResult::SKIP, $report->get( 'purchase' )->status );
	}

	public function test_an_unpublished_software_product_fails_the_package_check(): void {
		$this->product();
		$this->catalog->published = false;

		$report = $this->run_test( $this->plan() );

		$this->assertSame( CheckResult::FAIL, $report->get( 'package' )->status );
		$this->assertStringContainsString( 'not published', $report->get( 'package' )->message );
		$this->assertSame( CheckResult::PASS, $report->get( 'purchase' )->status );
		$this->assertSame( CheckResult::SKIP, $report->get( 'download' )->status );
		$this->assertFalse( $report->passed() );
	}

	public function test_a_missing_version_fails_the_package_check(): void {
		$this->product();
		$this->catalog->version = '';

		$this->assertStringContainsString( 'no version', $this->run_test( $this->plan() )->get( 'package' )->message );
	}

	public function test_no_zip_fails_the_package_check(): void {
		$this->product();
		$this->catalog->has_package = false;

		$this->assertStringContainsString( 'No zip', $this->run_test( $this->plan() )->get( 'package' )->message );
	}

	public function test_a_zip_missing_from_disk_fails_the_package_check(): void {
		$this->product();
		$this->catalog->file = null;

		$this->assertStringContainsString( 'missing from the server', $this->run_test( $this->plan() )->get( 'package' )->message );
	}

	public function test_a_stored_file_that_is_not_a_plugin_fails_the_package_check(): void {
		$this->product();
		file_put_contents( $this->zip, 'not a zip' );

		$result = $this->run_test( $this->plan() )->get( 'package' );

		$this->assertSame( CheckResult::FAIL, $result->status );
		$this->assertStringContainsString( 'not a usable plugin or theme', $result->message );
	}

	public function test_an_unreachable_api_only_warns(): void {
		$this->product();
		$this->probe_answer = array(
			'ok'      => false,
			'message' => 'Blocked.',
		);

		$report = $this->run_test( $this->plan() );

		$this->assertSame( CheckResult::WARN, $report->get( 'reachable' )->status );
		$this->assertSame( 'Blocked.', $report->get( 'reachable' )->message );
		$this->assertTrue( $report->has_warnings() );
		$this->assertTrue( $report->passed() );
	}

	public function test_an_invalid_period_fails_the_purchase(): void {
		$this->product( 5, 'bogus' );

		$report = $this->run_test( $this->plan( 5, 'bogus' ) );

		$this->assertSame( CheckResult::PASS, $report->get( 'terms' )->status );
		$this->assertSame( CheckResult::FAIL, $report->get( 'purchase' )->status );
		$this->assertStringContainsString( 'period', $report->get( 'purchase' )->message );
		$this->assertSame( CheckResult::SKIP, $report->get( 'activation' )->status );
	}

	public function test_a_builder_that_cannot_load_the_product_fails_the_terms(): void {
		$this->product();
		$builder = new class() implements OrderBuilder {
			public function build( int $woo_product_id, int $quantity ): \Devllo\WPLicenseIt\WooCommerce\OrderData {
				throw new \RuntimeException( 'The WooCommerce product could not be loaded.' );
			}
		};

		$result = $this->run_test( $this->plan(), $builder )->get( 'terms' );

		$this->assertSame( CheckResult::FAIL, $result->status );
		$this->assertStringContainsString( 'could not be loaded', $result->message );
	}

	public function test_the_report_never_passes_when_empty_or_with_a_skip(): void {
		$this->assertFalse( ( new TestReport( array() ) )->passed() );
		$this->assertFalse( ( new TestReport( array( new CheckResult( 'x', 'X', CheckResult::SKIP, '' ) ) ) )->passed() );
		$this->assertNull( ( new TestReport( array() ) )->get( 'x' ) );
	}

	public function test_the_real_order_builder_reads_terms_from_a_woocommerce_product(): void {
		$GLOBALS['wplit_test_products']      = array();
		$product                             = new \WC_Product();
		$product->id                         = 500;
		$product->name                       = 'SEO Pro - 5 sites';
		$product->meta                       = array(
			'_wplit_product_id'       => 7,
			'_wplit_activation_limit' => '5',
			'_wplit_period'           => 'P1Y',
		);
		$GLOBALS['wplit_test_products'][500] = $product;

		$order = ( new WcOrderBuilder( new OrderReader() ) )->build( 500, 1 );

		$this->assertCount( 1, $order->items );
		$this->assertSame( 7, $order->items[0]->wplit_product_id );
		$this->assertSame( 5, $order->items[0]->activation_limit );
		$this->assertSame( 'P1Y', $order->items[0]->period );
		$this->assertSame( 'SEO Pro - 5 sites', $order->items[0]->name );
	}

	public function test_the_real_order_builder_uses_the_default_for_unset_terms_and_refuses_a_missing_product(): void {
		$GLOBALS['wplit_test_products']      = array();
		$product                             = new \WC_Product();
		$product->id                         = 501;
		$product->meta                       = array( '_wplit_product_id' => 7 );
		$GLOBALS['wplit_test_products'][501] = $product;

		$order = ( new WcOrderBuilder( new OrderReader() ) )->build( 501, 2 );
		$this->assertSame( 2, $order->items[0]->quantity );
		$this->assertSame( 1, $order->items[0]->activation_limit );

		$this->expectException( \RuntimeException::class );
		( new WcOrderBuilder( new OrderReader() ) )->build( 999, 1 );
	}
}
