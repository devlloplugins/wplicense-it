<?php
/**
 * Activation value object.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

use DateTimeImmutable;

/**
 * One site that a license is (or was) activated on.
 */
final class Activation {

	public const ACTIVE      = 'active';
	public const DEACTIVATED = 'deactivated';

	/**
	 * Constructor.
	 *
	 * @param int                    $id              Row ID, 0 if not saved yet.
	 * @param int                    $license_id      License ID.
	 * @param string                 $site            Normalised site (see SiteNormalizer).
	 * @param bool                   $is_local        Local or staging site, which does not count against the limit.
	 * @param string                 $status          ACTIVE or DEACTIVATED.
	 * @param string|null            $product_version Version last reported by the client.
	 * @param DateTimeImmutable      $activated_at    First or latest activation, UTC.
	 * @param DateTimeImmutable|null $deactivated_at  Deactivation time, UTC.
	 * @param DateTimeImmutable|null $last_checked_at Last status or update check, UTC.
	 */
	public function __construct(
		public int $id,
		public int $license_id,
		public string $site,
		public bool $is_local,
		public string $status,
		public ?string $product_version,
		public DateTimeImmutable $activated_at,
		public ?DateTimeImmutable $deactivated_at,
		public ?DateTimeImmutable $last_checked_at
	) {
	}

	/**
	 * Whether the site is currently activated.
	 */
	public function is_active(): bool {
		return self::ACTIVE === $this->status;
	}
}
