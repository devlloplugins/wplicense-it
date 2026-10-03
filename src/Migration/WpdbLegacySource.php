<?php
/**
 * 1.x tables, read with $wpdb.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use wpdb;

/**
 * Reads wplit_product_licenses and wplit_orders. Read-only.
 */
final class WpdbLegacySource implements LegacySource {

	/**
	 * 1.x licenses table.
	 *
	 * @var string
	 */
	private string $licenses_table;

	/**
	 * 1.x orders table.
	 *
	 * @var string
	 */
	private string $orders_table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database object.
	 */
	public function __construct( private wpdb $db ) {
		$this->licenses_table = $db->prefix . 'wplit_product_licenses';
		$this->orders_table   = $db->prefix . 'wplit_orders';
	}

	/**
	 * Whether the 1.x tables exist.
	 */
	public function has_tables(): bool {
		return $this->table_exists( $this->licenses_table ) && $this->table_exists( $this->orders_table );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Maximum rows.
	 */
	public function licenses_after( int $after_id, int $limit ): array {
		return $this->rows_after( $this->licenses_table, $after_id, $limit );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit    Maximum rows.
	 */
	public function orders_after( int $after_id, int $limit ): array {
		return $this->rows_after( $this->orders_table, $after_id, $limit );
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_licenses(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->licenses_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_orders(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->orders_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $limit Maximum rows.
	 */
	public function sample_usable_licenses( int $limit ): array {
		// 1.x stored local time, so compare with the current local time.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->licenses_table} WHERE license_status = 'active' AND (valid_until = '0000-00-00 00:00:00' OR valid_until IS NULL OR valid_until > %s) ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
				current_time( 'mysql' ),
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows with an ID above the cursor.
	 *
	 * @param string $table    Table name.
	 * @param int    $after_id Cursor.
	 * @param int    $limit    Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_after( string $table, int $after_id, int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $after_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 */
	private function table_exists( string $table ): bool {
		return $table === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) );
	}
}
