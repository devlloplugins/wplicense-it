<?php
/**
 * In-memory personal data storage for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Privacy\PrivacyRepository;

/**
 * Mirrors WpdbPrivacyRepository's rules on top of the in-memory license repository.
 */
final class InMemoryPrivacyRepository implements PrivacyRepository {

	/**
	 * Order records by ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $orders = array();

	/**
	 * Activations by license ID.
	 *
	 * @var array<int, \Devllo\WPLicenseIt\Licenses\Activation[]>
	 */
	public array $activations = array();

	/**
	 * Events by license ID.
	 *
	 * @var array<int, array<int, array{type:string,data:array<string,mixed>,created_at:string}>>
	 */
	public array $events = array();

	/**
	 * 1.x rows.
	 *
	 * @var array{licenses:array<int,array<string,mixed>>,orders:array<int,array<string,mixed>>}
	 */
	public array $legacy = array(
		'licenses' => array(),
		'orders'   => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param InMemoryLicenseRepository $repo Licenses.
	 */
	public function __construct( private InMemoryLicenseRepository $repo ) {
	}

	public function licenses( string $email, ?int $user_id, int $page, int $per_page ): array {
		$query           = new \Devllo\WPLicenseIt\Licenses\LicenseQuery();
		$query->per_page = 1000;
		$query->orderby  = 'id';
		$query->order    = 'ASC';

		$mine = array_values(
			array_filter(
				$this->repo->search( $query )->items,
				static fn( License $license ): bool => $license->email === strtolower( $email ) || ( null !== $user_id && $license->user_id === $user_id )
			)
		);

		return array_slice( $mine, ( $page - 1 ) * $per_page, $per_page );
	}

	public function activations( int $license_id ): array {
		return $this->activations[ $license_id ] ?? array();
	}

	public function orders( array $licenses, ?int $user_id ): array {
		$out = array();

		foreach ( $this->order_ids( array_map( static fn( License $license ): int => $license->id, $licenses ), $user_id ) as $id ) {
			$out[] = $this->orders[ $id ];
		}

		return $out;
	}

	public function events( int $license_id, int $limit ): array {
		return array_slice( $this->events[ $license_id ] ?? array(), 0, $limit );
	}

	public function legacy_rows( string $email, ?int $user_id ): array {
		$email = strtolower( $email );

		return array(
			'licenses' => array_values( array_filter( $this->legacy['licenses'], static fn( array $row ): bool => $row['email'] === $email || ( null !== $user_id && (int) $row['user_id'] === $user_id ) ) ),
			'orders'   => array_values( array_filter( $this->legacy['orders'], static fn( array $row ): bool => $row['order_email'] === $email || ( null !== $user_id && (int) $row['user_id'] === $user_id ) ) ),
		);
	}

	public function anonymize_license( int $license_id, string $anonymous ): void {
		$license          = $this->repo->find( $license_id );
		$license->email   = $anonymous;
		$license->user_id = null;
		$this->repo->update( $license );

		foreach ( $this->events[ $license_id ] ?? array() as $i => $event ) {
			unset( $this->events[ $license_id ][ $i ]['data']['email'] );
		}
	}

	public function anonymize_orders( array $license_ids, ?int $user_id ): int {
		$count = 0;

		foreach ( $this->order_ids( $license_ids, $user_id ) as $id ) {
			$this->orders[ $id ]['billing'] = array();
			$this->orders[ $id ]['user_id'] = null;
			++$count;
		}

		return $count;
	}

	public function anonymize_legacy( string $email, ?int $user_id ): int {
		$rows  = $this->legacy_rows( $email, $user_id );
		$count = 0;

		foreach ( $rows['licenses'] as $row ) {
			foreach ( $this->legacy['licenses'] as $i => $existing ) {
				if ( $existing['id'] === $row['id'] ) {
					$this->legacy['licenses'][ $i ]['email']   = 'erased-' . $row['id'] . '@erased.invalid';
					$this->legacy['licenses'][ $i ]['user_id'] = 0;
					++$count;
				}
			}
		}

		foreach ( $rows['orders'] as $row ) {
			foreach ( $this->legacy['orders'] as $i => $existing ) {
				if ( $existing['id'] === $row['id'] ) {
					$this->legacy['orders'][ $i ]['order_email']     = 'erased-' . $row['id'] . '@erased.invalid';
					$this->legacy['orders'][ $i ]['first_name']      = '';
					$this->legacy['orders'][ $i ]['billing_address'] = '';
					$this->legacy['orders'][ $i ]['user_id']         = 0;
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Order IDs of licenses and of a user.
	 *
	 * @param int[]    $license_ids License IDs.
	 * @param int|null $user_id     User ID.
	 * @return int[]
	 */
	private function order_ids( array $license_ids, ?int $user_id ): array {
		$ids = array();

		foreach ( $license_ids as $license_id ) {
			$license = $this->repo->find( $license_id );
			if ( $license && null !== $license->order_id ) {
				$ids[] = $license->order_id;
			}
		}

		foreach ( $this->orders as $id => $order ) {
			if ( null !== $user_id && $order['user_id'] === $user_id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
