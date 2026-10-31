<?php
/**
 * Tests for the release history.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Releases\MetaReleaseRepository;
use Devllo\WPLicenseIt\Releases\Release;
use Devllo\WPLicenseIt\Releases\ReleaseException;
use Devllo\WPLicenseIt\Releases\ReleaseService;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryProductMeta;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Releases\ReleaseService
 * @covers \Devllo\WPLicenseIt\Releases\Release
 * @covers \Devllo\WPLicenseIt\Releases\MetaReleaseRepository
 */
final class ReleaseServiceTest extends TestCase {

	private const ID = 7;

	/**
	 * @var InMemoryProductMeta
	 */
	private $meta;

	/**
	 * @var ReleaseService
	 */
	private $service;

	protected function setUp(): void {
		$this->meta    = new InMemoryProductMeta();
		$this->service = new ReleaseService( new MetaReleaseRepository( $this->meta ), $this->meta, new FixedClock( '2026-03-01 12:00:00' ) );
	}

	private function release( string $version = '1.0.0', string $notes = '', string $requires_wp = '6.2' ): Release {
		return new Release( '', $version, 'wplit-files/p7/v' . $version . '/' . str_repeat( 'a', 32 ) . '/seo-pro.zip', 'https://x.test/' . $version . '.zip', 'seo-pro.zip', 2048, '', $notes, 'plugin', 'SEO Pro', 'seo-pro', '8.0', $requires_wp, '6.7' );
	}

	private function meta( string $key ): string {
		return $this->meta->get( self::ID, $key );
	}

	public function test_adding_a_release_makes_it_current_and_fills_the_settings_the_api_reads(): void {
		$warnings = $this->service->add( self::ID, $this->release( '1.4.2', "Fixed the sitemap.\nAdded a setting." ) );

		$this->assertSame( array(), $warnings );
		$release = $this->service->current( self::ID );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}$/', $release->id );
		$this->assertSame( '2026-03-01 12:00:00', $release->uploaded_at );

		$this->assertSame( $release->id, $this->meta( 'wplit_current_release' ) );
		$this->assertSame( '1.4.2', $this->meta( 'wplit_product_version' ) );
		$this->assertSame( $release->path, $this->meta( 'file_dir_path' ) );
		$this->assertSame( 'https://x.test/1.4.2.zip', $this->meta( 'file_dir_location' ) );
		$this->assertSame( 'seo-pro.zip', $this->meta( 'file_name' ) );
		$this->assertSame( 'plugin', $this->meta( 'wplit_package_type' ) );
		$this->assertSame( 'SEO Pro', $this->meta( 'wplit_package_name' ) );
		$this->assertSame( 'seo-pro', $this->meta( 'wplit_package_folder' ) );
		$this->assertSame( '8.0', $this->meta( 'wplit_required_php_version' ) );
		$this->assertSame( '6.2', $this->meta( 'wplit_required_wp_version' ) );
		$this->assertSame( '6.7', $this->meta( 'wplit_tested_wp_version' ) );
		$this->assertSame( "Fixed the sitemap.\nAdded a setting.", $this->meta( 'wplit_changelog' ) );
	}

	public function test_releases_are_listed_newest_first_and_the_latest_upload_is_current(): void {
		$this->service->add( self::ID, $this->release( '1.0.0' ) );
		$this->service->add( self::ID, $this->release( '1.1.0' ) );
		$this->service->add( self::ID, $this->release( '1.2.0' ) );

		$this->assertSame( array( '1.2.0', '1.1.0', '1.0.0' ), array_map( static fn( Release $r ): string => $r->version, $this->service->all( self::ID ) ) );
		$this->assertSame( '1.2.0', $this->service->current( self::ID )->version );
		$this->assertCount( 3, array_unique( array_map( static fn( Release $r ): string => $r->id, $this->service->all( self::ID ) ) ) );
	}

	public function test_notes_are_cleaned(): void {
		$this->service->add( self::ID, $this->release( '1.0.0', "  <b>Fixed</b>\n\n\n  <script>alert(1)</script>Added\x01 \r\n" ) );

		$this->assertSame( "Fixed\nalert(1)Added", $this->meta( 'wplit_changelog' ) );
		$this->assertSame( 5000, strlen( ReleaseService::notes( str_repeat( 'a', 9000 ) ) ) );
	}

	public function test_a_repeated_version_and_an_older_version_are_pointed_out(): void {
		$this->service->add( self::ID, $this->release( '1.2.0' ) );

		$same = $this->service->add( self::ID, $this->release( '1.2.0' ) );
		$this->assertCount( 1, $same );
		$this->assertStringContainsString( 'already released', $same[0] );

		$older = $this->service->add( self::ID, $this->release( '1.1.0' ) );
		$this->assertCount( 1, $older );
		$this->assertStringContainsString( 'older than the one customers had (1.2.0)', $older[0] );
	}

	public function test_a_newer_version_has_no_warnings(): void {
		$this->service->add( self::ID, $this->release( '1.0.0' ) );

		$this->assertSame( array(), $this->service->add( self::ID, $this->release( '1.10.0' ) ) );
	}

	public function test_rolling_back_makes_an_older_release_current_again(): void {
		$this->service->add( self::ID, $this->release( '1.0.0', 'First.' ) );
		$this->service->add( self::ID, $this->release( '1.1.0', 'Broken.' ) );
		$first = $this->service->all( self::ID )[1];

		$released = $this->service->make_current( self::ID, $first->id );

		$this->assertSame( '1.0.0', $released->version );
		$this->assertSame( $first->id, $this->service->current( self::ID )->id );
		$this->assertSame( '1.0.0', $this->meta( 'wplit_product_version' ) );
		$this->assertSame( $first->path, $this->meta( 'file_dir_path' ) );
		$this->assertSame( 'First.', $this->meta( 'wplit_changelog' ) );
		$this->assertCount( 2, $this->service->all( self::ID ), 'Nothing is lost by a rollback' );
	}

	public function test_a_rollback_does_not_wipe_a_requirement_the_old_release_never_stated(): void {
		$this->service->add( self::ID, $this->release( '1.0.0', '', '' ) );
		$this->service->add( self::ID, $this->release( '1.1.0', '', '6.5' ) );

		$this->service->make_current( self::ID, $this->service->all( self::ID )[1]->id );

		$this->assertSame( '6.5', $this->meta( 'wplit_required_wp_version' ) );
	}

	public function test_an_unknown_release_cannot_be_made_current_or_removed(): void {
		$this->service->add( self::ID, $this->release() );

		foreach ( array( 'make_current', 'remove' ) as $method ) {
			try {
				$this->service->{$method}( self::ID, 'ffffffff' );
				$this->fail( 'Expected an exception from ' . $method );
			} catch ( ReleaseException $e ) {
				$this->assertSame( 'That release no longer exists.', $e->getMessage() );
			}
		}
	}

	public function test_a_release_that_is_not_current_can_be_removed(): void {
		$this->service->add( self::ID, $this->release( '1.0.0' ) );
		$this->service->add( self::ID, $this->release( '1.1.0' ) );
		$old = $this->service->all( self::ID )[1];

		$removed = $this->service->remove( self::ID, $old->id );

		$this->assertSame( '1.0.0', $removed->version );
		$this->assertSame( array( '1.1.0' ), array_map( static fn( Release $r ): string => $r->version, $this->service->all( self::ID ) ) );
	}

	public function test_the_current_release_cannot_be_removed(): void {
		$this->service->add( self::ID, $this->release( '1.0.0' ) );

		try {
			$this->service->remove( self::ID, $this->service->current( self::ID )->id );
			$this->fail( 'Expected an exception' );
		} catch ( ReleaseException $e ) {
			$this->assertStringContainsString( 'Make another release current', $e->getMessage() );
		}

		$this->assertCount( 1, $this->service->all( self::ID ) );
	}

	public function test_it_knows_when_another_release_uses_the_same_file(): void {
		$this->service->add( self::ID, $this->release( '1.0.0' ) );
		$a = $this->release( '1.0.1' );
		$this->service->add( self::ID, $a );
		$ids = array_map( static fn( Release $r ): string => $r->id, $this->service->all( self::ID ) );

		$this->assertFalse( $this->service->shares_file( self::ID, $ids[0], $a->path ) );

		$same_path = new Release( '', '1.0.2', $a->path, $a->url, $a->name );
		$this->service->add( self::ID, $same_path );
		$this->assertTrue( $this->service->shares_file( self::ID, $ids[0], $a->path ) );
	}

	public function test_notes_and_version_of_the_current_release_update_the_settings(): void {
		$this->service->add( self::ID, $this->release( '1.0.0', 'Old.' ) );
		$this->service->add( self::ID, $this->release( '1.1.0', 'Current.' ) );
		$all = $this->service->all( self::ID );

		$this->service->set_notes( self::ID, $all[0]->id, 'Updated.' );
		$this->service->set_version( self::ID, $all[0]->id, '1.1.1' );
		$this->assertSame( 'Updated.', $this->meta( 'wplit_changelog' ) );
		$this->assertSame( '1.1.1', $this->meta( 'wplit_product_version' ) );

		$this->service->set_notes( self::ID, $all[1]->id, 'Older notes.' );
		$this->service->set_version( self::ID, $all[1]->id, '0.9.0' );
		$this->assertSame( 'Updated.', $this->meta( 'wplit_changelog' ), 'An older release does not touch the current notes' );
		$this->assertSame( '1.1.1', $this->meta( 'wplit_product_version' ) );
		$this->assertSame( 'Older notes.', $this->service->all( self::ID )[1]->notes );
	}

	// Products from before releases were recorded.

	private function legacy_product(): void {
		$this->meta->set( self::ID, 'file_dir_path', 'wplit-files/p7/v1.0.0/' . str_repeat( 'b', 32 ) . '/old.zip' );
		$this->meta->set( self::ID, 'file_dir_location', 'https://x.test/old.zip' );
		$this->meta->set( self::ID, 'file_name', 'old.zip' );
		$this->meta->set( self::ID, 'wplit_product_version', '1.0.0' );
	}

	public function test_a_product_from_before_releases_shows_its_one_release(): void {
		$this->legacy_product();

		$all = $this->service->all( self::ID );

		$this->assertCount( 1, $all );
		$this->assertSame( '1.0.0', $all[0]->version );
		$this->assertSame( 'old.zip', $all[0]->name );
		$this->assertSame( $all[0]->id, $this->service->current( self::ID )->id );
		$this->assertSame( $all[0]->id, $this->service->all( self::ID )[0]->id, 'The ID is stable between reads' );
		$this->assertSame( '', $this->meta( 'wplit_releases' ), 'Reading saves nothing' );
	}

	public function test_the_first_upload_keeps_the_old_release_in_the_history(): void {
		$this->legacy_product();

		$this->service->add( self::ID, $this->release( '1.1.0' ) );

		$this->assertSame( array( '1.1.0', '1.0.0' ), array_map( static fn( Release $r ): string => $r->version, $this->service->all( self::ID ) ) );
		$this->assertNotSame( '', $this->meta( 'wplit_releases' ) );
	}

	public function test_a_product_with_no_file_has_no_releases(): void {
		$this->assertSame( array(), $this->service->all( self::ID ) );
		$this->assertNull( $this->service->current( self::ID ) );
	}

	public function test_a_missing_or_stale_pointer_falls_back_sensibly(): void {
		$this->service->add( self::ID, $this->release( '1.0.0' ) );
		$this->service->add( self::ID, $this->release( '1.1.0' ) );

		$this->meta->set( self::ID, 'wplit_current_release', 'deadbeef' );
		$this->meta->set( self::ID, 'file_dir_path', $this->service->all( self::ID )[1]->path );
		$this->assertSame( '1.0.0', $this->service->current( self::ID )->version, 'A stale pointer falls back to the file the settings name' );

		$this->meta->delete( self::ID, 'wplit_current_release' );
		$this->meta->delete( self::ID, 'file_dir_path' );
		$this->assertSame( '1.1.0', $this->service->current( self::ID )->version, 'Then to the newest' );
	}

	public function test_damaged_stored_releases_are_skipped(): void {
		$this->meta->set( self::ID, 'wplit_releases', 'not json' );
		$this->assertSame( array(), $this->service->all( self::ID ) );

		$this->meta->set( self::ID, 'wplit_releases', '[{"id":"aaaaaaaa"},"junk",{"id":"bbbbbbbb","version":"1","path":"p","name":"n"}]' );
		$this->assertCount( 1, $this->service->all( self::ID ) );
	}

	public function test_a_release_survives_storage(): void {
		$release = $this->release( '1.0.0', 'Notes.' );
		$this->service->add( self::ID, $release );

		$stored = $this->service->all( self::ID )[0];

		$this->assertSame( $release->to_array(), $stored->to_array() );
	}
}
