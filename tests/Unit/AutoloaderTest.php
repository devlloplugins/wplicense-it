<?php
/**
 * Tests for the PSR-4 autoloader.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Autoloader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Autoloader
 */
final class AutoloaderTest extends TestCase {

	public function test_maps_class_to_file_inside_base_dir(): void {
		$this->assertSame(
			'/plugin/src/Plugin.php',
			Autoloader::path_for( 'Devllo\\WPLicenseIt\\Plugin', '/plugin/src/' )
		);
	}

	public function test_maps_nested_namespaces_to_directories(): void {
		$this->assertSame(
			'/plugin/src/Licenses/LicenseRepository.php',
			Autoloader::path_for( 'Devllo\\WPLicenseIt\\Licenses\\LicenseRepository', '/plugin/src/' )
		);
	}

	public function test_ignores_classes_from_other_namespaces(): void {
		$this->assertNull( Autoloader::path_for( 'Other\\Plugin', '/plugin/src/' ) );
		$this->assertNull( Autoloader::path_for( 'Devllo\\WPLicenseItPro\\Thing', '/plugin/src/' ) );
	}
}
