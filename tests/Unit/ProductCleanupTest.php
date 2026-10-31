<?php
/**
 * Tests for removing a product's files when it is deleted.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Products\ProductCleanup;
use Devllo\WPLicenseIt\Releases\MetaReleaseRepository;
use Devllo\WPLicenseIt\Releases\Release;
use Devllo\WPLicenseIt\Releases\ReleaseService;
use Devllo\WPLicenseIt\Tests\Support\FakeFileStore;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryProductMeta;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Products\ProductCleanup
 */
final class ProductCleanupTest extends TestCase {

	private const ID = 7;

	/**
	 * @var ReleaseService
	 */
	private $service;

	/**
	 * @var FakeFileStore
	 */
	private $files;

	/**
	 * @var ProductCleanup
	 */
	private $cleanup;

	protected function setUp(): void {
		$meta          = new InMemoryProductMeta();
		$this->service = new ReleaseService( new MetaReleaseRepository( $meta ), $meta, new FixedClock( '2026-03-01 12:00:00' ) );
		$this->files   = new FakeFileStore();
		$this->cleanup = new ProductCleanup( $this->service, $this->files );
	}

	private function add( string $version, string $path ): void {
		$this->service->add( self::ID, new Release( '', $version, $path, 'https://x.test/' . $version, 'seo.zip', 1000, '', '' ) );
	}

	private function post( string $type ): object {
		return (object) array( 'post_type' => $type );
	}

	public function test_deleting_a_product_removes_every_release_zip_and_its_folder(): void {
		$this->add( '1.0.0', 'wplit-files/p7/v1.0.0/' . str_repeat( 'a', 32 ) . '/seo.zip' );
		$this->add( '1.1.0', 'wplit-files/p7/v1.1.0/' . str_repeat( 'b', 32 ) . '/seo.zip' );

		$this->cleanup->on_delete( self::ID, $this->post( 'wplit_product' ) );

		$this->assertCount( 2, $this->files->deleted );
		$this->assertSame( array( self::ID ), $this->files->deleted_products );
	}

	public function test_a_zip_that_two_releases_share_is_deleted_once(): void {
		$path = 'wplit-files/p7/v1.0.0/' . str_repeat( 'a', 32 ) . '/seo.zip';
		$this->add( '1.0.0', $path );
		$this->add( '1.0.1', $path );

		$this->cleanup->clean( self::ID );

		$this->assertSame( array( $path ), array_values( array_unique( $this->files->deleted ) ) );
	}

	public function test_other_post_types_are_left_alone(): void {
		$this->add( '1.0.0', 'wplit-files/p7/v1.0.0/' . str_repeat( 'a', 32 ) . '/seo.zip' );

		$this->cleanup->on_delete( self::ID, $this->post( 'post' ) );

		$this->assertSame( array(), $this->files->deleted );
		$this->assertSame( array(), $this->files->deleted_products );
	}

	public function test_a_product_without_releases_still_has_its_folder_cleared(): void {
		$this->cleanup->clean( self::ID );

		$this->assertSame( array(), $this->files->deleted );
		$this->assertSame( array( self::ID ), $this->files->deleted_products );
	}

	public function test_a_failure_is_logged_and_never_stops_the_deletion(): void {
		$this->add( '1.0.0', 'wplit-files/p7/v1.0.0/' . str_repeat( 'a', 32 ) . '/seo.zip' );
		$this->files->delete_error = 'disk is read-only';

		$log = tempnam( sys_get_temp_dir(), 'wplit-log' );
		$old = ini_set( 'error_log', $log );
		try {
			$this->cleanup->clean( self::ID );
		} finally {
			ini_set( 'error_log', (string) $old );
		}

		$this->assertStringContainsString( 'disk is read-only', (string) file_get_contents( $log ) );
		unlink( $log );
	}
}
