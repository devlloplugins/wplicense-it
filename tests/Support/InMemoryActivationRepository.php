<?php
/**
 * In-memory activation repository for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Licenses\Activation;
use Devllo\WPLicenseIt\Licenses\ActivationRepository;

/**
 * Keeps activations in an array.
 */
final class InMemoryActivationRepository implements ActivationRepository {

	/**
	 * Activations by ID.
	 *
	 * @var array<int, Activation>
	 */
	private array $rows = array();

	/**
	 * Last assigned ID.
	 *
	 * @var int
	 */
	private int $last_id = 0;

	public function find( int $license_id, string $site ): ?Activation {
		foreach ( $this->rows as $row ) {
			if ( $row->license_id === $license_id && $row->site === $site ) {
				return clone $row;
			}
		}

		return null;
	}

	public function count_active( int $license_id ): int {
		$count = 0;

		foreach ( $this->rows as $row ) {
			if ( $row->license_id === $license_id && $row->is_active() && ! $row->is_local ) {
				++$count;
			}
		}

		return $count;
	}

	public function save( Activation $activation ): Activation {
		if ( 0 === $activation->id ) {
			$activation->id = ++$this->last_id;
		}

		$this->rows[ $activation->id ] = clone $activation;

		return $activation;
	}

	public function with_license_lock( int $license_id, callable $callback ) {
		return $callback();
	}
}
