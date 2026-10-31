<?php
/**
 * Activation storage contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * Stores site activations.
 */
interface ActivationRepository {

	/**
	 * Finds the activation of a license on a site, in any status.
	 *
	 * @param int    $license_id License ID.
	 * @param string $site       Normalised site.
	 */
	public function find( int $license_id, string $site ): ?Activation;

	/**
	 * Counts active sites that count against the limit (local and staging sites do not).
	 *
	 * @param int $license_id License ID.
	 */
	public function count_active( int $license_id ): int;

	/**
	 * The sites a license is currently active on (including local and staging sites).
	 *
	 * @param int $license_id License ID.
	 * @return Activation[]
	 */
	public function list_active( int $license_id ): array;

	/**
	 * Saves an activation (insert if its ID is 0), and returns it with its ID set.
	 *
	 * @param Activation $activation Activation.
	 */
	public function save( Activation $activation ): Activation;

	/**
	 * Runs a callback while holding an exclusive lock on the license, so two
	 * simultaneous activations cannot both take the last slot.
	 *
	 * @param int      $license_id License ID.
	 * @param callable $callback   Callback, its return value is returned.
	 * @return mixed
	 */
	public function with_license_lock( int $license_id, callable $callback );
}
