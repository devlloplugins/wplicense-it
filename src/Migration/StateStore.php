<?php
/**
 * Migration state storage contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

/**
 * Remembers migration progress between requests.
 */
interface StateStore {

	/**
	 * Loads the state, or a fresh one.
	 */
	public function load(): MigrationState;

	/**
	 * Saves the state.
	 *
	 * @param MigrationState $state State.
	 */
	public function save( MigrationState $state ): void;
}
