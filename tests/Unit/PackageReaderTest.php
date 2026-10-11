<?php
/**
 * Tests for reading plugin and theme zips.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Packages\PackageException;
use Devllo\WPLicenseIt\Packages\PackageInfo;
use Devllo\WPLicenseIt\Packages\PackageReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Packages\PackageReader
 * @covers \Devllo\WPLicenseIt\Packages\PackageInfo
 */
final class PackageReaderTest extends TestCase {

	private const PLUGIN = "<?php\n/**\n * Plugin Name: SEO Pro\n * Version: 1.4.2\n * Requires at least: 6.2\n * Requires PHP: 8.0\n * Author: Acme\n * Text Domain: seo-pro\n */\n";

	/**
	 * Builds a zip in a temp file, reads it and removes it.
	 *
	 * @param array<string,string> $entries Entry name to contents.
	 */
	private function read_zip( array $entries ): PackageInfo {
		$path = tempnam( sys_get_temp_dir(), 'wplit' );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();

		try {
			return PackageReader::read( $path );
		} finally {
			unlink( $path );
		}
	}

	public function test_reads_a_plugin(): void {
		$info = $this->read_zip( array( 'seo-pro/seo-pro.php' => self::PLUGIN ) );

		$this->assertSame( PackageInfo::TYPE_PLUGIN, $info->type );
		$this->assertSame( 'SEO Pro', $info->name );
		$this->assertSame( '1.4.2', $info->version );
		$this->assertSame( 'seo-pro', $info->folder );
		$this->assertSame( 'seo-pro/seo-pro.php', $info->main_file );
		$this->assertSame( '6.2', $info->requires_wp );
		$this->assertSame( '8.0', $info->requires_php );
		$this->assertSame( 'seo-pro', $info->text_domain );
		$this->assertSame( 'Acme', $info->author );
		$this->assertCount( 0, $info->warnings );
	}

	public function test_finds_the_header_in_a_file_with_another_name(): void {
		$info = $this->read_zip(
			array(
				'seo-pro/uninstall.php' => "<?php\n// nothing\n",
				'seo-pro/main.php'      => self::PLUGIN,
			)
		);

		$this->assertSame( 'seo-pro/main.php', $info->main_file );
	}

	public function test_ignores_php_files_in_subfolders(): void {
		$this->expectException( PackageException::class );
		$this->read_zip( array( 'seo-pro/includes/loader.php' => self::PLUGIN ) );
	}

	public function test_reads_a_theme(): void {
		$info = $this->read_zip(
			array(
				'neat/style.css' => "/*\nTheme Name: Neat\nVersion: 2.0.1\nRequires at least: 6.0\nRequires PHP: 7.4\n*/\n",
				'neat/index.php' => "<?php\n",
			)
		);

		$this->assertSame( PackageInfo::TYPE_THEME, $info->type );
		$this->assertSame( 'Neat', $info->name );
		$this->assertSame( '2.0.1', $info->version );
		$this->assertSame( 'neat', $info->folder );
	}

	public function test_plugin_wins_when_both_headers_exist(): void {
		$info = $this->read_zip(
			array(
				'x/x.php'     => self::PLUGIN,
				'x/style.css' => "/*\nTheme Name: X\nVersion: 9\n*/\n",
			)
		);

		$this->assertSame( PackageInfo::TYPE_PLUGIN, $info->type );
	}

	public function test_readme_fills_the_gaps(): void {
		$info = $this->read_zip(
			array(
				'p/p.php'      => "<?php\n/*\nPlugin Name: P\nVersion: 3.0\n*/\n",
				'p/readme.txt' => "=== P ===\nRequires at least: 6.3\nRequires PHP: 8.1\nTested up to: 6.7\nStable tag: 3.0\n",
			)
		);

		$this->assertSame( '6.3', $info->requires_wp );
		$this->assertSame( '8.1', $info->requires_php );
		$this->assertSame( '6.7', $info->tested_up_to );
		$this->assertCount( 0, $info->warnings );
	}

	public function test_header_wins_over_readme_for_requirements(): void {
		$info = $this->read_zip(
			array(
				'seo-pro/seo-pro.php' => self::PLUGIN,
				'seo-pro/readme.txt'  => "=== SEO Pro ===\nRequires at least: 5.0\nTested up to: 6.7\n",
			)
		);

		$this->assertSame( '6.2', $info->requires_wp );
		$this->assertSame( '6.7', $info->tested_up_to );
	}

	public function test_warns_when_stable_tag_disagrees(): void {
		$info = $this->read_zip(
			array(
				'seo-pro/seo-pro.php' => self::PLUGIN,
				'seo-pro/readme.txt'  => "=== SEO Pro ===\nStable tag: 1.4.1\n",
			)
		);

		$this->assertCount( 1, $info->warnings );
		$this->assertStringContainsString( '1.4.1', $info->warnings[0] );
	}

	public function test_trunk_stable_tag_is_not_a_warning(): void {
		$info = $this->read_zip(
			array(
				'seo-pro/seo-pro.php' => self::PLUGIN,
				'seo-pro/readme.txt'  => "=== SEO Pro ===\nStable tag: trunk\n",
			)
		);

		$this->assertCount( 0, $info->warnings );
	}

	public function test_warns_about_missing_requirements(): void {
		$info = $this->read_zip( array( 'p/p.php' => "<?php\n/*\nPlugin Name: P\nVersion: 1.0\n*/\n" ) );

		$this->assertCount( 2, $info->warnings );
	}

	public function test_warns_when_there_is_no_single_folder(): void {
		$info = $this->read_zip( array( 'seo-pro.php' => self::PLUGIN ) );

		$this->assertSame( '', $info->folder );
		$this->assertSame( 'seo-pro.php', $info->main_file );
		$this->assertCount( 1, $info->warnings );
		$this->assertStringContainsString( 'one folder', $info->warnings[0] );
	}

	public function test_two_top_level_folders_is_an_error(): void {
		try {
			$this->read_zip(
				array(
					'a/a.php' => self::PLUGIN,
					'b/b.php' => "<?php\n",
				)
			);
			$this->fail( 'Expected an exception' );
		} catch ( PackageException $e ) {
			$this->assertStringContainsString( 'more than one folder', $e->getMessage() );
		}
	}

	public function test_mac_resource_files_do_not_count(): void {
		$info = $this->read_zip(
			array(
				'seo-pro/seo-pro.php'          => self::PLUGIN,
				'__MACOSX/seo-pro/._seo-pro.php' => 'junk',
				'seo-pro/._readme.txt'         => 'junk',
			)
		);

		$this->assertSame( 'seo-pro', $info->folder );
		$this->assertCount( 0, $info->warnings );
	}

	public function test_windows_line_endings(): void {
		$info = $this->read_zip( array( 'p/p.php' => str_replace( "\n", "\r\n", self::PLUGIN ) ) );

		$this->assertSame( '1.4.2', $info->version );
		$this->assertSame( '8.0', $info->requires_php );
	}

	public function test_old_mac_line_endings(): void {
		$info = $this->read_zip( array( 'p/p.php' => str_replace( "\n", "\r", self::PLUGIN ) ) );

		$this->assertSame( '1.4.2', $info->version );
	}

	public function test_header_closing_on_the_same_line(): void {
		$info = $this->read_zip( array( 'p/p.php' => "<?php /* Plugin Name: P\nVersion: 1.0 */\n" ) );

		$this->assertSame( '1.0', $info->version );
	}

	public function test_hash_comment_style_header(): void {
		$info = $this->read_zip( array( 'p/p.php' => "<?php\n# Plugin Name: P\n# Version: 1.1\n" ) );

		$this->assertSame( '1.1', $info->version );
	}

	public function test_prerelease_versions(): void {
		foreach ( array( '1.0.0-beta.2', '2.1-rc1', '3' ) as $version ) {
			$info = $this->read_zip( array( 'p/p.php' => "<?php\n/*\nPlugin Name: P\nVersion: $version\n*/\n" ) );
			$this->assertSame( $version, $info->version );
		}
	}

	public function test_tags_are_stripped_from_the_name(): void {
		$info = $this->read_zip( array( 'p/p.php' => "<?php\n/*\nPlugin Name: <script>alert(1)</script>Safe\nVersion: 1.0\n*/\n" ) );

		$this->assertSame( 'alert(1)Safe', $info->name );
	}

	public function test_long_name_is_cut(): void {
		$info = $this->read_zip( array( 'p/p.php' => "<?php\n/*\nPlugin Name: " . str_repeat( 'a', 500 ) . "\nVersion: 1.0\n*/\n" ) );

		$this->assertSame( 120, strlen( $info->name ) );
	}

	public function test_missing_version_is_an_error(): void {
		$this->expectException( PackageException::class );
		$this->read_zip( array( 'p/p.php' => "<?php\n/*\nPlugin Name: P\n*/\n" ) );
	}

	public function test_invalid_version_is_an_error(): void {
		$this->expectException( PackageException::class );
		$this->read_zip( array( 'p/p.php' => "<?php\n/*\nPlugin Name: P\nVersion: ../../etc\n*/\n" ) );
	}

	public function test_version_with_spaces_is_an_error(): void {
		$this->expectException( PackageException::class );
		$this->read_zip( array( 'p/p.php' => "<?php\n/*\nPlugin Name: P\nVersion: one point two\n*/\n" ) );
	}

	public function test_zip_with_no_header_is_an_error(): void {
		$this->expectException( PackageException::class );
		$this->read_zip( array( 'p/p.php' => "<?php\necho 'hi';\n" ) );
	}

	public function test_zip_inside_a_zip_gets_a_clear_message(): void {
		try {
			$this->read_zip( array( 'seo-pro/seo-pro.zip' => 'PK' ) );
			$this->fail( 'Expected an exception' );
		} catch ( PackageException $e ) {
			$this->assertStringContainsString( 'inner zip', $e->getMessage() );
		}
	}

	public function test_a_file_that_is_not_a_zip_is_an_error(): void {
		$path = tempnam( sys_get_temp_dir(), 'wplit' );
		file_put_contents( $path, 'this is not a zip' );

		try {
			PackageReader::read( $path );
			$this->fail( 'Expected an exception' );
		} catch ( PackageException $e ) {
			$this->assertStringContainsString( 'not a readable zip', $e->getMessage() );
		} finally {
			unlink( $path );
		}
	}

	public function test_a_missing_file_is_an_error(): void {
		$this->expectException( PackageException::class );
		PackageReader::read( sys_get_temp_dir() . '/wplit-does-not-exist.zip' );
	}

	public function test_an_empty_zip_is_an_error(): void {
		$path = tempnam( sys_get_temp_dir(), 'wplit' );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$zip->close();

		try {
			PackageReader::read( $path );
			$this->fail( 'Expected an exception' );
		} catch ( PackageException $e ) {
			$this->assertTrue( '' !== $e->getMessage() );
		} finally {
			@unlink( $path );
		}
	}

	public function test_only_the_start_of_a_huge_file_is_read(): void {
		$info = $this->read_zip( array( 'p/p.php' => self::PLUGIN . str_repeat( '// padding ' . "\n", 200000 ) ) );

		$this->assertSame( '1.4.2', $info->version );
	}

	public function test_header_after_the_read_limit_is_not_found(): void {
		$this->expectException( PackageException::class );
		$this->read_zip( array( 'p/p.php' => "<?php\n" . str_repeat( '// x' . "\n", 5000 ) . "/*\nPlugin Name: Late\nVersion: 1.0\n*/\n" ) );
	}
}
