<?php
/**
 * Tests for the product screen's boxes and tabs.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Admin\ConnectBox;
use Devllo\WPLicenseIt\Admin\PlansBox;
use Devllo\WPLicenseIt\Admin\ProductScreen;
use Devllo\WPLicenseIt\Admin\ReleasesBox;
use Devllo\WPLicenseIt\Releases\MetaReleaseRepository;
use Devllo\WPLicenseIt\Releases\ReleaseService;
use Devllo\WPLicenseIt\Tests\Support\FakeFileStore;
use Devllo\WPLicenseIt\Tests\Support\InMemoryProductMeta;
use Devllo\WPLicenseIt\Tests\Support\ConfigurableProductCatalog;
use Devllo\WPLicenseIt\Tests\Support\FakeOrderBuilder;
use Devllo\WPLicenseIt\Tests\Support\FakeProductGateway;
use Devllo\WPLicenseIt\Tests\Support\InMemoryPlanRepository;
use Devllo\WPLicenseIt\TestPurchase\ReachabilityProbe;
use Devllo\WPLicenseIt\TestPurchase\TestPurchase;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Admin\ProductScreen
 */
final class ProductScreenTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wplit_test_post_meta'] = array();
		$GLOBALS['wplit_test_meta_boxes'] = array();
	}

	private function post( string $type = 'wplit_product' ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = 7;
		$post->post_type = $type;

		return $post;
	}

	private function capture( callable $draw ): string {
		ob_start();
		$draw();

		return (string) ob_get_clean();
	}

	private function all_boxes(): string {
		$screen = new ProductScreen();
		$post   = $this->post();

		return $this->capture(
			static function () use ( $screen, $post ): void {
				$screen->render_software( $post );
				$screen->render_defaults( $post );
				$screen->render_release( $post );
				$screen->render_legacy_key( $post );
			}
		);
	}

	public function test_every_field_the_save_code_reads_has_an_input(): void {
		$html = $this->all_boxes();

		foreach ( array_merge( ProductScreen::FIELDS, ProductScreen::FILE_FIELDS ) as $name ) {
			$this->assertStringContainsString( 'name="' . $name . '"', $html, $name . ' has no input' );
		}
		$this->assertStringContainsString( 'name="wplit_inner_custom_box_nonce"', $html );
	}

	public function test_the_saver_covers_the_same_fields_the_screen_lists(): void {
		// ProductSaverTest posts every field and upload name listed here and checks each one is saved.
		$this->assertSame(
			array( 'wplit_product_name', 'wplit_product_description', 'wplit_tested_wp_version', 'wplit_required_wp_version', 'wplit_product_version', 'wplit_release_notes', 'wplit_product_api_key', 'wplit_default_activation_limit', 'wplit_period' ),
			ProductScreen::FIELDS
		);
		$this->assertSame( array( 'wplit_product_file_upload', 'wplit_product_logo', 'wplit_product_banner' ), ProductScreen::FILE_FIELDS );
	}

	public function test_every_box_named_in_a_tab_is_added_by_something(): void {
		$probe = new class() implements ReachabilityProbe {
			public function probe(): array {
				return array(
					'ok'      => true,
					'message' => '',
				);
			}
		};
		$woo     = new FakeProductGateway();
		$plans   = new InMemoryPlanRepository();
		$catalog = new ConfigurableProductCatalog();

		( new ProductScreen() )->add_boxes();
		$meta = new InMemoryProductMeta();
		( new ReleasesBox( new ReleaseService( new MetaReleaseRepository( $meta ), $meta ), new FakeFileStore() ) )->add_box();
		( new PlansBox( $plans, $woo, new TestPurchase( $woo, $catalog, new FakeOrderBuilder( $woo ), $probe ), $catalog ) )->add_box();
		( new ConnectBox( $plans, static function () {
			return null;
		}, $catalog ) )->add_box();

		$added = array_keys( $GLOBALS['wplit_test_meta_boxes'] );
		// WordPress adds the author box itself. The tab list only decides on which tab it is shown.
		$named = array_values( array_diff( array_merge( ...array_values( ProductScreen::TABS ) ), array( 'authordiv' ) ) );

		sort( $added );
		sort( $named );

		$this->assertSame( $named, $added, 'Boxes added and boxes assigned to tabs differ' );
	}

	public function test_the_tabs_are_software_plans_releases_connect_in_that_order(): void {
		$this->assertSame( array( 'software', 'plans', 'releases', 'connect' ), array_keys( ProductScreen::TABS ) );
		$this->assertSame( array_keys( ProductScreen::TABS ), array_keys( ProductScreen::tab_labels() ) );
	}

	public function test_the_tab_bar_has_four_links_and_valid_tab_data(): void {
		$screen = new ProductScreen();
		$html   = $this->capture(
			function () use ( $screen ): void {
				$screen->render_tabs( $this->post() );
			}
		);

		$this->assertStringContainsString( 'id="wplit-tabs"', $html );
		$this->assertSame( 4, substr_count( $html, 'data-tab="' ) );
		$this->assertStringContainsString( 'style="display:none', $html, 'The bar must be hidden until the script runs' );
		$this->assertStringContainsString( 'data-product="7"', $html );

		preg_match( '/data-tabs="([^"]*)"/', $html, $match );
		$this->assertSame( ProductScreen::TABS, json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true ) );
	}

	public function test_the_tab_bar_only_appears_on_products(): void {
		$screen = new ProductScreen();

		$html = $this->capture(
			function () use ( $screen ): void {
				$screen->render_tabs( $this->post( 'page' ) );
			}
		);

		$this->assertSame( '', $html );
	}

	public function test_saved_values_are_shown_and_escaped(): void {
		$GLOBALS['wplit_test_post_meta'][7] = array(
			'wplit_product_name'             => 'Bob\'s "Pro" <b>Plugin</b>',
			'wplit_product_version'          => '1.4.2',
			'wplit_default_activation_limit' => '3',
			'wplit_period'                   => 'P1Y',
		);

		$html = $this->all_boxes();

		$this->assertStringNotContainsString( '<b>Plugin</b>', $html );
		$this->assertStringContainsString( 'Bob&#039;s &quot;Pro&quot; &lt;b&gt;Plugin&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'value="1.4.2"', $html );
		$this->assertStringContainsString( 'value="3"', $html );
		$this->assertStringContainsString( '<option value="P1Y" selected="selected">', $html );
	}

	public function test_the_release_box_shows_what_was_read_from_the_zip(): void {
		$GLOBALS['wplit_test_post_meta'][7] = array(
			'file_name'                  => 'seo-pro.zip',
			'wplit_package_name'         => 'SEO Pro',
			'wplit_package_type'         => 'plugin',
			'wplit_required_php_version' => '8.0',
		);

		$html = $this->all_boxes();

		$this->assertStringContainsString( 'seo-pro.zip', $html );
		$this->assertStringContainsString( '(SEO Pro, plugin, PHP 8.0)', $html );
	}

	public function test_a_product_with_no_release_says_so(): void {
		$this->assertStringContainsString( 'none yet', $this->all_boxes() );
	}

	public function test_logo_and_banner_previews_only_when_set(): void {
		$this->assertStringNotContainsString( '<img', $this->all_boxes() );

		$GLOBALS['wplit_test_post_meta'][7] = array(
			'wplit_product_logo_url'   => 'https://shop.example.com/logo.png',
			'wplit_product_banner_url' => 'https://shop.example.com/banner.png',
		);

		$this->assertSame( 2, substr_count( $this->all_boxes(), '<img' ) );
	}

	public function test_the_upload_input_accepts_zips(): void {
		$this->assertStringContainsString( 'name="wplit_product_file_upload" accept=".zip,application/zip"', $this->all_boxes() );
	}

	public function test_the_tab_bar_is_attached_to_the_hook_that_fires_after_the_title(): void {
		$GLOBALS['wplit_test_actions'] = array();
		( new ProductScreen() )->register();

		$this->assertContains( 'edit_form_after_title', array_column( $GLOBALS['wplit_test_actions'], 0 ) );
		$this->assertNotContains( 'edit_form_after_editor', array_column( $GLOBALS['wplit_test_actions'], 0 ) );
	}

	public function test_the_product_has_no_editor_or_featured_image(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/wplicense-it-product-post.php' );

		preg_match( "/'supports'\\s*=>\\s*array\\(([^)]*)\\)/", $source, $match );

		$this->assertStringContainsString( "'title'", $match[1] );
		$this->assertStringNotContainsString( "'editor'", $match[1] );
		$this->assertStringNotContainsString( "'thumbnail'", $match[1] );
	}

	public function test_the_plans_tab_explains_itself_when_woocommerce_is_not_active(): void {
		$screen = new ProductScreen();
		$screen->add_boxes();

		$this->assertTrue( isset( $GLOBALS['wplit_test_meta_boxes']['wplit_plans'] ), 'The Plans tab has a box even without WooCommerce' );

		$html = $this->capture(
			static function () use ( $screen ): void {
				$screen->render_plans_unavailable();
			}
		);

		$this->assertStringContainsString( 'WooCommerce is not active.', $html );
		$this->assertStringContainsString( 'Add license', $html );
	}

	public function test_every_file_input_carries_the_server_limit_and_a_place_for_the_warning(): void {
		$html = $this->all_boxes();

		foreach ( ProductScreen::FILE_FIELDS as $name ) {
			$this->assertMatchesRegularExpression( '/<input type="file" id="' . $name . '" name="' . $name . '"[^>]*data-max-bytes="2097152" data-max-text="2 MB"/', $html, $name );
			$this->assertStringContainsString( 'id="' . $name . '-warning"', $html, $name . ' has no warning element' );
		}
	}

	public function test_the_tab_script_guards_file_inputs(): void {
		$screen = new ProductScreen();
		$html   = $this->capture(
			function () use ( $screen ): void {
				$screen->render_tabs( $this->post() );
			}
		);

		$this->assertStringContainsString( 'data-max-bytes', $html );
		$this->assertStringContainsString( 'raise upload_max_filesize and post_max_size', $html );
	}
}
