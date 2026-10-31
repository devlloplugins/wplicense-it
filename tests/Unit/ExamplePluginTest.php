<?php
/**
 * Tests for the example plugin made for a product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Connect\ExamplePlugin;
use Devllo\WPLicenseIt\Connect\ExampleTheme;
use Devllo\WPLicenseIt\Connect\Snippet;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @covers \Devllo\WPLicenseIt\Connect\ExamplePlugin
 * @covers \Devllo\WPLicenseIt\Connect\ExampleTheme
 * @covers \Devllo\WPLicenseIt\Connect\Snippet
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

	// Card 113: the example theme and the gating code.

	public function test_the_theme_is_a_standalone_theme_with_this_server_and_product(): void {
		$files = ExampleTheme::files( 'Great Theme', 'great-theme', 'https://shop.example.com', 42 );

		$this->assertSame( array( 'great-theme/style.css', 'great-theme/functions.php', 'great-theme/index.php', 'great-theme/readme.txt' ), array_keys( $files ) );
		$this->assertStringContainsString( 'Theme Name: Great Theme', $files['great-theme/style.css'] );
		$this->assertStringNotContainsString( 'Template:', $files['great-theme/style.css'], 'no parent theme needed' );
		$functions = $files['great-theme/functions.php'];
		$this->assertStringContainsString( "require_once get_template_directory() . '/vendor/wplicense-it-client/wplicense-it-client.php';", $functions );
		$this->assertStringContainsString( "'type'       => 'theme',", $functions );
		$this->assertStringContainsString( "'slug'       => 'great-theme',", $functions );
		$this->assertStringContainsString( "'product_id' => 42,", $functions );
		$this->assertStringContainsString( "wplicense_it_license_active( 'great-theme' )", $functions );
		foreach ( array( 'functions.php', 'index.php' ) as $file ) {
			$this->assertTrue( $this->parses( $files[ 'great-theme/' . $file ] ), $file . ' is valid PHP' );
		}

		$hostile = ExampleTheme::files( "Bob's */ <?php exit; \n Theme", '', 'https://shop.example.com', 7 );
		$this->assertTrue( $this->parses( $hostile['bob-s-php-exit-theme/functions.php'] ), 'a hostile name still gives a theme that parses' );
		$this->assertStringNotContainsString( '*/ <', $hostile['bob-s-php-exit-theme/style.css'] );
	}

	public function test_the_theme_zip_holds_the_sdk_in_vendor(): void {
		$path = ExamplePlugin::build( ExampleTheme::files( 'Great Theme', 'great-theme', 'https://shop.example.com', 42 ), dirname( __DIR__, 2 ) . '/sdk' );
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$this->assertNotFalse( $zip->locateName( 'great-theme/vendor/wplicense-it-client/wplicense-it-client.php' ) );
		$this->assertNotFalse( $zip->locateName( 'great-theme/style.css' ) );
		$zip->close();
		unlink( $path );
	}

	public function test_the_gating_code_names_the_product_and_parses(): void {
		$gate = Snippet::gate( 'Great Plugin', 'great-plugin' );

		$this->assertStringContainsString( "wplicense_it_license_active( 'great-plugin' )", $gate );
		$this->assertStringContainsString( "'expired' => 'stop'", $gate );
		$this->assertTrue( $this->parses( "<?php\n" . $gate ) );
		$this->assertStringContainsString( "wplicense_it_license_active( 'my-great-plugin' )", Snippet::gate( 'My Great Plugin!', '' ), 'made from the name without a folder' );
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
