<?php
/**
 * Tests for saving the product form.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';

use Devllo\WPLicenseIt\Admin\ProductScreen;
use Devllo\WPLicenseIt\Products\ProductSaver;
use Devllo\WPLicenseIt\Products\SaveReport;
use Devllo\WPLicenseIt\Tests\Support\FakeFileStore;
use Devllo\WPLicenseIt\Tests\Support\InMemoryProductMeta;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Products\ProductSaver
 * @covers \Devllo\WPLicenseIt\Products\SaveReport
 */
final class ProductSaverTest extends TestCase {

	private const ID = 7;

	/**
	 * @var InMemoryProductMeta
	 */
	private $meta;

	/**
	 * @var FakeFileStore
	 */
	private $files;

	/**
	 * @var ProductSaver
	 */
	private $saver;

	/**
	 * @var string[] Temporary files to remove.
	 */
	private $temp = array();

	protected function setUp(): void {
		$this->meta  = new InMemoryProductMeta();
		$this->files = new FakeFileStore();
		$this->saver = new ProductSaver( $this->meta, $this->files, '2M' );
	}

	protected function tearDown(): void {
		foreach ( $this->temp as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * Builds a zip on disk and returns a $_FILES entry for it.
	 *
	 * @param array<string,string> $entries Entry name to contents.
	 * @return array<string, mixed>
	 */
	private function zip( array $entries, string $name = 'seo-pro.zip' ): array {
		$path = tempnam( sys_get_temp_dir(), 'wplit' );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $entries as $entry => $contents ) {
			$zip->addFromString( $entry, $contents );
		}
		$zip->close();
		$this->temp[] = $path;

		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $path ),
		);
	}

	private function plugin_zip( string $header = "Plugin Name: SEO Pro\nVersion: 1.4.2\nRequires at least: 6.2\nRequires PHP: 8.0" ): array {
		return $this->zip( array( 'seo-pro/seo-pro.php' => "<?php\n/*\n" . $header . "\n*/\n" ) );
	}

	private function image( string $name = 'logo.png' ): array {
		return array(
			'name'     => $name,
			'tmp_name' => '/tmp/' . $name,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 100,
		);
	}

	private function save( array $post = array(), array $uploads = array() ): SaveReport {
		return $this->saver->save( self::ID, $post, $uploads );
	}

	private function meta( string $key ): string {
		return $this->meta->get( self::ID, $key );
	}

	// Text fields.

	public function test_text_fields_are_cleaned_and_saved(): void {
		$this->save(
			array(
				'wplit_product_name'        => "  <b>SEO</b>\n Pro  ",
				'wplit_product_description' => 'Does <script>alert(1)</script>things',
				'wplit_tested_wp_version'   => '6.7',
				'wplit_required_wp_version' => '6.2.1',
			)
		);

		$this->assertSame( 'SEO Pro', $this->meta( 'wplit_product_name' ) );
		$this->assertSame( 'Does alert(1)things', $this->meta( 'wplit_product_description' ) );
		$this->assertSame( '6.7', $this->meta( 'wplit_tested_wp_version' ) );
		$this->assertSame( '6.2.1', $this->meta( 'wplit_required_wp_version' ) );
	}

	public function test_long_text_is_cut(): void {
		$this->save(
			array(
				'wplit_product_name'        => str_repeat( 'a', 500 ),
				'wplit_product_description' => str_repeat( 'b', 5000 ),
			)
		);

		$this->assertSame( 120, strlen( $this->meta( 'wplit_product_name' ) ) );
		$this->assertSame( 1000, strlen( $this->meta( 'wplit_product_description' ) ) );
	}

	public function test_a_wordpress_version_that_is_not_one_is_not_kept(): void {
		$this->meta->set( self::ID, 'wplit_tested_wp_version', '6.5' );

		$this->save( array( 'wplit_tested_wp_version' => 'latest <b>one</b>' ) );

		$this->assertSame( '', $this->meta( 'wplit_tested_wp_version' ) );
	}

	public function test_a_field_that_was_not_submitted_is_left_alone(): void {
		$this->meta->set( self::ID, 'wplit_product_name', 'Keep me' );
		$this->meta->set( self::ID, 'wplit_product_version', '3.0' );
		$this->meta->set( self::ID, 'wplit_default_activation_limit', '5' );

		$this->save( array( 'wplit_product_description' => 'x' ) );

		$this->assertSame( 'Keep me', $this->meta( 'wplit_product_name' ) );
		$this->assertSame( '3.0', $this->meta( 'wplit_product_version' ) );
		$this->assertSame( '5', $this->meta( 'wplit_default_activation_limit' ) );
	}

	public function test_a_field_sent_as_a_list_is_ignored_and_does_not_crash(): void {
		$this->meta->set( self::ID, 'wplit_product_name', 'Keep me' );

		$this->save(
			array(
				'wplit_product_name'     => array( 'x' ),
				'wplit_product_version'  => array( '9' ),
				'wplit_product_api_key'  => array( 'k' ),
				'wplit_period'           => array( 'P1Y' ),
			)
		);

		$this->assertSame( 'Keep me', $this->meta( 'wplit_product_name' ) );
		$this->assertSame( '', $this->meta( 'wplit_product_version' ) );
		$this->assertSame( 24, strlen( $this->meta( 'wplit_product_api_key' ) ) );
	}

	// Defaults.

	public function test_defaults_for_sites_and_period_are_saved(): void {
		$this->save(
			array(
				'wplit_default_activation_limit' => '3',
				'wplit_period'                   => 'P1Y',
			)
		);

		$this->assertSame( '3', $this->meta( 'wplit_default_activation_limit' ) );
		$this->assertSame( 'P1Y', $this->meta( 'wplit_period' ) );
	}

	public function test_empty_or_invalid_defaults_remove_the_setting(): void {
		$this->meta->set( self::ID, 'wplit_default_activation_limit', '5' );
		$this->meta->set( self::ID, 'wplit_period', 'P1Y' );

		$this->save(
			array(
				'wplit_default_activation_limit' => 'many',
				'wplit_period'                   => 'P99Y',
			)
		);

		$this->assertArrayNotHasKey( 'wplit_default_activation_limit', $this->meta->data[ self::ID ] );
		$this->assertArrayNotHasKey( 'wplit_period', $this->meta->data[ self::ID ] );
	}

	// Version.

	public function test_a_typed_version_is_cleaned_and_saved(): void {
		$this->save( array( 'wplit_product_version' => '1.0 beta!' ) );
		$this->assertSame( '1.0beta', $this->meta( 'wplit_product_version' ) );

		$this->save( array( 'wplit_product_version' => '../../etc' ) );
		$this->assertSame( 'etc', $this->meta( 'wplit_product_version' ) );
	}

	public function test_a_cleared_version_is_saved_as_empty(): void {
		$this->meta->set( self::ID, 'wplit_product_version', '1.0' );

		$this->save( array( 'wplit_product_version' => '' ) );

		$this->assertSame( '', $this->meta( 'wplit_product_version' ) );
	}

	// Release zip.

	public function test_a_valid_zip_is_stored_and_fills_in_the_details(): void {
		$this->meta->set( self::ID, 'wplit_tested_wp_version', '6.0' );
		$zip = $this->zip(
			array(
				'seo-pro/seo-pro.php' => "<?php\n/*\nPlugin Name: SEO Pro\nVersion: 1.4.2\nRequires at least: 6.2\nRequires PHP: 8.0\n*/\n",
				'seo-pro/readme.txt'  => "=== SEO Pro ===\nTested up to: 6.7\nStable tag: 1.4.2\n",
			)
		);

		$report = $this->save( array( 'wplit_product_version' => '9.9' ), array( 'wplit_product_file_upload' => $zip ) );

		$this->assertSame( array(), $report->errors );
		$this->assertSame( '1.4.2', $this->meta( 'wplit_product_version' ), 'The zip wins over the typed version' );
		$this->assertSame( 'plugin', $this->meta( 'wplit_package_type' ) );
		$this->assertSame( 'SEO Pro', $this->meta( 'wplit_package_name' ) );
		$this->assertSame( 'seo-pro', $this->meta( 'wplit_package_folder' ) );
		$this->assertSame( '8.0', $this->meta( 'wplit_required_php_version' ) );
		$this->assertSame( '6.2', $this->meta( 'wplit_required_wp_version' ) );
		$this->assertSame( '6.7', $this->meta( 'wplit_tested_wp_version' ) );
		$this->assertSame( 'seo-pro.zip', $this->meta( 'file_name' ) );
		$this->assertSame( 'wplit-files/p7/v1.4.2/' . str_repeat( 'a', 32 ) . '/seo-pro.zip', $this->meta( 'file_dir_path' ) );
		$this->assertStringStartsWith( 'https://shop.example.com/wp-content/uploads/wplit-files/p7/', $this->meta( 'file_dir_location' ) );

		$this->assertCount( 1, $this->files->packages );
		$this->assertSame( '1.4.2', $this->files->packages[0]['version'] );
		$this->assertStringContainsString( 'SEO Pro 1.4.2 (plugin)', $report->messages[0] );
	}

	public function test_a_zip_without_requirements_leaves_the_existing_ones(): void {
		$this->meta->set( self::ID, 'wplit_required_wp_version', '6.0' );
		$this->meta->set( self::ID, 'wplit_tested_wp_version', '6.5' );

		$report = $this->save( array(), array( 'wplit_product_file_upload' => $this->plugin_zip( "Plugin Name: P\nVersion: 2.0" ) ) );

		$this->assertSame( '6.0', $this->meta( 'wplit_required_wp_version' ) );
		$this->assertSame( '6.5', $this->meta( 'wplit_tested_wp_version' ) );
		$this->assertCount( 2, $report->warnings, 'Both missing minimums are pointed out' );
	}

	public function test_a_zip_that_is_not_a_plugin_is_refused_and_nothing_about_the_release_changes(): void {
		$this->meta->set( self::ID, 'file_name', 'old.zip' );
		$this->meta->set( self::ID, 'wplit_product_version', '1.0' );

		$report = $this->save(
			array( 'wplit_product_version' => '1.1' ),
			array( 'wplit_product_file_upload' => $this->zip( array( 'x/x.php' => "<?php\necho 1;\n" ) ) )
		);

		$this->assertCount( 1, $report->errors );
		$this->assertStringContainsString( 'The release was not saved.', $report->errors[0] );
		$this->assertCount( 0, $this->files->packages );
		$this->assertSame( 'old.zip', $this->meta( 'file_name' ) );
		$this->assertSame( '1.1', $this->meta( 'wplit_product_version' ), 'The typed version is still saved' );
		$this->assertSame( '', $this->meta( 'wplit_package_name' ) );
	}

	public function test_a_file_that_is_not_a_zip_is_refused(): void {
		$path = tempnam( sys_get_temp_dir(), 'wplit' );
		file_put_contents( $path, 'not a zip' );
		$this->temp[] = $path;

		$report = $this->save( array(), array( 'wplit_product_file_upload' => array( 'name' => 'x.zip', 'tmp_name' => $path, 'error' => 0, 'size' => 9 ) ) );

		$this->assertStringContainsString( 'not a readable zip', $report->errors[0] );
		$this->assertCount( 0, $this->files->packages );
	}

	public function test_a_file_over_the_server_limit_says_so(): void {
		$report = $this->save( array(), array( 'wplit_product_file_upload' => array( 'name' => 'big.zip', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ) ) );

		$this->assertCount( 1, $report->errors );
		$this->assertStringContainsString( 'bigger than this server allows (2M)', $report->errors[0] );
		$this->assertCount( 0, $this->files->packages );
	}

	public function test_a_file_that_is_not_a_real_upload_is_refused(): void {
		$zip                   = $this->plugin_zip();
		$this->files->not_uploaded = array( $zip['tmp_name'] );

		$report = $this->save( array(), array( 'wplit_product_file_upload' => $zip ) );

		$this->assertStringContainsString( 'could not be verified', $report->errors[0] );
		$this->assertCount( 0, $this->files->packages );
	}

	public function test_no_file_chosen_does_nothing(): void {
		$report = $this->save( array(), array( 'wplit_product_file_upload' => array( 'name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 ) ) );

		$this->assertTrue( $report->is_empty() );
		$this->assertCount( 0, $this->files->packages );
	}

	public function test_a_storage_failure_leaves_the_release_unchanged_and_says_why(): void {
		$this->meta->set( self::ID, 'wplit_product_version', '1.0' );
		$this->files->package_error = 'The folder for this release could not be created.';

		$report = $this->save( array(), array( 'wplit_product_file_upload' => $this->plugin_zip() ) );

		$this->assertSame( 'The release was not saved. The folder for this release could not be created.', $report->errors[0] );
		$this->assertSame( '1.0', $this->meta( 'wplit_product_version' ), 'The version is not moved on without a stored file' );
		$this->assertSame( '', $this->meta( 'file_name' ) );
	}

	public function test_a_refused_zip_does_not_stop_the_other_fields_saving(): void {
		$report = $this->save(
			array( 'wplit_product_name' => 'Still saved' ),
			array( 'wplit_product_file_upload' => $this->zip( array( 'x.txt' => 'nothing' ) ) )
		);

		$this->assertCount( 1, $report->errors );
		$this->assertSame( 'Still saved', $this->meta( 'wplit_product_name' ) );
	}

	// Images.

	public function test_a_logo_and_a_banner_are_stored_and_linked(): void {
		$report = $this->save( array(), array( 'wplit_product_logo' => $this->image( 'logo.png' ), 'wplit_product_banner' => $this->image( 'banner.png' ) ) );

		$this->assertSame( array(), $report->errors );
		$this->assertStringEndsWith( 'wplit-files/p7/logo/logo.png', $this->meta( 'wplit_product_logo_url' ) );
		$this->assertStringEndsWith( 'wplit-files/p7/banner/banner.png', $this->meta( 'wplit_product_banner_url' ) );
		$this->assertSame( array( 'The logo was saved.', 'The banner was saved.' ), $report->messages );
	}

	public function test_a_refused_image_is_reported_and_the_rest_still_saves(): void {
		$this->files->image_error = 'Sorry, you are not allowed to upload this file type.';

		$report = $this->save( array( 'wplit_product_name' => 'Saved' ), array( 'wplit_product_logo' => $this->image( 'logo.exe' ) ) );

		$this->assertSame( array( 'The logo was not saved. Sorry, you are not allowed to upload this file type.' ), $report->errors );
		$this->assertSame( '', $this->meta( 'wplit_product_logo_url' ) );
		$this->assertSame( 'Saved', $this->meta( 'wplit_product_name' ) );
	}

	public function test_an_image_over_the_limit_says_so(): void {
		$file          = $this->image();
		$file['error'] = UPLOAD_ERR_FORM_SIZE;

		$report = $this->save( array(), array( 'wplit_product_banner' => $file ) );

		$this->assertStringContainsString( 'The banner was not saved.', $report->errors[0] );
		$this->assertStringContainsString( '2M', $report->errors[0] );
	}

	// API key.

	public function test_a_typed_api_key_is_saved_and_limited(): void {
		$this->save( array( 'wplit_product_api_key' => '  my-key  ' ) );
		$this->assertSame( 'my-key', $this->meta( 'wplit_product_api_key' ) );

		$this->save( array( 'wplit_product_api_key' => str_repeat( 'k', 200 ) ) );
		$this->assertSame( 64, strlen( $this->meta( 'wplit_product_api_key' ) ) );
	}

	public function test_an_empty_api_key_field_keeps_the_current_key(): void {
		$this->meta->set( self::ID, 'wplit_product_api_key', 'current-key' );

		$this->save( array( 'wplit_product_api_key' => '' ) );

		$this->assertSame( 'current-key', $this->meta( 'wplit_product_api_key' ) );
	}

	public function test_a_product_without_a_key_gets_one(): void {
		$this->save( array( 'wplit_product_api_key' => '' ) );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{24}$/', $this->meta( 'wplit_product_api_key' ) );
	}

	public function test_a_generated_key_is_kept_on_later_saves(): void {
		$this->save();
		$first = $this->meta( 'wplit_product_api_key' );

		$this->save();

		$this->assertSame( $first, $this->meta( 'wplit_product_api_key' ) );
	}

	// The form and the saver agree.

	public function test_every_field_on_the_screen_is_saved(): void {
		$values = array(
			'wplit_product_name'             => 'Name',
			'wplit_product_description'      => 'Description',
			'wplit_tested_wp_version'        => '6.7',
			'wplit_required_wp_version'      => '6.2',
			'wplit_product_version'          => '1.0',
			'wplit_product_api_key'          => 'key',
			'wplit_default_activation_limit' => '2',
			'wplit_period'                   => 'P1Y',
		);

		$this->assertSame( ProductScreen::FIELDS, array_keys( $values ), 'The test must cover every field the screen has' );

		$this->save( $values );

		foreach ( $values as $key => $value ) {
			$this->assertSame( $value, $this->meta( $key ), $key . ' was not saved' );
		}
	}

	public function test_every_upload_field_on_the_screen_is_handled(): void {
		$uploads = array(
			'wplit_product_file_upload' => $this->plugin_zip(),
			'wplit_product_logo'        => $this->image( 'logo.png' ),
			'wplit_product_banner'      => $this->image( 'banner.png' ),
		);

		$this->assertSame( ProductScreen::FILE_FIELDS, array_keys( $uploads ), 'The test must cover every upload field the screen has' );

		$report = $this->save( array(), $uploads );

		$this->assertSame( array(), $report->errors );
		$this->assertSame( 'seo-pro.zip', $this->meta( 'file_name' ) );
		$this->assertTrue( '' !== $this->meta( 'wplit_product_logo_url' ) );
		$this->assertTrue( '' !== $this->meta( 'wplit_product_banner_url' ) );
	}

	// The report.

	public function test_a_report_survives_being_kept(): void {
		$report           = new SaveReport();
		$report->errors   = array( 'e' );
		$report->messages = array( 'm' );
		$report->warnings = array( 'w' );

		$this->assertSame( $report->to_array(), SaveReport::from_array( $report->to_array() )->to_array() );
		$this->assertFalse( $report->is_empty() );
		$this->assertTrue( ( new SaveReport() )->is_empty() );
	}

	public function test_a_damaged_kept_report_is_cleaned(): void {
		$this->assertTrue( SaveReport::from_array( 'junk' )->is_empty() );

		$report = SaveReport::from_array( array( 'errors' => array( 'ok', array( 'x' ), 5 ), 'messages' => 'no' ) );

		$this->assertSame( array( 'ok' ), $report->errors );
		$this->assertSame( array(), $report->messages );
	}
}
