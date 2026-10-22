<?php
/**
 * Tests for the small product helpers.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Products\Text;
use Devllo\WPLicenseIt\Products\UploadError;
use Devllo\WPLicenseIt\Products\Version;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Products\Version
 * @covers \Devllo\WPLicenseIt\Products\Text
 * @covers \Devllo\WPLicenseIt\Products\UploadError
 */
final class ProductHelpersTest extends TestCase {

	/**
	 * @dataProvider versions
	 */
	public function test_versions( string $input, string $clean, string $folder ): void {
		$this->assertSame( $clean, Version::clean( $input ) );
		$this->assertSame( $folder, Version::folder( $input ) );
	}

	/**
	 * @return array<string, array{string,string,string}>
	 */
	public function versions(): array {
		return array(
			'normal'           => array( '1.4.2', '1.4.2', '1.4.2' ),
			'prerelease'       => array( '2.0.0-beta.1', '2.0.0-beta.1', '2.0.0-beta.1' ),
			'build metadata'   => array( '1.0+build5', '1.0+build5', '1.0-build5' ),
			'spaces and marks' => array( ' 1.0 beta! ', '1.0beta', '1.0beta' ),
			'dot runs'         => array( '1..2...3', '1.2.3', '1.2.3' ),
			'traversal'        => array( '../../etc', 'etc', 'etc' ),
			'ends trimmed'     => array( '.-1.0-.', '1.0', '1.0' ),
			'empty'            => array( '', '', 'unversioned' ),
			'only junk'        => array( '!!!', '', 'unversioned' ),
			'long'             => array( str_repeat( '1', 100 ), str_repeat( '1', 40 ), str_repeat( '1', 40 ) ),
		);
	}

	public function test_a_version_folder_never_contains_a_slash_or_leading_dot(): void {
		foreach ( array( '../x', '/etc/passwd', '.hidden', 'a/b' ) as $input ) {
			$folder = Version::folder( $input );
			$this->assertFalse( false !== strpos( $folder, '/' ), $folder );
			$this->assertTrue( '.' !== substr( $folder, 0, 1 ), $folder );
		}
	}

	public function test_text_lines(): void {
		$this->assertSame( 'a b', Text::line( " a \n\t b ", 10 ) );
		$this->assertSame( 'alert(1)x', Text::line( '<script>alert(1)</script>x', 50 ) );
		$this->assertSame( 'abc', Text::line( 'abcdef', 3 ) );
		$this->assertSame( '12', Text::line( 12, 10 ) );
		$this->assertSame( '', Text::line( array( 'x' ), 10 ) );
		$this->assertSame( '', Text::line( null, 10 ) );
	}

	/**
	 * @dataProvider wp_versions
	 */
	public function test_wordpress_versions( $input, string $expected ): void {
		$this->assertSame( $expected, Text::wp_version( $input ) );
	}

	/**
	 * @return array<string, array{mixed,string}>
	 */
	public function wp_versions(): array {
		return array(
			'two parts'   => array( '6.7', '6.7' ),
			'three parts' => array( '6.2.1', '6.2.1' ),
			'beta'        => array( '6.8-beta1', '6.8-beta1' ),
			'one part'    => array( '6', '6' ),
			'words'       => array( 'latest', '' ),
			'tags'        => array( '<b>6.7</b>', '6.7' ),
			'four parts'  => array( '6.7.1.2', '' ),
			'array'       => array( array( '6.7' ), '' ),
		);
	}

	public function test_upload_errors(): void {
		$this->assertNull( UploadError::message( UPLOAD_ERR_OK, '2M' ) );
		$this->assertNull( UploadError::message( UPLOAD_ERR_NO_FILE, '2M' ) );
		$this->assertStringContainsString( '(2M)', (string) UploadError::message( UPLOAD_ERR_INI_SIZE, '2M' ) );
		$this->assertStringContainsString( 'the upload limit', (string) UploadError::message( UPLOAD_ERR_FORM_SIZE, '' ) );
		$this->assertStringContainsString( 'partly', (string) UploadError::message( UPLOAD_ERR_PARTIAL, '' ) );
		$this->assertStringContainsString( 'temporary folder', (string) UploadError::message( UPLOAD_ERR_NO_TMP_DIR, '' ) );
		$this->assertStringContainsString( 'disk space', (string) UploadError::message( UPLOAD_ERR_CANT_WRITE, '' ) );
		$this->assertStringContainsString( 'extension', (string) UploadError::message( UPLOAD_ERR_EXTENSION, '' ) );
		$this->assertStringContainsString( 'error 99', (string) UploadError::message( 99, '' ) );
	}
}
