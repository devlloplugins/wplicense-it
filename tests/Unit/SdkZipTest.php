<?php
/**
 * Tests for packing the client SDK.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Connect\SdkZip;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Connect\SdkZip
 */
final class SdkZipTest extends TestCase {

	private const SDK = __DIR__ . '/../../sdk';

	public function test_the_zip_holds_one_folder_with_the_loader_and_the_sources(): void {
		$path = SdkZip::build( self::SDK );
		$zip  = new \ZipArchive();
		$zip->open( $path );

		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();
		unlink( $path );

		$this->assertContains( 'wplicense-it-client/wplicense-it-client.php', $names );
		$this->assertContains( 'wplicense-it-client/src/Client.php', $names );
		$this->assertContains( 'wplicense-it-client/README.md', $names );

		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'wplicense-it-client/', $name );
		}
	}

	public function test_development_files_are_left_out(): void {
		$this->assertNotContains( 'phpcs.xml.dist', SdkZip::files( self::SDK ) );
	}

	public function test_every_sdk_source_file_is_packed(): void {
		$expected = array();
		foreach ( glob( self::SDK . '/src/*.php' ) as $file ) {
			$expected[] = 'src/' . basename( $file );
		}

		$this->assertSame( array(), array_diff( $expected, SdkZip::files( self::SDK ) ) );
	}

	public function test_a_folder_without_the_loader_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		SdkZip::build( sys_get_temp_dir() );
	}
}
