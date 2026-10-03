<?php
/**
 * Personal data storage using $wpdb.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Privacy;

use DateTimeImmutable;
use Devllo\WPLicenseIt\Database\Dates;
use Devllo\WPLicenseIt\Database\Schema;
use Devllo\WPLicenseIt\Database\WpdbActivationRepository;
use Devllo\WPLicenseIt\Database\WpdbEventLog;
use Devllo\WPLicenseIt\Database\WpdbLicenseRepository;
use wpdb;

/**
 * Finds and anonymises a person's data in the 2.0 tables and the 1.x tables that remain.
 */
final class WpdbPrivacyRepository implements PrivacyRepository {

	/**
	 * Licenses table.
	 *
	 * @var string
	 */
	private string $licenses;

	/**
	 * License orders table.
	 *
	 * @var string
	 */
	private string $orders;

	/**
	 * Events table.
	 *
	 * @var string
	 */
	private string $events_table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database object.
	 */
	public function __construct( private wpdb $db ) {
		$this->licenses     = Schema::table( $db->prefix, 'licenses' );
		$this->orders       = Schema::table( $db->prefix, 'license_orders' );
		$this->events_table = Schema::table( $db->prefix, 'license_events' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $email    Email address.
	 * @param int|null $user_id  WordPress user ID.
	 * @param int      $page     Page number.
	 * @param int      $per_page Licenses per page.
	 */
	public function licenses( string $email, ?int $user_id, int $page, int $per_page ): array {
		return ( new WpdbLicenseRepository( $this->db ) )->for_person( $email, $user_id, $per_page, ( max( 1, $page ) - 1 ) * $per_page );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $license_id License ID.
	 */
	public function activations( int $license_id ): array {
		return ( new WpdbActivationRepository( $this->db ) )->all_for_license( $license_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, \Devllo\WPLicenseIt\Licenses\License> $licenses Licenses on this page.
	 * @param int|null                                         $user_id  WordPress user ID.
	 */
	public function orders( array $licenses, ?int $user_id ): array {
		$ids = $this->order_ids( array_map( static fn( $license ): int => $license->id, $licenses ), $user_id );

		if ( array() === $ids ) {
			return array();
		}

		$rows = $this->db->get_results( "SELECT * FROM {$this->orders} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from the prefix, IDs cast to integers.

		$orders = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$billing = is_string( $row['legacy_billing'] ) ? json_decode( $row['legacy_billing'], true ) : array();

			$orders[] = array(
				'id'           => (int) $row['id'],
				'order_number' => (string) $row['order_number'],
				'source'       => (string) $row['source'],
				'currency'     => (string) $row['currency'],
				'total_minor'  => (int) $row['total_minor'],
				'status'       => (string) $row['status'],
				'created_at'   => (string) $row['created_at'],
				'billing'      => is_array( $billing ) ? array_map( 'strval', $billing ) : array(),
			);
		}

		return $orders;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Maximum events.
	 */
	public function events( int $license_id, int $limit ): array {
		$events = array();

		foreach ( ( new WpdbEventLog( $this->db ) )->for_license( $license_id, $limit ) as $event ) {
			$events[] = array(
				'type'       => $event['type'],
				'data'       => $event['data'],
				'created_at' => $event['created_at']->format( 'Y-m-d H:i:s' ),
			);
		}

		return $events;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $email   Email address.
	 * @param int|null $user_id WordPress user ID.
	 */
	public function legacy_rows( string $email, ?int $user_id ): array {
		$empty = array(
			'licenses' => array(),
			'orders'   => array(),
		);

		if ( ! $this->legacy_tables_exist() ) {
			return $empty;
		}

		$user = (int) $user_id;
		$lt   = $this->db->prefix . 'wplit_product_licenses';
		$ot   = $this->db->prefix . 'wplit_orders';

		$licenses = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$lt} WHERE email = %s OR (%d > 0 AND user_id = %d) ORDER BY id ASC", strtolower( $email ), $user, $user ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		$orders   = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$ot} WHERE order_email = %s OR (%d > 0 AND user_id = %d) ORDER BY id ASC", strtolower( $email ), $user, $user ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

		return array(
			'licenses' => is_array( $licenses ) ? $licenses : array(),
			'orders'   => is_array( $orders ) ? $orders : array(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $license_id License ID.
	 * @param string $anonymous  Placeholder email.
	 */
	public function anonymize_license( int $license_id, string $anonymous ): void {
		$this->db->update(
			$this->licenses,
			array(
				'email'      => $anonymous,
				'user_id'    => null,
				'updated_at' => Dates::to_db( new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ),
			),
			array( 'id' => $license_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		// History that recorded an old or new email address (an edit) loses it.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT id, data FROM {$this->events_table} WHERE license_id = %d AND data LIKE %s", $license_id, '%"email"%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$data = json_decode( (string) $row['data'], true );

			if ( is_array( $data ) ) {
				unset( $data['email'] );
				$this->db->update( $this->events_table, array( 'data' => array() === $data ? null : wp_json_encode( $data ) ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[]    $license_ids Licenses that were anonymised.
	 * @param int|null $user_id     WordPress user ID.
	 */
	public function anonymize_orders( array $license_ids, ?int $user_id ): int {
		$ids = $this->order_ids( $license_ids, $user_id );

		if ( array() === $ids ) {
			return 0;
		}

		$cleaned = $this->db->query( "UPDATE {$this->orders} SET legacy_billing = NULL, user_id = NULL WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from the prefix, IDs cast to integers.

		return is_int( $cleaned ) ? $cleaned : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $email   Email address.
	 * @param int|null $user_id WordPress user ID.
	 */
	public function anonymize_legacy( string $email, ?int $user_id ): int {
		if ( ! $this->legacy_tables_exist() ) {
			return 0;
		}

		$rows  = $this->legacy_rows( $email, $user_id );
		$count = 0;
		$lt    = $this->db->prefix . 'wplit_product_licenses';
		$ot    = $this->db->prefix . 'wplit_orders';

		foreach ( $rows['licenses'] as $row ) {
			$placeholder = PrivacyService::placeholder( (int) $row['id'] );

			if ( $placeholder !== $row['email'] || (int) $row['user_id'] > 0 ) {
				$this->db->update( $lt, array( 'email' => $placeholder, 'user_id' => 0 ), array( 'id' => (int) $row['id'] ), array( '%s', '%d' ), array( '%d' ) );
				++$count;
			}
		}

		foreach ( $rows['orders'] as $row ) {
			$this->db->update(
				$ot,
				array(
					'user_id'         => 0,
					'order_email'     => PrivacyService::placeholder( (int) $row['id'] ),
					'first_name'      => '',
					'last_name'       => '',
					'billing_company' => '',
					'billing_address' => '',
					'billing_state'   => '',
					'billing_city'    => '',
					'billing_country' => '',
					'billing_phone'   => '',
					'postal_code'     => '',
					'discount_code'   => '',
				),
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			++$count;
		}

		return $count;
	}

	/**
	 * IDs of the order records that belong to licenses or to a user.
	 *
	 * @param int[]    $license_ids License IDs.
	 * @param int|null $user_id     WordPress user ID.
	 * @return int[]
	 */
	private function order_ids( array $license_ids, ?int $user_id ): array {
		$ids = array();

		if ( array() !== $license_ids ) {
			$found = $this->db->get_col( "SELECT DISTINCT order_id FROM {$this->licenses} WHERE order_id IS NOT NULL AND id IN (" . implode( ',', array_map( 'intval', $license_ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from the prefix, IDs cast to integers.
			$ids   = array_map( 'intval', is_array( $found ) ? $found : array() );
		}

		if ( null !== $user_id && $user_id > 0 ) {
			$found = $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->orders} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
			$ids   = array_merge( $ids, array_map( 'intval', is_array( $found ) ? $found : array() ) );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether the 1.x tables are still there.
	 */
	private function legacy_tables_exist(): bool {
		$table = $this->db->prefix . 'wplit_product_licenses';

		return $table === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) )
			&& $this->db->prefix . 'wplit_orders' === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->db->prefix . 'wplit_orders' ) ) );
	}
}
