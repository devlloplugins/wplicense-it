<?php
/**
 * Tests for drawing the Connect box.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Admin\ConnectBox;
use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Tests\Support\ConfigurableProductCatalog;
use Devllo\WPLicenseIt\Tests\Support\FakeProductGateway;
use Devllo\WPLicenseIt\TestPurchase\Fingerprint;
use Devllo\WPLicenseIt\Tests\Support\InMemoryPlanRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Admin\ConnectBox
 */
final class ConnectBoxTest extends TestCase {

	/**
	 * @var InMemoryPlanRepository
	 */
	private $plans;

	/**
	 * @var FakeProductGateway
	 */
	private $woo;

	protected function setUp(): void {
		$this->plans                     = new InMemoryPlanRepository();
		$this->woo                       = new FakeProductGateway();
		$GLOBALS['wplit_test_post_meta'] = array();
		$GLOBALS['wplit_test_home']      = 'https://shop.example.com/';
	}

	private function draw( string $title = 'SEO Pro', string $status = 'publish', ?FakeProductGateway $gateway = null ): string {
		$post              = new \WP_Post();
		$post->ID          = 7;
		$post->post_title  = $title;
		$post->post_status = $status;

		$box = new ConnectBox(
			$this->plans,
			static function () use ( $gateway ) {
				return $gateway;
			},
			new ConfigurableProductCatalog()
		);

		ob_start();
		$box->render( $post );

		return (string) ob_get_clean();
	}

	public function test_an_unsaved_product_asks_to_be_saved_first(): void {
		$html = $this->draw( 'SEO Pro', 'auto-draft' );

		$this->assertStringContainsString( 'Save the product first', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
	}

	public function test_shows_the_code_with_the_server_and_product_id(): void {
		$html = $this->draw();

		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( 'https://shop.example.com', $html );
		$this->assertStringNotContainsString( 'https://shop.example.com/&', $html );
		$this->assertStringContainsString( '&#039;product_id&#039; =&gt; 7,', $html );
		$this->assertStringContainsString( 'Product ID: 7', $html );
	}

	public function test_offers_the_sdk_as_a_download_with_a_nonce(): void {
		$html = $this->draw();

		$this->assertStringContainsString( 'id="wplit-sdk-download"', $html );
		$this->assertStringContainsString( 'action=wplit_download_sdk', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertStringNotContainsString( 'Copy the sdk folder', $html );
	}

	public function test_uses_the_package_name_type_and_folder_when_known(): void {
		$GLOBALS['wplit_test_post_meta'][7] = array(
			'wplit_package_type'   => 'theme',
			'wplit_package_name'   => 'Neat Theme',
			'wplit_package_folder' => 'neat',
		);

		$html = $this->draw( 'Some other title' );

		$this->assertStringContainsString( '&#039;type&#039;       =&gt; &#039;theme&#039;,', $html );
		$this->assertStringContainsString( '&#039;slug&#039;       =&gt; &#039;neat&#039;,', $html );
		$this->assertStringContainsString( 'Neat Theme', $html );
		$this->assertStringNotContainsString( 'Some other title', $html );
	}

	public function test_a_product_name_cannot_break_out_of_the_textarea(): void {
		$html = $this->draw( "</textarea><script>alert('x')</script>" );

		$this->assertSame( 1, substr_count( $html, '</textarea>' ) );
		$this->assertStringNotContainsString( "<script>alert('x')", $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;&lt;script&gt;', $html );
	}

	public function test_the_checklist_follows_the_product(): void {
		$GLOBALS['wplit_test_post_meta'][7] = array(
			'file_name'             => 'seo-pro.zip',
			'wplit_product_version' => '1.4.2',
		);
		$this->woo->products[500]           = array(
			'status'   => 'publish',
			'price'    => 4900,
			'software' => 7,
			'sites'    => 1,
			'period'   => 'P1Y',
		);
		$plan                               = new Plan( 'aaaaaaaa', 'One site', '49', 1, 'P1Y', 500, 4900, 'USD', null, '2026-10-11 12:00:00' );
		$plan->tested_hash                  = Fingerprint::of( 7, $plan, null );
		$this->plans->save( 7, array( $plan ) );

		$html = $this->draw( 'SEO Pro', 'publish', $this->woo );

		$this->assertSame( 6, substr_count( $html, 'Done:' ) );
		$this->assertStringNotContainsString( 'To do:', $html );
	}

	public function test_a_test_that_no_longer_matches_does_not_count(): void {
		$GLOBALS['wplit_test_post_meta'][7] = array(
			'file_name'             => 'seo-pro.zip',
			'wplit_product_version' => '1.4.2',
		);
		$this->woo->products[500]           = array(
			'status'   => 'publish',
			'price'    => 4900,
			'software' => 7,
			'sites'    => 1,
			'period'   => 'P1Y',
		);
		$plan                               = new Plan( 'aaaaaaaa', 'One site', '49', 1, 'P1Y', 500, 4900, 'USD', null, '2026-10-11 12:00:00' );
		$plan->tested_hash                  = Fingerprint::of( 7, $plan, '/an/older/upload.zip' );
		$this->plans->save( 7, array( $plan ) );

		$html = $this->draw( 'SEO Pro', 'publish', $this->woo );

		$this->assertStringContainsString( 'To do: </span>Test purchase passed for every plan', $html );
	}

	public function test_a_new_product_shows_what_is_left(): void {
		$html = $this->draw();

		$this->assertSame( 1, substr_count( $html, 'Done:' ) );
		$this->assertSame( 5, substr_count( $html, 'To do:' ) );
	}

	public function test_works_without_woocommerce(): void {
		$this->plans->save( 7, array( new Plan( 'aaaaaaaa', 'One site', '49', 1, 'P1Y', 500, 4900, 'USD' ) ) );

		$html = $this->draw( 'SEO Pro', 'publish', null );

		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringContainsString( 'To do:', $html );
	}

	public function test_a_plain_http_site_is_flagged(): void {
		$GLOBALS['wplit_test_home'] = 'http://shop.example.com';

		$html = $this->draw();

		$this->assertStringContainsString( 'To do: </span>This site uses HTTPS', $html );
	}
}
