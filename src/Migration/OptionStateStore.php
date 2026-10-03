<?php
/**
 * Migration state in a WordPress option.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

/**
 * Stores the migration state in the wplit_migration_state option.
 */
final class OptionStateStore implements StateStore {

	public const OPTION = 'wplit_migration_state';

	/**
	 * {@inheritDoc}
	 */
	public function load(): MigrationState {
		return MigrationState::from_array( get_option( self::OPTION, array() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param MigrationState $state State.
	 */
	public function save( MigrationState $state ): void {
		update_option( self::OPTION, $state->to_array(), false );
	}
}
