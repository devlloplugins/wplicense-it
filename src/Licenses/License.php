<?php
/**
 * License value object.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

use DateTimeImmutable;

/**
 * A license. Treat instances as immutable: the with_* methods return changed copies.
 */
final class License {

	/**
	 * Constructor.
	 *
	 * @param int                    $id               Row ID, 0 if not saved yet.
	 * @param int                    $product_id       Post ID of the wplit_product.
	 * @param int|null               $user_id          WordPress user, if any.
	 * @param int|null               $order_id         Row in the license orders table, if any.
	 * @param string                 $license_key      The license key.
	 * @param string                 $email            Lower-cased customer email.
	 * @param string                 $status           One of Status::all().
	 * @param int                    $activation_limit Maximum active sites, 0 for unlimited.
	 * @param DateTimeImmutable|null $expires_at       Expiry in UTC, null for a lifetime license.
	 * @param DateTimeImmutable      $created_at       Created, UTC.
	 * @param DateTimeImmutable      $updated_at       Last change, UTC.
	 */
	public function __construct(
		public int $id,
		public int $product_id,
		public ?int $user_id,
		public ?int $order_id,
		public string $license_key,
		public string $email,
		public string $status,
		public int $activation_limit,
		public ?DateTimeImmutable $expires_at,
		public DateTimeImmutable $created_at,
		public DateTimeImmutable $updated_at
	) {
	}

	/**
	 * Whether the license never expires.
	 */
	public function is_lifetime(): bool {
		return null === $this->expires_at;
	}

	/**
	 * Whether the expiry date has passed.
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	public function is_past_expiry( DateTimeImmutable $now ): bool {
		return null !== $this->expires_at && $this->expires_at <= $now;
	}

	/**
	 * Whether the license can be used right now.
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	public function is_usable( DateTimeImmutable $now ): bool {
		return Status::ACTIVE === $this->status && ! $this->is_past_expiry( $now );
	}

	/**
	 * Copy with a new status.
	 *
	 * @param string            $status New status.
	 * @param DateTimeImmutable $now    Current time.
	 */
	public function with_status( string $status, DateTimeImmutable $now ): self {
		$copy             = clone $this;
		$copy->status     = $status;
		$copy->updated_at = $now;

		return $copy;
	}

	/**
	 * Copy with a new expiry.
	 *
	 * @param DateTimeImmutable|null $expires_at New expiry, null for lifetime.
	 * @param DateTimeImmutable      $now        Current time.
	 */
	public function with_expiry( ?DateTimeImmutable $expires_at, DateTimeImmutable $now ): self {
		$copy             = clone $this;
		$copy->expires_at = $expires_at;
		$copy->updated_at = $now;

		return $copy;
	}
}
