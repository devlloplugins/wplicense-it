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
	public const REINSTATED  = 'reinstated';
	public const UPDATED     = 'updated';

	/**
	 * Records an event.
	 *
	 * @param int                  $license_id License ID.
	 * @param string               $type       One of the constants above.
	 * @param array<string, mixed> $data       Extra details (site, old and new expiry, ...).
	 */
	public function record( int $license_id, string $type, array $data = array() ): void;

	/**
	 * Recent events of a license, newest first.
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Maximum events.
	 * @return array<int, array{type:string,data:array<string,mixed>,created_at:\DateTimeImmutable}>
	 */
	public function for_license( int $license_id, int $limit = 50 ): array;
}
