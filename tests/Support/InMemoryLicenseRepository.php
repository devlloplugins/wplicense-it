<?php
/**
 * In-memory license repository for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use DateTimeImmutable;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseRepository;
use Devllo\WPLicenseIt\Licenses\Status;

/**
 * Keeps licenses in an array.
 */
final class InMemoryLicenseRepository implements LicenseRepository {

	/**
	 * Licenses by ID.
	 *
	 * @var array<int, License>
	 */
	private array $rows = array();

	/**
	 * Last assigned ID.
	 *
	 * @var int
	 */
	private int $last_id = 0;

	public function insert( License $license ): License {
		$license->id          = ++$this->last_id;
		$this->rows[ $license->id ] = clone $license;

		return $license;
	}

	public function update( License $license ): void {
		$this->rows[ $license->id ] = clone $license;
	}

	public function find( int $id ): ?License {
		return isset( $this->rows[ $id ] ) ? clone $this->rows[ $id ] : null;
	}

	public function find_by_key( string $license_key ): ?License {
		foreach ( $this->rows as $row ) {
			if ( $row->license_key === $license_key ) {
				return clone $row;
			}
		}

		return null;
	}

	public function find_by_user( int $user_id ): array {
		$found = array_filter( $this->rows, static fn( License $row ): bool => $row->user_id === $user_id );

		return array_map( static fn( License $row ): License => clone $row, array_reverse( array_values( $found ) ) );
	}

	public function find_by_order( int $order_id ): array {
		$found = array_filter( $this->rows, static fn( License $row ): bool => $row->order_id === $order_id );

		return array_map( static fn( License $row ): License => clone $row, array_values( $found ) );
	}

	public function find_due_for_expiry( DateTimeImmutable $now, int $limit ): array {
		$due = array();

		foreach ( $this->rows as $row ) {
			if ( Status::ACTIVE === $row->status && $row->is_past_expiry( $now ) ) {
				$due[] = clone $row;
			}
		}

		return array_slice( $due, 0, $limit );
	}
}
