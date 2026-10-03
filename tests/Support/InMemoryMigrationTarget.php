<?php
/**
 * In-memory 2.0 target for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Migration\MigratedOrder;
use Devllo\WPLicenseIt\Migration\MigrationTarget;

/**
 * Mirrors WpdbMigrationTarget's rules on top of the in-memory license repository, which
 * the LicenseService under test also uses, so verification sees the migrated licenses.
 */
final class InMemoryMigrationTarget implements MigrationTarget {

	/**
	 * Legacy license ID to new license ID.
	 *
	 * @var array<int, int>
	 */
	public array $license_legacy = array();

	/**
	 * Migrated orders by new ID.
	 *
	 * @var array<int, MigratedOrder>
	 */
	public array $orders = array();

	/**
	 * Constructor.
	 *
	 * @param InMemoryLicenseRepository $repo License repository shared with the service.
	 */
	public function __construct( private InMemoryLicenseRepository $repo ) {
	}

	public function import_license( License $license, int $legacy_id ): string {
		if ( isset( $this->license_legacy[ $legacy_id ] ) ) {
			return self::EXISTS;
		}

		$by_key = $this->repo->find_by_key( $license->license_key );
		if ( null !== $by_key ) {
			if ( in_array( $by_key->id, $this->license_legacy, true ) ) {
				return self::CONFLICT;
			}

			$this->license_legacy[ $legacy_id ] = $by_key->id;

			return self::EXISTS;
		}

		$saved                              = $this->repo->insert( $license );
		$this->license_legacy[ $legacy_id ] = $saved->id;

		return self::CREATED;
	}

	public function import_order( MigratedOrder $order ): string {
		foreach ( $this->orders as $existing ) {
			if ( $existing->legacy_id === $order->legacy_id ) {
				return self::EXISTS;
			}
		}

		$id                  = count( $this->orders ) + 1;
		$this->orders[ $id ] = clone $order;

		$this->link( $id, $order );

		return self::CREATED;
	}

	public function order_number_taken( string $order_number ): bool {
		foreach ( $this->orders as $existing ) {
			if ( $existing->order_number === $order_number ) {
				return true;
			}
		}

		return false;
	}

	public function count_migrated_licenses(): int {
		return count( $this->license_legacy );
	}

	public function count_migrated_orders(): int {
		return count( $this->orders );
	}

	/**
	 * Links an order to the closest migrated license of the same user and product.
	 *
	 * @param int           $order_id Order ID.
	 * @param MigratedOrder $order    Order.
	 */
	private function link( int $order_id, MigratedOrder $order ): void {
		if ( null === $order->user_id ) {
			return;
		}

		$best      = null;
		$best_diff = PHP_INT_MAX;

		foreach ( $this->license_legacy as $license_id ) {
			$license = $this->repo->find( $license_id );
			if ( null === $license || $license->user_id !== $order->user_id || $license->product_id !== $order->product_id || null !== $license->order_id ) {
				continue;
			}

			$diff = abs( $license->created_at->getTimestamp() - $order->created_at->getTimestamp() );
			if ( $diff < $best_diff ) {
				$best      = $license;
				$best_diff = $diff;
			}
		}

		if ( null !== $best ) {
			$best->order_id = $order_id;
			$this->repo->update( $best );
		}
	}
}
