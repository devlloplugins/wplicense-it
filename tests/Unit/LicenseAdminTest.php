<?php
/**
 * Tests for the license admin features: editing, reinstating, searching.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Database\WpdbLicenseRepository;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseChanges;
use Devllo\WPLicenseIt\Licenses\LicenseException;
use Devllo\WPLicenseIt\Licenses\LicenseQuery;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Licenses\ValidationResult;
use Devllo\WPLicenseIt\Tests\Support\FakeWpdb;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Licenses\LicenseService
 * @covers \Devllo\WPLicenseIt\Licenses\LicenseQuery
 * @covers \Devllo\WPLicenseIt\Database\WpdbLicenseRepository
 */
final class LicenseAdminTest extends TestCase {

	private LicenseService $service;
	private FixedClock $clock;
	private RecordingEventLog $events;

	protected function setUp(): void {
		$this->clock   = new FixedClock( '2026-03-01 00:00:00' );
		$this->events  = new RecordingEventLog();
		$this->service = new LicenseService( new InMemoryLicenseRepository(), new InMemoryActivationRepository(), $this->events, new KeyGenerator(), $this->clock );
	}

	private function utc( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
	}

	// Reinstating.

	public function test_a_revoked_or_refunded_license_can_be_reinstated(): void {
		$revoked  = $this->service->issue_license( 7, 'a@example.com' );
		$refunded = $this->service->issue_license( 7, 'b@example.com' );
		$this->service->revoke_license( $revoked->id );
		$this->service->revoke_license( $refunded->id, Status::REFUNDED );

		$this->assertSame( Status::ACTIVE, $this->service->reinstate_license( $revoked->id )->status );
		$this->assertSame( Status::ACTIVE, $this->service->reinstate_license( $refunded->id )->status );
		$this->assertTrue( $this->service->validate( $revoked->license_key )->is_valid() );
		$this->assertContains( EventLog::REINSTATED, $this->events->types() );
	}

	public function test_reinstating_after_the_expiry_leaves_it_expired_until_renewed(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-04-01 00:00:00' ) );
		$this->service->revoke_license( $license->id );
		$this->clock->advance( '+2 months' );

		$this->assertSame( Status::EXPIRED, $this->service->reinstate_license( $license->id )->status );
		$this->assertSame( ValidationResult::EXPIRED, $this->service->validate( $license->license_key )->code );
	}

	public function test_only_ended_licenses_can_be_reinstated(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		try {
			$this->service->reinstate_license( $license->id );
			$this->fail( 'Expected an exception.' );
		} catch ( LicenseException $e ) {
			$this->assertSame( LicenseException::INVALID_INPUT, $e->reason() );
		}

		$this->expectException( LicenseException::class );
		$this->service->reinstate_license( 999 );
	}

	// Editing.

	public function test_limit_email_and_expiry_can_be_edited_and_are_logged(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-04-01 00:00:00' ) );

		$changes                   = new LicenseChanges();
		$changes->activation_limit = 5;
		$changes->email            = ' New@Example.com ';
		$changes->change_expiry    = true;
		$changes->expires_at       = $this->utc( '2027-01-01 00:00:00' );

		$updated = $this->service->update_license( $license->id, $changes );

		$this->assertSame( 5, $updated->activation_limit );
		$this->assertSame( 'new@example.com', $updated->email );
		$this->assertSame( '2027-01-01', $updated->expires_at->format( 'Y-m-d' ) );

		$event = end( $this->events->events );
		$this->assertSame( EventLog::UPDATED, $event['type'] );
		$this->assertSame( array( 1, 5 ), $event['data']['activation_limit'] );
		$this->assertSame( array( 'a@example.com', 'new@example.com' ), $event['data']['email'] );
	}

	public function test_an_expiry_can_be_removed_to_make_a_lifetime_license(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-04-01 00:00:00' ) );

		$changes                = new LicenseChanges();
		$changes->change_expiry = true;
		$changes->expires_at    = null;

		$this->assertNull( $this->service->update_license( $license->id, $changes )->expires_at );
	}

	public function test_a_new_expiry_moves_the_status_with_it_but_never_undoes_a_revocation(): void {
		$expiring = $this->service->issue_license( 7, 'a@example.com', null, null, 1, $this->utc( '2026-04-01 00:00:00' ) );
		$revoked  = $this->service->issue_license( 7, 'b@example.com', null, null, 1, $this->utc( '2026-04-01 00:00:00' ) );
		$this->service->revoke_license( $revoked->id );
		$this->clock->advance( '+2 months' );
		$this->service->expire_due_licenses();

		$extend                = new LicenseChanges();
		$extend->change_expiry = true;
		$extend->expires_at    = $this->utc( '2027-01-01 00:00:00' );

		$this->assertSame( Status::ACTIVE, $this->service->update_license( $expiring->id, $extend )->status );
		$this->assertSame( Status::REVOKED, $this->service->update_license( $revoked->id, $extend )->status );

		$shorten             = new LicenseChanges();
		$shorten->change_expiry = true;
		$shorten->expires_at = $this->utc( '2026-01-01 00:00:00' );

		$this->assertSame( Status::EXPIRED, $this->service->update_license( $expiring->id, $shorten )->status );
	}

	public function test_editing_nothing_changes_nothing_and_logs_nothing(): void {
		$license = $this->service->issue_license( 7, 'a@example.com', null, null, 2 );
		$before  = count( $this->events->events );

		$changes                   = new LicenseChanges();
		$changes->activation_limit = 2;
		$changes->email            = 'A@example.com';

		$this->service->update_license( $license->id, $changes );

		$this->assertCount( $before, $this->events->events );
	}

	public function test_invalid_edits_are_rejected(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );

		foreach ( array( array( 'activation_limit', -1 ), array( 'email', 'nope' ), array( 'email', '  ' ) ) as $edit ) {
			$changes             = new LicenseChanges();
			$changes->{$edit[0]} = $edit[1];

			try {
				$this->service->update_license( $license->id, $changes );
				$this->fail( 'Expected an exception.' );
			} catch ( LicenseException $e ) {
				$this->assertSame( LicenseException::INVALID_INPUT, $e->reason() );
			}
		}
	}

	public function test_a_license_shows_its_history_newest_first(): void {
		$license = $this->service->issue_license( 7, 'a@example.com' );
		$this->service->activate( $license->license_key, 'one.com' );

		$types = array_column( $this->service->events( $license ), 'type' );

		$this->assertSame( array( EventLog::ACTIVATED, EventLog::ISSUED ), $types );
	}

	// Searching (in memory).

	public function test_search_filters_sorts_and_pages(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->service->issue_license( 7 + ( $i % 2 ), "user{$i}@example.com" );
		}
		$this->service->revoke_license( 2 );

		$all      = $this->service->search( LicenseQuery::from_input( array( 'per_page' => 2, 'page' => 2 ) ) );
		$revoked  = $this->service->search( LicenseQuery::from_input( array( 'status' => 'revoked' ) ) );
		$by_email = $this->service->search( LicenseQuery::from_input( array( 'search' => 'USER4' ) ) );
		$product  = $this->service->search( LicenseQuery::from_input( array( 'product_id' => '8' ) ) );

		$this->assertSame( 5, $all->total );
		$this->assertCount( 2, $all->items );
		$this->assertSame( array( 3, 2 ), array_map( static fn( $license ): int => $license->id, $all->items ) ); // Newest first, page 2.
		$this->assertSame( 1, $revoked->total );
		$this->assertSame( 'user4@example.com', $by_email->items[0]->email );
		$this->assertSame( 3, $product->total );
		$this->assertSame( array( 'active' => 4, 'expired' => 0, 'revoked' => 1, 'refunded' => 0 ), $this->service->status_counts() );
	}

	// Query input handling.

	public function test_query_input_is_clamped_and_whitelisted(): void {
		$query = LicenseQuery::from_input(
			array(
				'status'   => 'bogus',
				'orderby'  => 'id; DROP TABLE wp_users',
				'order'    => 'sideways',
				'page'     => '-4',
				'per_page' => '100000',
				'search'   => str_repeat( 'x', 500 ),
			)
		);

		$this->assertNull( $query->status );
		$this->assertSame( 'id', $query->orderby );
		$this->assertSame( 'DESC', $query->order );
		$this->assertSame( 1, $query->page );
		$this->assertSame( 200, $query->per_page );
		$this->assertSame( 100, strlen( $query->search ) );
		$this->assertSame( 0, $query->offset() );

		$valid = LicenseQuery::from_input( array( 'orderby' => 'expires_at', 'order' => 'ASC', 'page' => '3', 'per_page' => '10' ) );
		$this->assertSame( 'expires_at', $valid->orderby );
		$this->assertSame( 'ASC', $valid->order );
		$this->assertSame( 20, $valid->offset() );
	}

	// Searching (the SQL the real repository builds).

	public function test_the_sql_uses_prepared_values_and_whitelisted_sorting(): void {
		$db   = new FakeWpdb();
		$repo = new WpdbLicenseRepository( $db );

		$query          = LicenseQuery::from_input( array( 'status' => 'active', 'product_id' => '7', 'search' => "o'brien_100%", 'orderby' => 'expires_at', 'order' => 'asc', 'page' => '2', 'per_page' => '25' ) );
		$db->var        = '42';
		$page           = $repo->search( $query );
		$count_sql      = $db->queries[0];
		$rows_sql       = $db->queries[1];

		$this->assertSame( 42, $page->total );
		$this->assertStringContainsString( "status = 'active'", $count_sql );
		$this->assertStringContainsString( 'product_id = 7', $count_sql );
		// The quote is escaped for the string, and the LIKE wildcards (_ and %) are escaped for the pattern.
		$this->assertStringContainsString( 'LIKE \'%o\\\'brien\\\\_100\\\\%%\'', $count_sql );
		$this->assertStringContainsString( 'ORDER BY expires_at ASC, id DESC LIMIT 25 OFFSET 25', $rows_sql );
	}

	public function test_an_unfiltered_search_has_no_stray_conditions(): void {
		$db   = new FakeWpdb();
		$repo = new WpdbLicenseRepository( $db );

		$repo->search( new LicenseQuery() );

		$this->assertStringContainsString( 'WHERE 1=1 ORDER BY id DESC, id DESC LIMIT 20 OFFSET 0', $db->queries[1] );
		$this->assertStringNotContainsString( 'LIKE', $db->queries[0] );
	}

	public function test_a_hostile_orderby_can_never_reach_the_sql(): void {
		$db   = new FakeWpdb();
		$repo = new WpdbLicenseRepository( $db );

		$query          = new LicenseQuery();
		$query->orderby = 'id; DROP TABLE wp_users --'; // Bypasses from_input on purpose.
		$query->order   = 'DESC; DROP';
		$repo->search( $query );

		$this->assertStringNotContainsString( 'DROP', $db->queries[1] );
		$this->assertStringContainsString( 'ORDER BY id DESC, id DESC', $db->queries[1] );
	}

	public function test_status_counts_fill_in_missing_statuses(): void {
		$db       = new FakeWpdb();
		$db->rows = array( array( 'status' => 'active', 'total' => '12' ), array( 'status' => 'revoked', 'total' => '3' ), array( 'status' => 'mystery', 'total' => '9' ) );

		$counts = ( new WpdbLicenseRepository( $db ) )->status_counts();

		$this->assertSame( array( 'active' => 12, 'expired' => 0, 'revoked' => 3, 'refunded' => 0 ), $counts );
	}
}
