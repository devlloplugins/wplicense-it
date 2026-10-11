<?php
/**
 * Tests for the product save messages.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Packages\UploadReport;
use Devllo\WPLicenseIt\Products\SaveReport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Packages\UploadReport
 */
final class UploadReportTest extends TestCase {

	protected function setUp(): void {
		$store = &wplit_test_transients();
		$store = array();
	}

	private function render(): string {
		ob_start();
		UploadReport::render();

		return (string) ob_get_clean();
	}

	public function test_nothing_queued_prints_nothing(): void {
		$this->assertSame( '', $this->render() );
	}

	public function test_errors_messages_and_warnings_get_their_own_boxes(): void {
		$report           = new SaveReport();
		$report->errors   = array( 'The logo was not saved.' );
		$report->messages = array( 'Release saved.' );
		$report->warnings = array( 'No minimum PHP.' );
		UploadReport::queue( $report );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'The logo was not saved.', $html );
		$this->assertStringContainsString( 'Worth a look:', $html );
	}

	public function test_a_report_is_shown_once(): void {
		$report           = new SaveReport();
		$report->messages = array( 'Release saved.' );
		UploadReport::queue( $report );

		$this->assertStringContainsString( 'Release saved.', $this->render() );
		$this->assertSame( '', $this->render() );
	}

	public function test_text_from_a_zip_is_escaped(): void {
		$report           = new SaveReport();
		$report->warnings = array( '<script>alert(1)</script>' );
		UploadReport::queue( $report );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_a_damaged_kept_value_prints_nothing(): void {
		set_transient( 'wplit_package_report_1', 'junk', 300 );

		$this->assertSame( '', $this->render() );
	}
}
