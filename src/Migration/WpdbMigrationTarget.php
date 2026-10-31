<?php
/**
 * 2.0 tables, written with $wpdb during migration.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use Devllo\WPLicenseIt\Database\Dates;
use Devllo\WPLicenseIt\Database\Schema;
use Devllo\WPLicenseIt\Licenses\License;
use RuntimeException;
use wpdb;

/**
 * Writes migrated rows to wplit_licenses and wplit_license_orders.
 */
final class WpdbMigrationTarget implements MigrationTarget {

	/**
	 * Licenses table.
	 *
	 * @var string
	 */
	private string $licenses;

	/**
	 * Orders table.
	 *
	 * @var string
	 */
	private string $orders;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database object.
	 */
	public function __construct( private wpdb $db ) {
		$this->licenses = Schema::table( $db->prefix, 'licenses' );
		$this->orders   = Schema::table( $db->prefix, 'license_orders' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param License $license   Converted license (ID 0).
	 * @param int     $legacy_id ID in the 1.x table.
	 * @throws RuntimeException If the row could not be saved.
	 */
	public function import_license( License $license, int $legacy_id ): string {
		$by_legacy = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->licenses} WHERE legacy_id = %d", $legacy_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		if ( null !== $by_legacy ) {
			return self::EXISTS;
		}

		$by_key = $this->db->get_row( $this->db->prepare( "SELECT id, legacy_id FROM {$this->licenses} WHERE license_key = %s", $license->license_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		if ( is_array( $by_key ) ) {
			if ( null !== $by_key['legacy_id'] ) {
				return self::CONFLICT;
			}

			// Issued by 2.0 while 1.x still kept its own copy: adopt it.
			$this->db->update( $this->licenses, array( 'legacy_id' => $legacy_id ), array( 'id' => (int) $by_key['id'] ), array( '%d' ), array( '%d' ) );

			return self::EXISTS;
		}

		$result = $this->db->insert(
			$this->licenses,
			array(
				'product_id'       => $license->product_id,
				'user_id'          => $license->user_id,
				'order_id'         => null,
				'license_key'      => $license->license_key,
				'email'            => $license->email,
				'status'           => $license->status,
				'activation_limit' => $license->activation_limit,
				'expires_at'       => Dates::to_db( $license->expires_at ),
				'legacy_id'        => $legacy_id,
				'created_at'       => Dates::to_db( $license->created_at ),
				'updated_at'       => Dates::to_db( $license->updated_at ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Could not migrate license ' . $legacy_id . ': ' . $this->db->last_error );
		}

		return self::CREATED;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param MigratedOrder $order Converted order.
	 * @throws RuntimeException If the row could not be saved.
	 */
	public function import_order( MigratedOrder $order ): string {
		$existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->orders} WHERE legacy_id = %d", $order->legacy_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		if ( null !== $existing ) {
			return self::EXISTS;
		}

		$result = $this->db->insert(
			$this->orders,
			array(
				'source'         => $order->source,
				'external_id'    => null,
				'order_number'   => $order->order_number,
				'user_id'        => $order->user_id,
				'product_id'     => $order->product_id,
				'currency'       => $order->currency,
				'total_minor'    => $order->total_minor,
				'status'         => $order->status,
				'legacy_id'      => $order->legacy_id,
				'legacy_billing' => $order->legacy_billing,
				'created_at'     => Dates::to_db( $order->created_at ),
				'updated_at'     => Dates::to_db( $order->updated_at ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Could not migrate order ' . $order->legacy_id . ': ' . $this->db->last_error );
		}

		$this->link_license( (int) $this->db->insert_id, $order );

		return self::CREATED;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $order_number Order number.
	 */
	public function order_number_taken( string $order_number ): bool {
		return null !== $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->orders} WHERE order_number = %s", $order_number ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_migrated_licenses(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->licenses} WHERE legacy_id IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
	}

	/**
	 * {@inheritDoc}
	 */
	public function count_migrated_orders(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->orders} WHERE legacy_id IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
	}

	/**
	 * Links an order to the migrated license of the same user and product that was created closest to it.
	 *
	 * @param int           $order_id New order ID.
	 * @param MigratedOrder $order    The order.
	 */
	private function link_license( int $order_id, MigratedOrder $order ): void {
		if ( null === $order->user_id ) {
			return;
		}

		$license_id = $this->db->get_var(
			$this->db->prepare(
				"SELECT id FROM {$this->licenses} WHERE user_id = %d AND product_id = %d AND order_id IS NULL AND legacy_id IS NOT NULL ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, %s)) ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
				$order->user_id,
				$order->product_id,
				Dates::to_db( $order->created_at )
			)
		);

		if ( null !== $license_id ) {
			$this->db->update( $this->licenses, array( 'order_id' => $order_id ), array( 'id' => (int) $license_id ), array( '%d' ), array( '%d' ) );
		}
	}
}
