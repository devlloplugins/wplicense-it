<?php
/**
 * Tests for the WordPress side of saving a product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Admin\ProductSaveHook;
use Devllo\WPLicenseIt\Products\WpProductMeta;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Admin\ProductSaveHook
 * @covers \Devllo\WPLicenseIt\Products\WpProductMeta
 */
final class ProductSaveHookTest extends TestCase {

	/**
	 * @var array<string, mixed>
	 */
	private $saved_post;

	protected function setUp(): void {
		$this->saved_post                = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- A test double reads what the hook saw.
		$_POST                           = array();
		$GLOBALS['wplit_test_post_meta'] = array();
		$GLOBALS['wplit_test_can']       = true;

		$store = &wplit_test_transients();
		$store = array();
	}

	protected function tearDown(): void {
		$_POST = $this->saved_post;
		unset( $GLOBALS['wplit_test_can'] );
	}

	private function post( string $type = 'wplit_product' ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = 7;
		$post->post_type = $type;

		return $post;
	}

	private function submit( array $fields, string $nonce = 'good-nonce' ): void {
		// WordPress adds slashes to everything in $_POST.
		$_POST = array( ProductSaveHook::NONCE_FIELD => $nonce ) + wp_slash( $fields );
	}

	private function name(): string {
		return (string) ( $GLOBALS['wplit_test_post_meta'][7]['wplit_product_name'] ?? '' );
	}

	public function test_a_valid_form_is_saved(): void {
		$this->submit( array( 'wplit_product_name' => 'SEO Pro' ) );

		( new ProductSaveHook() )->save( 7, $this->post() );

		$this->assertSame( 'SEO Pro', $this->name() );
	}

	public function test_backslashes_and_quotes_survive_the_round_trip(): void {
		$this->submit( array( 'wplit_product_name' => 'Bob\'s "A\\B" plugin' ) );

		( new ProductSaveHook() )->save( 7, $this->post() );

		$this->assertSame( 'Bob\'s "A\\B" plugin', $this->name() );
		$this->assertSame( 'Bob\'s "A\\B" plugin', ( new WpProductMeta() )->get( 7, 'wplit_product_name' ) );
	}

	public function test_a_request_without_the_nonce_changes_nothing(): void {
		$_POST = array( 'wplit_product_name' => 'Sneaky' );

		( new ProductSaveHook() )->save( 7, $this->post() );

		$this->assertSame( '', $this->name() );
	}

	public function test_a_wrong_nonce_changes_nothing(): void {
		$this->submit( array( 'wplit_product_name' => 'Sneaky' ), 'bad-nonce' );

		( new ProductSaveHook() )->save( 7, $this->post() );

		$this->assertSame( '', $this->name() );
	}

	public function test_another_post_type_is_ignored(): void {
		$this->submit( array( 'wplit_product_name' => 'Sneaky' ) );

		( new ProductSaveHook() )->save( 7, $this->post( 'page' ) );

		$this->assertSame( '', $this->name() );
	}

	public function test_a_user_who_cannot_edit_the_product_changes_nothing(): void {
		$GLOBALS['wplit_test_can'] = false;
		$this->submit( array( 'wplit_product_name' => 'Sneaky' ) );

		( new ProductSaveHook() )->save( 7, $this->post() );

		$this->assertSame( '', $this->name() );
	}

	public function test_problems_are_kept_for_the_next_page(): void {
		$this->submit( array( 'wplit_product_name' => 'SEO Pro' ) );
		$_FILES = array(
			'wplit_product_file_upload' => array(
				'name'     => 'big.zip',
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_INI_SIZE,
				'size'     => 0,
			),
		);

		( new ProductSaveHook() )->save( 7, $this->post() );
		$_FILES = array();

		$kept = get_transient( 'wplit_package_report_1' );
		$this->assertTrue( is_array( $kept ) );
		$this->assertStringContainsString( 'bigger than this server allows', $kept['errors'][0] );
		$this->assertSame( 'SEO Pro', $this->name(), 'The rest of the form is still saved' );
	}

	public function test_a_clean_save_leaves_no_report(): void {
		$this->submit( array( 'wplit_product_name' => 'SEO Pro' ) );

		( new ProductSaveHook() )->save( 7, $this->post() );

		$this->assertSame( false, get_transient( 'wplit_package_report_1' ) );
	}

	public function test_only_product_forms_carry_files(): void {
		$hook = new ProductSaveHook();

		ob_start();
		$hook->form_tag( $this->post() );
		$hook->form_tag( $this->post( 'page' ) );
		$hook->form_tag( null );
		$html = (string) ob_get_clean();

		$this->assertSame( ' enctype="multipart/form-data"', $html );
	}
}
