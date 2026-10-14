<?php
/**
 * License storage using $wpdb.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Database;

use DateTimeImmutable;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicensePage;
use Devllo\WPLicenseIt\Licenses\LicenseQuery;
use Devllo\WPLicenseIt\Licenses\LicenseRepository;
use Devllo\WPLicenseIt\Licenses\Status;
use RuntimeException;
use wpdb;

/**
 * Stores licenses in the wplit_licenses table.
 */
final class WpdbLicenseRepository implements LicenseRepository {

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
		$this->table = Schema::table( $db->prefix, 'licenses' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param License $license License with ID 0.
	 * @throws RuntimeException If the row could not be saved.
	 */
	public function insert( License $license ): License {
		$result = $this->db->insert( $this->table, $this->to_row( $license ), $this->formats() );

		if ( false === $result ) {
			throw new RuntimeException( 'Could not save the license: ' . $this->db->last_error );
		}

		$license->id = (int) $this->db->insert_id;

		return $license;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param License $license License with an ID.
	 * @throws RuntimeException If the row could not be saved.
	 */
	public function update( License $license ): void {
		$result = $this->db->update( $this->table, $this->to_row( $license ), array( 'id' => $license->id ), $this->formats(), array( '%d' ) );

		if ( false === $result ) {
			throw new RuntimeException( 'Could not update the license: ' . $this->db->last_error );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id License ID.
	 */
	public function find( int $id ): ?License {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

		return is_array( $row ) ? $this->from_row( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $license_key License key.
	 */
	public function find_by_key( string $license_key ): ?License {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE license_key = %s", $license_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

		return is_array( $row ) ? $this->from_row( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function find_by_user( int $user_id ): array {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY id DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

		return array_map( array( $this, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id Row ID in the license orders table.
	 */
	public function find_by_order( int $order_id ): array {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

		return array_map( array( $this, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Licenses that belong to a person: issued to their email address or linked to their account.
	 *
	 * @param string   $email   Email address.
	 * @param int|null $user_id WordPress user ID, if any.
	 * @param int      $limit   Maximum licenses.
	 * @param int      $offset  Licenses to skip.
	 * @return License[]
	 */
	public function for_person( string $email, ?int $user_id, int $limit, int $offset ): array {
		if ( null !== $user_id && $user_id > 0 ) {
			$sql = $this->db->prepare( "SELECT * FROM {$this->table} WHERE email = %s OR user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", strtolower( $email ), $user_id, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		} else {
			$sql = $this->db->prepare( "SELECT * FROM {$this->table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", strtolower( $email ), $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		}

		$rows = $this->db->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param LicenseQuery $query Filters, sort order and page.
	 */
	public function search( LicenseQuery $query ): LicensePage {
		$where = array( '1=1' );
		$args  = array();

		if ( null !== $query->status ) {
			$where[] = 'status = %s';
			$args[]  = $query->status;
		}

		if ( null !== $query->product_id ) {
			$where[] = 'product_id = %d';
			$args[]  = $query->product_id;
		}

		if ( '' !== $query->search ) {
			$like    = '%' . $this->db->esc_like( $query->search ) . '%';
			$where[] = '(license_key LIKE %s OR email LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		$clause = implode( ' AND ', $where );

		// Column and direction come from whitelists, never from the request.
		$orderby = in_array( $query->orderby, LicenseQuery::ORDERBY, true ) ? $query->orderby : 'id';
		$order   = 'ASC' === $query->order ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$clause}";
		$total     = (int) $this->db->get_var( array() === $args ? $count_sql : $this->db->prepare( $count_sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clause is built from fixed fragments, values are prepared.

		$rows_sql = "SELECT * FROM {$this->table} WHERE {$clause} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";
		$rows     = $this->db->get_results( $this->db->prepare( $rows_sql, array_merge( $args, array( $query->per_page, $query->offset() ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clause is built from fixed fragments, values are prepared.

		return new LicensePage( array_map( array( $this, 'from_row' ), is_array( $rows ) ? $rows : array() ), $total );
	}

	/**
	 * {@inheritDoc}
	 */
	public function status_counts(): array {
		$counts = array_fill_keys( Status::all(), 0 );
		$rows   = $this->db->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeImmutable $now   Current time.
	 * @param int               $limit Maximum number to return.
	 */
	public function find_due_for_expiry( DateTimeImmutable $now, int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY expires_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
				Status::ACTIVE,
				Dates::to_db( $now ),
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Column formats, in the order of to_row().
	 *
	 * @return string[]
	 */
	private function formats(): array {
		return array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );
	}

	/**
	 * Converts a license to a database row (without the ID).
	 *
	 * @param License $license License.
	 * @return array<string, mixed>
	 */
	private function to_row( License $license ): array {
		return array(
			'product_id'       => $license->product_id,
			'user_id'          => $license->user_id,
			'order_id'         => $license->order_id,
			'license_key'      => $license->license_key,
			'email'            => $license->email,
			'status'           => $license->status,
			'activation_limit' => $license->activation_limit,
			'expires_at'       => Dates::to_db( $license->expires_at ),
			'created_at'       => Dates::to_db( $license->created_at ),
			'updated_at'       => Dates::to_db( $license->updated_at ),
		);
	}

	/**
	 * Converts a database row to a license.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function from_row( array $row ): License {
		return new License(
			(int) $row['id'],
			(int) $row['product_id'],
			null === $row['user_id'] ? null : (int) $row['user_id'],
			null === $row['order_id'] ? null : (int) $row['order_id'],
			(string) $row['license_key'],
			(string) $row['email'],
			(string) $row['status'],
			(int) $row['activation_limit'],
			Dates::from_db( $row['expires_at'] ),
			Dates::from_db( $row['created_at'] ) ?? new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ),
			Dates::from_db( $row['updated_at'] ) ?? new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) )
		);
	}
}
