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
	 * Deletes events older than the given date. Meant for a daily job.
	 *
	 * @param DateTimeImmutable $before Delete events created before this time.
	 * @return int Number of rows deleted.
	 */
	public function prune( DateTimeImmutable $before ): int {
		$deleted = $this->db->query(
			$this->db->prepare( "DELETE FROM {$this->table} WHERE created_at < %s", Dates::to_db( $before ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
}
