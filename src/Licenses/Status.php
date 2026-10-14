<?php
/**
 * License statuses.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * The statuses a license can have.
 */
final class Status {

	public const ACTIVE   = 'active';
	public const EXPIRED  = 'expired';
	public const REVOKED  = 'revoked';
	public const REFUNDED = 'refunded';

	/**
	 * All valid statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::ACTIVE, self::EXPIRED, self::REVOKED, self::REFUNDED );
	}

	/**
	 * Statuses that end a license for good (it cannot be renewed back to life).
	 *
	 * @return string[]
	 */
	public static function terminal(): array {
		return array( self::REVOKED, self::REFUNDED );
	}
}
