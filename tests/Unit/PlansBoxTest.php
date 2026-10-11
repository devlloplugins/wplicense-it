<?php
/**
 * Tests for drawing the Plans box.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Admin\PlansBox;
use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\TestPurchase\CheckResult;
use Devllo\WPLicenseIt\TestPurchase\Fingerprint;
use Devllo\WPLicenseIt\TestPurchase\ReachabilityProbe;
use Devllo\WPLicenseIt\TestPurchase\TestPurchase;
use Devllo\WPLicenseIt\TestPurchase\TestReport;
use Devllo\WPLicenseIt\Tests\Support\ConfigurableProductCatalog;
use Devllo\WPLicenseIt\Tests\Support\FakeOrderBuilder;
use Devllo\WPLicenseIt\Tests\Support\FakeProductGateway;
use Devllo\WPLicenseIt\Tests\Support\InMemoryPlanRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Admin\PlansBox
 */
final class PlansBoxTest extends TestCase {

	/**
	 * @var InMemoryPlanRepository
	 */
	private $plans;

	/**
	 * @var FakeProductGateway
	 */
	private $woo;

	/**
	 * @var ConfigurableProductCatalog
	 */
	private $catalog;

	protected function setUp(): void {
		$this->plans   = new InMemoryPlanRepository();
		$this->woo     = new FakeProductGateway();
		$this->catalog = new ConfigurableProductCatalog();

		$store = &wplit_test_transients();
		$store = array();
	}

	private function product( string $status = 'draft', int $price = 9900 ): void {
		$this->woo->products[500] = array(
			'status'   => $status,
			'price'    => $price,
			'software' => 7,
			'sites'    => 5,
			'period'   => 'P1Y',
		);
	}

	private function plan( ?string $hash = null, ?string $tested_at = null, ?int $conflict = null, string $name = 'Five sites' ): Plan {
		return new Plan( 'aaaaaaaa', $name, '99', 5, 'P1Y', 500, 9900, 'USD', $conflict, $tested_at, $hash );
	}

	private function draw(): string {
		$probe = new class() implements ReachabilityProbe {
			public function probe(): array {
				return array(
					'ok'      => true,
					'message' => '',
				);
			}
		};

		$box  = new PlansBox( $this->plans, $this->woo, new TestPurchase( $this->woo, $this->catalog, new FakeOrderBuilder( $this->woo ), $probe ), $this->catalog );
		$post = new \WP_Post();
		$post->ID = 7;

		ob_start();
		$box->render( $post );

		return (string) ob_get_clean();
	}

	private function queue_report( bool $passed, array $rows ): void {
		set_transient( 'wplit_plans_test_1_7', array( 'plan' => 'Five sites', 'passed' => $passed, 'rows' => $rows ), 300 );
	}

	public function test_an_untested_draft_plan_offers_a_test_and_no_publish(): void {
		$this->product();
		$this->plans->save( 7, array( $this->plan() ) );

		$html = $this->draw();

		$this->assertStringContainsString( 'Test this plan', $html );
		$this->assertStringContainsString( 'do=test&amp;post=7&amp;plan=aaaaaaaa', $html );
		$this->assertStringContainsString( 'Not for sale yet', $html );
		$this->assertStringNotContainsString( 'Publish in the shop', $html );
	}

	public function test_a_plan_with_a_current_pass_can_be_published(): void {
		$this->product();
		$plan = $this->plan();
		$plan->tested_at   = '2026-10-11 12:00:00';
		$plan->tested_hash = Fingerprint::of( 7, $plan, null );
		$this->plans->save( 7, array( $plan ) );

		$html = $this->draw();

		$this->assertStringContainsString( 'The test passed.', $html );
		$this->assertStringContainsString( 'do=publish&amp;post=7&amp;plan=aaaaaaaa', $html );
	}

	public function test_a_stale_pass_cannot_be_published(): void {
		$this->product();
		$plan = $this->plan();
		$plan->tested_at   = '2026-10-11 12:00:00';
		$plan->tested_hash = Fingerprint::of( 7, $plan, '/an/older/upload.zip' );
		$this->plans->save( 7, array( $plan ) );

		$html = $this->draw();

		$this->assertStringContainsString( 'changed since the test passed', $html );
		$this->assertStringNotContainsString( 'do=publish', $html );
	}

	public function test_a_plan_on_sale_can_still_be_tested_but_not_published_again(): void {
		$this->product( 'publish' );
		$this->plans->save( 7, array( $this->plan() ) );

		$html = $this->draw();

		$this->assertStringContainsString( 'On sale', $html );
		$this->assertStringContainsString( 'do=test', $html );
		$this->assertStringNotContainsString( 'do=publish', $html );
		$this->assertStringNotContainsString( 'Not for sale yet', $html );
	}

	public function test_a_deleted_product_offers_to_create_it_again_and_no_test(): void {
		$this->plans->save( 7, array( $this->plan() ) );

		$html = $this->draw();

		$this->assertStringContainsString( 'Deleted in WooCommerce', $html );
		$this->assertStringContainsString( 'do=forget', $html );
		$this->assertStringNotContainsString( 'do=test', $html );
	}

	public function test_a_price_conflict_shows_both_prices_and_both_choices(): void {
		$this->product( 'draft', 3900 );
		$this->plans->save( 7, array( $this->plan( null, null, 3900 ) ) );

		$html = $this->draw();

		$this->assertStringContainsString( 'WooCommerce price is 39.00, plan price is 99', $html );
		$this->assertStringContainsString( 'do=restore', $html );
		$this->assertStringContainsString( 'do=accept', $html );
	}

	public function test_a_passed_report_is_shown_once_with_every_check(): void {
		$this->product();
		$this->plans->save( 7, array( $this->plan() ) );
		$report = new TestReport( array( new CheckResult( 'product', 'The WooCommerce product', CheckResult::PASS, 'It exists.' ), new CheckResult( 'reachable', 'This site can reach its own license API', CheckResult::WARN, 'Blocked by the host.' ) ) );
		$this->queue_report( true, $report->to_array() );

		$html = $this->draw();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'passed. Nothing was saved or sent.', $html );
		$this->assertStringContainsString( 'The WooCommerce product', $html );
		$this->assertStringContainsString( 'Passed: ', $html );
		$this->assertStringContainsString( 'Warning: ', $html );
		$this->assertStringContainsString( 'Blocked by the host.', $html );

		$this->assertStringNotContainsString( 'Nothing was saved or sent', $this->draw(), 'The report is shown once.' );
	}

	public function test_a_failed_report_says_what_to_fix(): void {
		$this->product();
		$this->plans->save( 7, array( $this->plan() ) );
		$report = new TestReport(
			array(
				new CheckResult( 'product', 'The WooCommerce product', CheckResult::FAIL, 'It was deleted.' ),
				new CheckResult( 'terms', 'The license terms', CheckResult::SKIP, 'Not tried.' ),
			)
		);
		$this->queue_report( false, $report->to_array() );

		$html = $this->draw();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'did not pass', $html );
		$this->assertStringContainsString( 'Failed: ', $html );
		$this->assertStringContainsString( 'Not tried: ', $html );
	}

	public function test_text_from_the_report_and_the_plan_name_is_escaped(): void {
		$this->product();
		$this->plans->save( 7, array( $this->plan( null, null, null, '"><script>alert(1)</script>' ) ) );
		$report = new TestReport( array( new CheckResult( 'x', '<b>label</b>', CheckResult::FAIL, '<script>alert(2)</script>' ) ) );
		$this->queue_report( false, $report->to_array() );

		$html = $this->draw();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(2)&lt;/script&gt;', $html );
		$this->assertStringContainsString( '&lt;b&gt;label&lt;/b&gt;', $html );
	}

	public function test_a_damaged_stored_report_draws_nothing_and_does_not_crash(): void {
		$this->product();
		$this->plans->save( 7, array( $this->plan() ) );
		set_transient( 'wplit_plans_test_1_7', 'junk', 300 );

		$this->assertStringNotContainsString( 'Nothing was saved', $this->draw() );
	}
}
