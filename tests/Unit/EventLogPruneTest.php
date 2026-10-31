<?php
/**
 * Tests for pruning old events in slices.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Database\WpdbEventLog;
use Devllo\WPLicenseIt\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Database\WpdbEventLog
 */
final class EventLogPruneTest extends TestCase {

	private function before(): DateTimeImmutable {
		return new DateTimeImmutable( '2024-06-01 00:00:00', new DateTimeZone( 'UTC' ) );
	}

	public function test_prune_deletes_in_slices_until_a_slice_comes_back_short(): void {
		$db                = new FakeWpdb();
		$db->query_results = array( 5000, 5000, 1200 );

		$deleted = ( new WpdbEventLog( $db ) )->prune( $this->before() );

		$this->assertSame( 11200, $deleted );
		$this->assertCount( 3, $db->queries );
		$this->assertStringContainsString( "created_at < '2024-06-01 00:00:00' LIMIT 5000", $db->queries[0] );
	}

	public function test_prune_stops_after_the_most_slices_of_one_run(): void {
		$db                = new FakeWpdb();
		$db->query_results = array_fill( 0, 50, 100 );

		$deleted = ( new WpdbEventLog( $db ) )->prune( $this->before(), 100, 4 );

		$this->assertSame( 400, $deleted );
		$this->assertCount( 4, $db->queries, 'a bigger backlog continues the next day' );
	}

	public function test_prune_with_nothing_to_delete_runs_one_query(): void {
		$db = new FakeWpdb();

		$this->assertSame( 0, ( new WpdbEventLog( $db ) )->prune( $this->before() ) );
		$this->assertCount( 1, $db->queries );
	}
}
