<?php
/**
 * Audit log contract.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * Append-only audit log of license events.
 */
interface EventLog {

	public const ISSUED      = 'issued';
	public const ACTIVATED   = 'activated';
	public const DEACTIVATED = 'deactivated';
	public const RENEWED     = 'renewed';
	public const EXPIRED     = 'expired';
	public const REVOKED     = 'revoked';
	public const REFUNDED    = 'refunded';

	/**
	 * Records an event.
	 *
	 * @param int                  $license_id License ID.
	 * @param string               $type       One of the constants above.
	 * @param array<string, mixed> $data       Extra details (site, old and new expiry, ...).
	 */
	public function record( int $license_id, string $type, array $data = array() ): void;
}
