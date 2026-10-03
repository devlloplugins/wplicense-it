<?php
/**
 * Administrator edits to a license.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

use DateTimeImmutable;

/**
 * Which fields of a license an administrator wants to change. Fields left at their defaults are untouched.
 */
final class LicenseChanges {

	/**
	 * New sites-per-license limit, 0 for unlimited, null to leave it.
	 *
	 * @var int|null
	 */
	public ?int $activation_limit = null;

	/**
	 * Whether to change the expiry (needed because null is a valid new expiry: lifetime).
	 *
	 * @var bool
	 */
	public bool $change_expiry = false;

	/**
	 * New expiry in UTC, null for lifetime. Only used when $change_expiry is true.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $expires_at = null;

	/**
	 * New customer email, null to leave it.
	 *
	 * @var string|null
	 */
	public ?string $email = null;
}
