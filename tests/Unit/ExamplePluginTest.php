<?php
/**
 * Tests for the example plugin made for a product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Connect\ExamplePlugin;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @covers \Devllo\WPLicenseIt\Connect\ExamplePlugin
 */
final class ExamplePluginTest extends TestCase {

	public function test_the_plugin_is_named_after_its_folder_and_has_this_server_and_product(): void {
		$files = ExamplePlugin::files( 'Great Plugin', 'great-plugin', 'https://shop.example.com', 42 );

		$this->assertSame( array( 'great-plugin/great-plugin.php', 'great-plugin/readme.txt' ), array_keys( $files ) );
		$main = $files['great-plugin/great-plugin.php'];
		$this->assertStringContainsString( 'Plugin Name: Great Plugin', $main );
		$this->assertStringContainsString( "'server'     => 'https://shop.example.com',", $main );
		$this->assertStringContainsString( "'product_id' => 42,", $main );
		$this->assertStringContainsString( "wplicense_it_license_active( 'great-plugin' )", $main );
		$this->assertStringContainsString( "admin_url( 'options-general.php?page=great-plugin-license' )", $main );
		$this->assertStringContainsString( 'Stable tag: 1.0.0', $files['great-plugin/readme.txt'] );
		$this->assertStringContainsString( '== Changelog ==', $files['great-plugin/readme.txt'] );
	}

	public function test_a_hostile_name_still_gives_a_plugin_that_parses(): void {
		$files = ExamplePlugin::files( "Bob's \"Pro\" */ <?php exit; \n Plugin", '', 'https://shop.example.com', 7 );
		$main  = (string) reset( $files );

		$this->assertSame( 'bob-s-pro-php-exit-plugin/bob-s-pro-php-exit-plugin.php', array_key_first( $files ), 'the folder is made from the name' );
		$this->assertStringNotContainsString( '*/ <', $main, 'the name cannot end the header comment' );
		$this->assertSame( 1, substr_count( $main, '<?' ), 'and brings no PHP tags of its own' );
		$this->assertTrue( $this->parses( $main ), 'the plugin file is valid PHP' );
	}

	public function test_the_zip_holds_the_plugin_and_the_sdk_in_vendor(): void {
		$files = ExamplePlugin::files( 'Great Plugin', 'great-plugin', 'https://shop.example.com', 42 );
		$path  = ExamplePlugin::build( $files, dirname( __DIR__, 2 ) . '/sdk' );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}
		$main = (string) $zip->getFromName( 'great-plugin/great-plugin.php' );
		$zip->close();
		unlink( $path );

		$this->assertContains( 'great-plugin/vendor/wplicense-it-client/wplicense-it-client.php', $names );
		$this->assertContains( 'great-plugin/vendor/wplicense-it-client/src/Client.php', $names );
		$this->assertNotContains( 'great-plugin/vendor/wplicense-it-client/phpcs.xml.dist', $names, 'no development files' );
		$this->assertSame( array(), array_filter( $names, static fn( string $name ): bool => 0 !== strpos( $name, 'great-plugin/' ) ), 'everything is inside one folder' );
		$this->assertTrue( $this->parses( $main ) );
	}

	/**
	 * Whether PHP accepts the code.
	 *
	 * @param string $code PHP file contents.
	 */
	private function parses( string $code ): bool {
		$file = tempnam( sys_get_temp_dir(), 'wplit-example-test' );
		file_put_contents( $file, $code );
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( (string) $file ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A test that runs php -l.
		unlink( (string) $file );

		return 0 === $status;
	}
}
