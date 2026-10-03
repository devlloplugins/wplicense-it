<?php
/**
 * License service.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The licensing core: issues, renews, revokes, validates and activates licenses.
 *
 * It knows nothing about payments. Adapters (WooCommerce, free products, manual
 * orders) call issue_license(), renew_license() and revoke_license().
 */
final class LicenseService {

	private const KEY_ATTEMPTS = 5;

	/**
	 * Clock, returns the current time in UTC.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository    $licenses    License storage.
	 * @param ActivationRepository $activations Activation storage.
	 * @param EventLog             $events      Audit log.
	 * @param KeyGenerator         $keys        Key generator.
	 * @param callable|null        $clock       Returns a DateTimeImmutable, defaults to the current UTC time.
	 */
	public function __construct(
		private LicenseRepository $licenses,
		private ActivationRepository $activations,
		private EventLog $events,
		private KeyGenerator $keys,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Issues a new license.
	 *
	 * @param int                    $product_id       Post ID of the wplit_product.
	 * @param string                 $email            Customer email.
	 * @param int|null               $user_id          WordPress user ID, if any.
	 * @param int|null               $order_id         Order the license came from, if any.
	 * @param int                    $activation_limit Maximum active sites, 0 for unlimited.
	 * @param DateTimeImmutable|null $expires_at       Expiry, null for a lifetime license.
	 * @throws LicenseException If the input is invalid.
	 */
	public function issue_license(
		int $product_id,
		string $email,
		?int $user_id = null,
		?int $order_id = null,
		int $activation_limit = 1,
		?DateTimeImmutable $expires_at = null
	): License {
		$email = strtolower( trim( $email ) );

		if ( $product_id <= 0 ) {
			throw new LicenseException( LicenseException::INVALID_INPUT, 'A product is required.' );
		}
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			throw new LicenseException( LicenseException::INVALID_INPUT, 'A valid email is required.' );
		}
		if ( $activation_limit < 0 ) {
			throw new LicenseException( LicenseException::INVALID_INPUT, 'The activation limit cannot be negative.' );
		}

		$now = $this->now();

		$key = $this->unique_key();

		$license = $this->licenses->insert(
			new License(
				0,
				$product_id,
				$user_id,
				$order_id,
				$key,
				$email,
				Status::ACTIVE,
				$activation_limit,
				$expires_at,
				$now,
				$now
			)
		);

		$this->events->record(
			$license->id,
			EventLog::ISSUED,
			array(
				'product_id' => $product_id,
				'order_id'   => $order_id,
				'expires_at' => $this->format( $expires_at ),
			)
		);

		return $license;
	}

	/**
	 * Extends a license.
	 *
	 * The new period starts from the current expiry, or from now if the license
	 * has already expired. An expired license becomes active again. Lifetime
	 * licenses are returned unchanged.
	 *
	 * @param int          $license_id License ID.
	 * @param DateInterval $period     Period to add.
	 * @throws LicenseException If the license does not exist or was revoked or refunded.
	 */
	public function renew_license( int $license_id, DateInterval $period ): License {
		$license = $this->licenses->find( $license_id );

		if ( null === $license ) {
			throw new LicenseException( LicenseException::NOT_FOUND, 'License not found.' );
		}

		if ( in_array( $license->status, Status::terminal(), true ) ) {
			throw new LicenseException( LicenseException::NOT_RENEWABLE, 'A revoked or refunded license cannot be renewed.' );
		}

		if ( $license->is_lifetime() ) {
			return $license;
		}

		$now  = $this->now();
		$base = $license->is_past_expiry( $now ) ? $now : $license->expires_at;
		$new  = $base->add( $period );

		$renewed = $license->with_expiry( $new, $now )->with_status( Status::ACTIVE, $now );
		$this->licenses->update( $renewed );

		$this->events->record(
			$renewed->id,
			EventLog::RENEWED,
			array(
				'old_expires_at' => $this->format( $license->expires_at ),
				'new_expires_at' => $this->format( $new ),
			)
		);

		return $renewed;
	}

	/**
	 * Ends a license permanently.
	 *
	 * @param int    $license_id License ID.
	 * @param string $status     Status::REVOKED or Status::REFUNDED.
	 * @throws LicenseException If the status is not a terminal one or the license does not exist.
	 */
	public function revoke_license( int $license_id, string $status = Status::REVOKED ): License {
		if ( ! in_array( $status, Status::terminal(), true ) ) {
			throw new LicenseException( LicenseException::INVALID_INPUT, 'Status must be revoked or refunded.' );
		}

		$license = $this->licenses->find( $license_id );

		if ( null === $license ) {
			throw new LicenseException( LicenseException::NOT_FOUND, 'License not found.' );
		}

		if ( $license->status === $status ) {
			return $license;
		}

		$revoked = $license->with_status( $status, $this->now() );
		$this->licenses->update( $revoked );

		$this->events->record(
			$revoked->id,
			Status::REFUNDED === $status ? EventLog::REFUNDED : EventLog::REVOKED,
			array( 'previous_status' => $license->status )
		);

		return $revoked;
	}

	/**
	 * Finds a license by ID.
	 *
	 * @param int $license_id License ID.
	 */
	public function find( int $license_id ): ?License {
		return $this->licenses->find( $license_id );
	}

	/**
	 * A customer's licenses, newest first.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return License[]
	 */
	public function licenses_for_user( int $user_id ): array {
		return $this->licenses->find_by_user( $user_id );
	}

	/**
	 * The sites a license is currently active on.
	 *
	 * @param License $license License.
	 * @return Activation[]
	 */
	public function active_sites( License $license ): array {
		return $this->activations->list_active( $license->id );
	}

	/**
	 * Number of sites currently using a slot of the license.
	 *
	 * @param License $license License.
	 */
	public function activations_used( License $license ): int {
		return $this->activations->count_active( $license->id );
	}

	/**
	 * Checks a license key.
	 *
	 * @param string      $license_key License key.
	 * @param int|null    $product_id  If given, the license must be for this product.
	 * @param string|null $email       If given, the license must belong to this email.
	 */
	public function validate( string $license_key, ?int $product_id = null, ?string $email = null ): ValidationResult {
		$license_key = trim( $license_key );

		$license = '' === $license_key ? null : $this->licenses->find_by_key( $license_key );

		if ( null === $license || ! hash_equals( $license->license_key, $license_key ) ) {
			return new ValidationResult( ValidationResult::NOT_FOUND );
		}

		if ( null !== $product_id && $license->product_id !== $product_id ) {
			return new ValidationResult( ValidationResult::PRODUCT_MISMATCH, $license );
		}

		if ( null !== $email && strtolower( trim( $email ) ) !== $license->email ) {
			return new ValidationResult( ValidationResult::EMAIL_MISMATCH, $license );
		}

		if ( Status::REVOKED === $license->status ) {
			return new ValidationResult( ValidationResult::REVOKED, $license );
		}

		if ( Status::REFUNDED === $license->status ) {
			return new ValidationResult( ValidationResult::REFUNDED, $license );
		}

		if ( ! $license->is_usable( $this->now() ) ) {
			return new ValidationResult( ValidationResult::EXPIRED, $license );
		}

		return new ValidationResult( ValidationResult::OK, $license );
	}

	/**
	 * Activates a license on a site.
	 *
	 * Activating a site that is already active succeeds without using a new slot.
	 * Local and staging sites are recorded but do not count against the limit.
	 *
	 * @param string      $license_key     License key.
	 * @param string      $site_url        Site URL reported by the client.
	 * @param int|null    $product_id      If given, the license must be for this product.
	 * @param string|null $product_version Version reported by the client.
	 */
	public function activate( string $license_key, string $site_url, ?int $product_id = null, ?string $product_version = null ): ActivationResult {
		$validation = $this->validate( $license_key, $product_id );

		if ( ! $validation->is_valid() ) {
			return new ActivationResult( $validation->code, $validation->license );
		}

		$license = $validation->license;
		$site    = SiteNormalizer::normalize( $site_url );

		if ( null === $site ) {
			return new ActivationResult( ActivationResult::INVALID_SITE, $license );
		}

		return $this->activations->with_license_lock(
			$license->id,
			function () use ( $license, $site, $product_version ): ActivationResult {
				$now      = $this->now();
				$is_local = SiteNormalizer::is_local( $site );
				$existing = $this->activations->find( $license->id, $site );

				if ( null !== $existing && $existing->is_active() ) {
					$existing->last_checked_at  = $now;
					$existing->product_version = $product_version ?? $existing->product_version;
					$this->activations->save( $existing );

					return new ActivationResult( ActivationResult::ALREADY_ACTIVE, $license, $existing );
				}

				$limit = $license->activation_limit;
				if ( ! $is_local && $limit > 0 && $this->activations->count_active( $license->id ) >= $limit ) {
					return new ActivationResult( ActivationResult::LIMIT_REACHED, $license );
				}

				if ( null !== $existing ) {
					$existing->status          = Activation::ACTIVE;
					$existing->is_local        = $is_local;
					$existing->activated_at    = $now;
					$existing->deactivated_at  = null;
					$existing->last_checked_at = $now;
					$existing->product_version = $product_version ?? $existing->product_version;
					$activation                = $this->activations->save( $existing );
				} else {
					$activation = $this->activations->save(
						new Activation( 0, $license->id, $site, $is_local, Activation::ACTIVE, $product_version, $now, null, $now )
					);
				}

				$this->events->record( $license->id, EventLog::ACTIVATED, array( 'site' => $site ) );

				return new ActivationResult( ActivationResult::ACTIVATED, $license, $activation );
			}
		);
	}

	/**
	 * Deactivates a license on a site, freeing the slot.
	 *
	 * Deactivation works for expired licenses too, so customers can free slots.
	 *
	 * @param string $license_key License key.
	 * @param string $site_url    Site URL reported by the client.
	 */
	public function deactivate( string $license_key, string $site_url ): ActivationResult {
		$license_key = trim( $license_key );
		$license     = '' === $license_key ? null : $this->licenses->find_by_key( $license_key );

		if ( null === $license || ! hash_equals( $license->license_key, $license_key ) ) {
			return new ActivationResult( ValidationResult::NOT_FOUND );
		}

		$site = SiteNormalizer::normalize( $site_url );
		if ( null === $site ) {
			return new ActivationResult( ActivationResult::INVALID_SITE, $license );
		}

		return $this->activations->with_license_lock(
			$license->id,
			function () use ( $license, $site ): ActivationResult {
				$activation = $this->activations->find( $license->id, $site );

				if ( null === $activation || ! $activation->is_active() ) {
					return new ActivationResult( ActivationResult::NOT_ACTIVE, $license, $activation );
				}

				$now                         = $this->now();
				$activation->status          = Activation::DEACTIVATED;
				$activation->deactivated_at  = $now;
				$activation                  = $this->activations->save( $activation );

				$this->events->record( $license->id, EventLog::DEACTIVATED, array( 'site' => $site ) );

				return new ActivationResult( ActivationResult::DEACTIVATED, $license, $activation );
			}
		);
	}

	/**
	 * Marks active licenses whose expiry has passed as expired. Meant for a daily job.
	 *
	 * @param int $batch Maximum licenses to process in one call.
	 * @return int Number of licenses expired.
	 */
	public function expire_due_licenses( int $batch = 100 ): int {
		$now = $this->now();
		$due = $this->licenses->find_due_for_expiry( $now, $batch );

		foreach ( $due as $license ) {
			$this->licenses->update( $license->with_status( Status::EXPIRED, $now ) );
			$this->events->record( $license->id, EventLog::EXPIRED, array( 'expires_at' => $this->format( $license->expires_at ) ) );
		}

		return count( $due );
	}

	/**
	 * Current time in UTC.
	 */
	private function now(): DateTimeImmutable {
		return ( $this->clock )();
	}

	/**
	 * Formats a date for the audit log.
	 *
	 * @param DateTimeImmutable|null $date Date.
	 */
	private function format( ?DateTimeImmutable $date ): ?string {
		return null === $date ? null : $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Generates a key that is not in use yet.
	 *
	 * @throws LicenseException If no unused key could be generated.
	 */
	private function unique_key(): string {
		for ( $attempt = 0; $attempt < self::KEY_ATTEMPTS; $attempt++ ) {
			$key = $this->keys->generate();

			if ( null === $this->licenses->find_by_key( $key ) ) {
				return $key;
			}
		}

		throw new LicenseException( LicenseException::INVALID_INPUT, 'Could not generate a unique license key.' );
	}
}
