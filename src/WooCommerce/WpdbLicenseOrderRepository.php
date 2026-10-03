<?php
/**
 * License order records using $wpdb.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Database\Dates;
use Devllo\WPLicenseIt\Database\Schema;
use RuntimeException;
use wpdb;

/**
 * One row per WooCommerce order in wplit_license_orders (source "woocommerce").
 *
 * The table has a single product column, so an order with several licensed products
 * records the first one. Each license points to its order, so nothing is lost.
 */
final class WpdbLicenseOrderRepository implements LicenseOrderRepository {

	private const SOURCE = 'woocommerce';

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
		$this->table = Schema::table( $db->prefix, 'license_orders' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param OrderData $order Order.
	 * @throws RuntimeException If the row could not be saved.
	 */
	public function record( OrderData $order ): int {
		$existing = $this->find( $order->id );
		if ( null !== $existing ) {
			return $existing;
		}

		$product_id = 0;
		foreach ( $order->items as $item ) {
			if ( $item->wplit_product_id > 0 ) {
				$product_id = $item->wplit_product_id;
				break;
			}
		}

		$now = Dates::to_db( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

		$result = $this->db->insert(
			$this->table,
			array(
				'source'       => self::SOURCE,
				'external_id'  => (string) $order->id,
				'order_number' => 'WC-' . $order->number,
				'user_id'      => $order->user_id,
				'product_id'   => $product_id,
				'currency'     => strtoupper( substr( $order->currency, 0, 3 ) ),
				'total_minor'  => $order->total_minor,
				'status'       => 'completed',
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			// Another request may have inserted it a moment ago.
			$existing = $this->find( $order->id );
			if ( null !== $existing ) {
				return $existing;
			}

			throw new RuntimeException( 'Could not record the order: ' . $this->db->last_error );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $woo_order_id WooCommerce order ID.
	 * @param string $status       completed, refunded or cancelled.
	 */
	public function set_status( int $woo_order_id, string $status ): void {
		$this->db->update(
			$this->table,
			array(
				'status'     => $status,
				'updated_at' => Dates::to_db( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ),
			),
			array(
				'source'      => self::SOURCE,
				'external_id' => (string) $woo_order_id,
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Finds the record of a WooCommerce order.
	 *
	 * @param int $woo_order_id WooCommerce order ID.
	 * @return int|null Row ID.
	 */
	private function find( int $woo_order_id ): ?int {
		$id = $this->db->get_var(
			$this->db->prepare( "SELECT id FROM {$this->table} WHERE source = %s AND external_id = %s", self::SOURCE, (string) $woo_order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		);

		return null === $id ? null : (int) $id;
	}
}
