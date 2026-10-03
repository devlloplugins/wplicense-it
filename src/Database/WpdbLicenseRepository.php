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
