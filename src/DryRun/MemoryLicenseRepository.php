<?php
/**
 * In-memory license repository.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\DryRun;

use DateTimeImmutable;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicensePage;
use Devllo\WPLicenseIt\Licenses\LicenseQuery;
use Devllo\WPLicenseIt\Licenses\LicenseRepository;
use Devllo\WPLicenseIt\Licenses\Status;

/**
 * Keeps licenses in an array. Nothing is written to the database, so it is used by the test purchase dry run.
 */
final class MemoryLicenseRepository implements LicenseRepository {

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

	/**
	 * {@inheritdoc}
	 */
	public function insert( License $license ): License {
		$license->id                = ++$this->last_id;
		$this->rows[ $license->id ] = clone $license;

		return $license;
	}

	/**
	 * {@inheritdoc}
	 */
	public function update( License $license ): void {
		$this->rows[ $license->id ] = clone $license;
	}

	/**
	 * {@inheritdoc}
	 */
	public function find( int $id ): ?License {
		return isset( $this->rows[ $id ] ) ? clone $this->rows[ $id ] : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function find_by_key( string $license_key ): ?License {
		foreach ( $this->rows as $row ) {
			if ( $row->license_key === $license_key ) {
				return clone $row;
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function find_by_user( int $user_id ): array {
		$found = array_filter( $this->rows, static fn( License $row ): bool => $row->user_id === $user_id );

		return array_map( static fn( License $row ): License => clone $row, array_reverse( array_values( $found ) ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function find_by_order( int $order_id ): array {
		$found = array_filter( $this->rows, static fn( License $row ): bool => $row->order_id === $order_id );

		return array_map( static fn( License $row ): License => clone $row, array_values( $found ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function search( LicenseQuery $query ): LicensePage {
		$found = array_filter(
			$this->rows,
			static function ( License $row ) use ( $query ): bool {
				return ( null === $query->status || $row->status === $query->status )
					&& ( null === $query->product_id || $row->product_id === $query->product_id )
					&& ( '' === $query->search || false !== stripos( $row->license_key . ' ' . $row->email, $query->search ) );
			}
		);

		$found = array_values( $found );
		usort(
			$found,
			static function ( License $a, License $b ) use ( $query ): int {
				$result = $a->{$query->orderby} <=> $b->{$query->orderby};

				return 'ASC' === $query->order ? $result : -$result;
			}
		);

		return new LicensePage(
			array_map( static fn( License $row ): License => clone $row, array_slice( $found, $query->offset(), $query->per_page ) ),
			count( $found )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function status_counts(): array {
		$counts = array_fill_keys( \Devllo\WPLicenseIt\Licenses\Status::all(), 0 );

		foreach ( $this->rows as $row ) {
			++$counts[ $row->status ];
		}

		return $counts;
	}

	/**
	 * {@inheritdoc}
	 */
	public function find_due_for_expiry( DateTimeImmutable $now, int $limit ): array {
		$due = array();

		foreach ( $this->rows as $row ) {
			if ( Status::ACTIVE === $row->status && $row->is_past_expiry( $now ) ) {
				$due[] = clone $row;
			}
		}

		return array_slice( $due, 0, $limit );
	}

	/**
	 * {@inheritdoc}
	 */
	public function find_expiring( DateTimeImmutable $from, DateTimeImmutable $to, ?License $after, int $limit, string $status = Status::ACTIVE ): array {
		$found = array();

		foreach ( $this->rows as $row ) {
			if ( $status !== $row->status || null === $row->expires_at || $row->expires_at <= $from || $row->expires_at > $to ) {
				continue;
			}

			if ( null !== $after && null !== $after->expires_at && ( $row->expires_at < $after->expires_at || ( $row->expires_at == $after->expires_at && $row->id <= $after->id ) ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Dates are compared by value.
				continue;
			}

			$found[] = clone $row;
		}

		usort( $found, static fn( License $a, License $b ): int => array( $a->expires_at, $a->id ) <=> array( $b->expires_at, $b->id ) );

		return array_slice( $found, 0, $limit );
	}

	/**
	 * {@inheritdoc}
	 */
	public function count_expiring( DateTimeImmutable $from, DateTimeImmutable $to, string $status ): int {
		return count( $this->find_expiring( $from, $to, null, PHP_INT_MAX, $status ) );
	}
}
