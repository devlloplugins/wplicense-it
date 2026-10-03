<?php
/**
 * Tests for the pieces that replaced the 1.x checkout's housekeeping.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Admin\UpgradeNotice;
use Devllo\WPLicenseIt\Files\ProtectedStorage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Files\ProtectedStorage
 * @covers \Devllo\WPLicenseIt\Admin\UpgradeNotice
 */
final class CheckoutRemovalTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wplit-storage-' . uniqid();
	}

	protected function tearDown(): void {
		foreach ( array( '/.htaccess', '/index.php' ) as $file ) {
			if ( is_file( $this->dir . $file ) ) {
				unlink( $this->dir . $file );
			}
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
	}

	public function test_the_package_folder_is_created_with_its_protection_files(): void {
		ProtectedStorage::create( $this->dir . '' );

		$this->assertTrue( is_dir( $this->dir ) );
		$this->assertTrue( is_file( $this->dir . '/.htaccess' ) );
		$this->assertTrue( is_file( $this->dir . '/index.php' ) );
		$this->assertStringContainsString( 'Silence is golden', (string) file_get_contents( $this->dir . '/index.php' ) );
	}

	public function test_creating_it_again_repairs_a_missing_or_old_htaccess(): void {
		ProtectedStorage::create( $this->dir );
		file_put_contents( $this->dir . '/.htaccess', '<Files ".zip">Deny from all</Files>' ); // The 1.x rule, which never matched.

		ProtectedStorage::create( $this->dir );

		$this->assertSame( ProtectedStorage::htaccess(), file_get_contents( $this->dir . '/.htaccess' ) );
	}

	public function test_the_htaccess_denies_zip_files_on_both_apache_versions(): void {
		$rules = ProtectedStorage::htaccess();

		$this->assertStringContainsString( '<FilesMatch "\.zip$">', $rules );
		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( 'Deny from all', $rules );
		$this->assertStringContainsString( '</FilesMatch>', $rules );
	}

	public function test_every_old_checkout_option_is_listed_for_cleanup(): void {
		$options = UpgradeNotice::legacy_options();

		$this->assertContains( 'wplit-stripe-settings-live-sk', $options );
		$this->assertContains( 'wplit-stripe-settings-test-sk', $options );
		$this->assertContains( 'wplit-checkout-page', $options );
		$this->assertContains( 'wplit-licenses-page', $options );
		$this->assertSame( count( $options ), count( array_unique( $options ) ) );
	}

	public function test_the_uninstall_script_removes_every_old_checkout_option(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		foreach ( UpgradeNotice::legacy_options() as $option ) {
			$this->assertStringContainsString( "'{$option}'", $script );
		}
	}
}
