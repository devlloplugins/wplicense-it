<?php
/**
 * Activation storage using $wpdb.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Database;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\Activation;
use Devllo\WPLicenseIt\Licenses\ActivationRepository;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * Stores activations in the wplit_activations table.
 */
final class WpdbActivationRepository implements ActivationRepository {

	/**
	 * Activations table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Licenses table, locked by with_license_lock().
	 *
	 * @var string
	 */
	private string $licenses_table;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database object.
	 */
	public function __construct( private wpdb $db ) {
		$this->table          = Schema::table( $db->prefix, 'activations' );
		$this->licenses_table = Schema::table( $db->prefix, 'licenses' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $license_id License ID.
	 * @param string $site       Normalised site.
	 */
	public function find( int $license_id, string $site ): ?Activation {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table} WHERE license_id = %d AND site = %s", $license_id, $site ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
			ARRAY_A
		);

		return is_array( $row ) ? $this->from_row( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $license_id License ID.
	 */
	public function count_active( int $license_id ): int {
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d AND status = %s AND is_local = 0", $license_id, Activation::ACTIVE ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Activation $activation Activation.
	 * @throws RuntimeException If the row could not be saved.
	 */
	public function save( Activation $activation ): Activation {
		$row     = array(
			'license_id'      => $activation->license_id,
			'site'            => $activation->site,
			'is_local'        => $activation->is_local ? 1 : 0,
			'status'          => $activation->status,
			'product_version' => $activation->product_version,
			'activated_at'    => Dates::to_db( $activation->activated_at ),
			'deactivated_at'  => Dates::to_db( $activation->deactivated_at ),
			'last_checked_at' => Dates::to_db( $activation->last_checked_at ),
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( 0 === $activation->id ) {
			$result = $this->db->insert( $this->table, $row, $formats );
			if ( false !== $result ) {
				$activation->id = (int) $this->db->insert_id;
			}
		} else {
			$result = $this->db->update( $this->table, $row, array( 'id' => $activation->id ), $formats, array( '%d' ) );
		}

		if ( false === $result ) {
			throw new RuntimeException( 'Could not save the activation: ' . $this->db->last_error );
		}

		return $activation;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int      $license_id License ID.
	 * @param callable $callback   Callback.
	 * @return mixed
	 * @throws Throwable Anything the callback throws, after rolling back.
	 */
	public function with_license_lock( int $license_id, callable $callback ) {
		$this->db->query( 'START TRANSACTION' );

		try {
			// Row lock on the license: concurrent activations of the same license wait here.
			$this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->licenses_table} WHERE id = %d FOR UPDATE", $license_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the prefix.

			$result = $callback();

			$this->db->query( 'COMMIT' );

			return $result;
		} catch ( Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Converts a database row to an activation.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function from_row( array $row ): Activation {
		return new Activation(
			(int) $row['id'],
			(int) $row['license_id'],
			(string) $row['site'],
			(bool) $row['is_local'],
			(string) $row['status'],
			null === $row['product_version'] ? null : (string) $row['product_version'],
			Dates::from_db( $row['activated_at'] ) ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			Dates::from_db( $row['deactivated_at'] ),
			Dates::from_db( $row['last_checked_at'] )
		);
	}
}
