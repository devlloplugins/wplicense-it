<?php
/**
 * Tests for the upload report messages.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Packages\PackageException;
use Devllo\WPLicenseIt\Packages\PackageInfo;
use Devllo\WPLicenseIt\Packages\UploadReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Packages\UploadReport
 */
final class UploadReportTest extends TestCase {

	public function test_success_names_the_package_and_requirements(): void {
		$info               = new PackageInfo( 'plugin', 'SEO Pro', '1.4.2', 'seo-pro', 'seo-pro/seo-pro.php' );
		$info->requires_wp  = '6.2';
		$info->requires_php = '8.0';

		$report = UploadReport::success( $info );

		$this->assertTrue( $report['ok'] );
		$this->assertStringContainsString( 'SEO Pro 1.4.2 (plugin)', $report['message'] );
		$this->assertStringContainsString( 'WordPress 6.2 and PHP 8.0', $report['message'] );
	}

	public function test_success_without_requirements_does_not_mention_them(): void {
		$info = new PackageInfo( 'theme', 'Neat', '2.0', 'neat', 'neat/style.css' );

		$this->assertStringNotContainsString( 'Needs', UploadReport::success( $info )['message'] );
	}

	public function test_success_carries_the_warnings(): void {
		$info           = new PackageInfo( 'plugin', 'P', '1.0', 'p', 'p/p.php' );
		$info->warnings = array( 'Look at this.' );

		$this->assertSame( array( 'Look at this.' ), UploadReport::success( $info )['warnings'] );
	}

	public function test_failure_says_the_file_was_not_saved(): void {
		$report = UploadReport::failure( new PackageException( 'This zip is empty.' ) );

		$this->assertFalse( $report['ok'] );
		$this->assertSame( 'The file was not saved. This zip is empty.', $report['message'] );
	}
}
