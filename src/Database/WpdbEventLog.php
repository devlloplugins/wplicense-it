<?php
/**
 * Audit log using $wpdb.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Database;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\EventLog;
use wpdb;

/**
 * Stores events in the wplit_license_events table.
 */
final class WpdbEventLog implements EventLog {

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database object.
	 */
	public function __construct( private wpdb $db ) {
		$this->table = Schema::table( $db->prefix, 'license_events' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $license_id License ID.
	 * @param string               $type       Event type.
	 * @param array<string, mixed> $data       Extra details.
	 */
	public function record( int $license_id, string $type, array $data = array() ): void {
		$this->db->insert(
			$this->table,
			array(
				'license_id' => $license_id,
				'type'       => $type,
				'data'       => array() === $data ? null : wp_json_encode( $data ),
				'created_at' => Dates::to_db( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Maximum events.
	 */
	public function for_license( int $license_id, int $limit = 50 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT type, data, created_at FROM {$this->table} WHERE license_id = %d ORDER BY id DESC LIMIT %d", $license_id, max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
			ARRAY_A
		);

		return $this->events_from_rows( $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $license_id License ID.
	 * @param string $type       Event type.
	 * @param int    $limit      Maximum events.
	 */
	public function of_type( int $license_id, string $type, int $limit = 20 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT type, data, created_at FROM {$this->table} WHERE license_id = %d AND type = %s ORDER BY id DESC LIMIT %d", $license_id, $type, max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
			ARRAY_A
		);

		return $this->events_from_rows( $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string            $type  Event type.
	 * @param DateTimeImmutable $since Earliest time (UTC).
	 * @param int               $limit Maximum events.
	 */
	public function since( string $type, DateTimeImmutable $since, int $limit = 20000 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT license_id, type, data, created_at FROM {$this->table} WHERE created_at >= %s AND type = %s ORDER BY created_at ASC, id ASC LIMIT %d", Dates::to_db( $since ), $type, max( 1, $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$events = array();
		foreach ( $this->events_from_rows( $rows ) as $i => $event ) {
			$events[] = array(
				'license_id' => (int) $rows[ $i ]['license_id'],
				'data'       => $event['data'],
				'created_at' => $event['created_at'],
			);
		}

		return $events;
	}

	/**
	 * Turns database rows into events.
	 *
	 * @param mixed $rows Rows from get_results().
	 * @return array<int, array{type:string,data:array<string,mixed>,created_at:DateTimeImmutable}>
	 */
	private function events_from_rows( $rows ): array {

		$events = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$data     = is_string( $row['data'] ) ? json_decode( $row['data'], true ) : array();
			$events[] = array(
				'type'       => (string) $row['type'],
				'data'       => is_array( $data ) ? $data : array(),
				'created_at' => Dates::from_db( $row['created_at'] ) ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			);
		}

		return $events;
	}

	/**
	 * Deletes events older than the given date. Meant for a daily job.
	 *
	 * It deletes in slices, so a first run on a big table does not hold one huge delete open. A backlog that is larger than the
	 * slices of one run continues the next day.
	 *
	 * @param DateTimeImmutable $before Delete events created before this time.
	 * @param int               $slice  Rows per delete.
	 * @param int               $slices Most deletes in one run.
	 * @return int Number of rows deleted.
	 */
	public function prune( DateTimeImmutable $before, int $slice = 5000, int $slices = 20 ): int {
		$total = 0;

		for ( $i = 0; $i < $slices; $i++ ) {
			$deleted = $this->db->query(
				$this->db->prepare( "DELETE FROM {$this->table} WHERE created_at < %s LIMIT %d", Dates::to_db( $before ), $slice ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
			);
			$deleted = is_int( $deleted ) ? $deleted : 0;
			$total  += $deleted;

			if ( $deleted < $slice ) {
				break;
			}
		}

		return $total;
	}
}
