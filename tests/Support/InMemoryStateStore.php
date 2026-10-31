<?php
/**
 * In-memory migration state for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Migration\MigrationState;
use Devllo\WPLicenseIt\Migration\StateStore;

/**
 * Stores the state as an array, like the option does, so (de)serialisation is exercised too.
 */
final class InMemoryStateStore implements StateStore {

	/**
	 * Stored state.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = array();

	/**
	 * Number of saves.
	 *
	 * @var int
	 */
	public int $saves = 0;

	public function load(): MigrationState {
		return MigrationState::from_array( $this->data );
	}

	public function save( MigrationState $state ): void {
		$this->data = $state->to_array();
		++$this->saves;
	}
}
