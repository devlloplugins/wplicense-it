<?php
/**
 * Tests for the release history box.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Admin\ReleasesBox;
use Devllo\WPLicenseIt\Releases\MetaReleaseRepository;
use Devllo\WPLicenseIt\Releases\Release;
use Devllo\WPLicenseIt\Releases\ReleaseService;
use Devllo\WPLicenseIt\Tests\Support\FakeFileStore;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryProductMeta;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Admin\ReleasesBox
 */
final class ReleasesBoxTest extends TestCase {

	private const ID = 7;

	/**
	 * @var InMemoryProductMeta
	 */
	private $meta;

	/**
	 * @var ReleaseService
	 */
	private $service;

	/**
	 * @var FakeFileStore
	 */
	private $files;

	/**
	 * @var ReleasesBox
	 */
	private $box;

	protected function setUp(): void {
		$this->meta    = new InMemoryProductMeta();
		$this->service = new ReleaseService( new MetaReleaseRepository( $this->meta ), $this->meta, new FixedClock( '2026-03-01 12:00:00' ) );
		$this->files   = new FakeFileStore();
		$this->box     = new ReleasesBox( $this->service, $this->files );
	}

	private function add( string $version, string $notes = '', string $name = 'seo-pro.zip', int $size = 1572864, ?string $path = null ): void {
		$this->service->add( self::ID, new Release( '', $version, $path ?? 'wplit-files/p7/v' . $version . '/' . str_repeat( 'a', 32 ) . '/' . $name, 'https://x.test/' . $version, $name, $size, '', $notes ) );
	}

	private function draw(): string {
		$post     = new \WP_Post();
		$post->ID = self::ID;

		ob_start();
		$this->box->render( $post );

		return (string) ob_get_clean();
	}

	private function id_of( string $version ): string {
		foreach ( $this->service->all( self::ID ) as $release ) {
			if ( $release->version === $version ) {
				return $release->id;
			}
		}
		$this->fail( 'No release ' . $version );
	}

	public function test_a_product_with_no_release_says_so(): void {
		$this->assertStringContainsString( 'No release yet', $this->draw() );
		$this->assertStringNotContainsString( '<table', $this->draw() );
	}

	public function test_the_history_lists_newest_first_and_marks_the_current_one(): void {
		$this->add( '1.0.0' );
		$this->add( '1.1.0' );

		$html = $this->draw();

		$this->assertTrue( strpos( $html, '1.1.0' ) < strpos( $html, '1.0.0' ), 'The newest release comes first' );
		$this->assertSame( 1, substr_count( $html, 'Current</span>' ) );
		$this->assertStringContainsString( '2 releases, 3.0 MB on disk', $html );
		$this->assertStringContainsString( '2026-03-01 12:00 UTC', $html );
		$this->assertStringContainsString( '1.5 MB', $html );
	}

	public function test_only_older_releases_get_actions(): void {
		$this->add( '1.0.0' );
		$this->add( '1.1.0' );

		$html = $this->draw();

		$this->assertSame( 1, substr_count( $html, 'do=current' ) );
		$this->assertSame( 1, substr_count( $html, 'do=delete' ) );
		$this->assertStringContainsString( 'release=' . $this->id_of( '1.0.0' ), $html );
		$this->assertStringNotContainsString( 'release=' . $this->id_of( '1.1.0' ), $html );
		$this->assertStringContainsString( 'confirm(', $html );
	}

	public function test_a_single_release_has_no_actions(): void {
		$this->add( '1.0.0' );

		$html = $this->draw();

		$this->assertStringNotContainsString( 'do=current', $html );
		$this->assertStringNotContainsString( 'do=delete', $html );
		$this->assertStringContainsString( '1 release,', $html );
	}

	public function test_notes_names_and_versions_are_escaped_even_if_stored_raw(): void {
		// Written straight to storage, as damaged or old data could be: the box must still escape it.
		( new MetaReleaseRepository( $this->meta ) )->save(
			self::ID,
			array( new Release( 'aaaaaaaa', '<i>1.0</i>', 'wplit-files/p7/x/y.zip', '', '<b>x</b>.zip', 0, '', '<script>alert(1)</script>' ) )
		);

		$html = $this->draw();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringNotContainsString( '<i>1.0</i>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_notes_are_cleaned_when_they_are_saved(): void {
		$this->add( '1.0.0', '<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', $this->draw() );
	}

	public function test_long_notes_are_folded_away(): void {
		$this->add( '1.0.0', str_repeat( 'word ', 80 ) );

		$html = $this->draw();

		$this->assertStringContainsString( '<details>', $html );
		$this->assertStringContainsString( '&hellip;', $html );
	}

	public function test_a_product_from_before_releases_shows_with_unknown_date_and_size(): void {
		$this->meta->set( self::ID, 'file_dir_path', 'wplit-files/p7/v1.0.0/' . str_repeat( 'b', 32 ) . '/old.zip' );
		$this->meta->set( self::ID, 'file_name', 'old.zip' );
		$this->meta->set( self::ID, 'wplit_product_version', '1.0.0' );

		$html = $this->draw();

		$this->assertStringContainsString( 'old.zip', $html );
		$this->assertStringContainsString( '—', $html );
		$this->assertStringContainsString( 'Current</span>', $html );
	}

	// Actions.

	public function test_making_an_older_release_current(): void {
		$this->add( '1.0.0', 'First.' );
		$this->add( '1.1.0', 'Broken.' );

		$report = $this->box->run( 'current', self::ID, $this->id_of( '1.0.0' ) );

		$this->assertSame( array( 'Version 1.0.0 is now the release customers get.' ), $report->messages );
		$this->assertSame( '1.0.0', $this->meta->get( self::ID, 'wplit_product_version' ) );
		$this->assertCount( 0, $this->files->deleted );
	}

	public function test_deleting_an_older_release_removes_its_zip(): void {
		$this->add( '1.0.0' );
		$this->add( '1.1.0' );
		$old = $this->service->all( self::ID )[1];

		$report = $this->box->run( 'delete', self::ID, $old->id );

		$this->assertSame( array(), $report->errors );
		$this->assertSame( array( $old->path ), $this->files->deleted );
		$this->assertCount( 1, $this->service->all( self::ID ) );
	}

	public function test_a_zip_another_release_still_uses_is_not_deleted(): void {
		$path = 'wplit-files/p7/v1.0.0/' . str_repeat( 'c', 32 ) . '/shared.zip';
		$this->add( '1.0.0', '', 'shared.zip', 1000, $path );
		$this->add( '1.0.1', '', 'shared.zip', 1000, $path );
		$this->add( '1.1.0' );

		$report = $this->box->run( 'delete', self::ID, $this->id_of( '1.0.0' ) );

		$this->assertSame( array(), $report->errors );
		$this->assertCount( 0, $this->files->deleted );
	}

	public function test_the_current_release_cannot_be_deleted(): void {
		$this->add( '1.0.0' );

		$report = $this->box->run( 'delete', self::ID, $this->id_of( '1.0.0' ) );

		$this->assertStringContainsString( 'Make another release current', $report->errors[0] );
		$this->assertCount( 0, $this->files->deleted );
		$this->assertCount( 1, $this->service->all( self::ID ) );
	}

	public function test_a_file_that_cannot_be_deleted_is_reported_and_the_record_is_gone(): void {
		$this->add( '1.0.0' );
		$this->add( '1.1.0' );
		$this->files->delete_error = 'The file could not be deleted.';

		$report = $this->box->run( 'delete', self::ID, $this->id_of( '1.0.0' ) );

		$this->assertStringContainsString( 'its file stays on the server', $report->errors[0] );
		$this->assertCount( 1, $this->service->all( self::ID ) );
	}

	public function test_unknown_releases_and_actions_are_refused(): void {
		$this->add( '1.0.0' );

		$this->assertSame( array( 'That release no longer exists.' ), $this->box->run( 'current', self::ID, 'ffffffff' )->errors );
		$this->assertSame( array( 'Unknown action.' ), $this->box->run( 'format', self::ID, 'x' )->errors );
	}

	public function test_sizes_and_dates(): void {
		$this->assertSame( '1 KB', ReleasesBox::size_text( 10 ) );
		$this->assertSame( '500 KB', ReleasesBox::size_text( 512000 ) );
		$this->assertSame( '1.0 MB', ReleasesBox::size_text( 1048576 ) );
		$this->assertSame( '—', ReleasesBox::date_text( '' ) );
		$this->assertSame( '—', ReleasesBox::date_text( 'not a date' ) );
		$this->assertSame( '2026-03-01 12:00 UTC', ReleasesBox::date_text( '2026-03-01 12:00:00' ) );
	}
}
