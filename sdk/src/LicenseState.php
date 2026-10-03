<?php
/**
 * Stored license state.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * What the site remembers about its license.
 */
final class LicenseState {

	public const INACTIVE = 'inactive';
	public const ACTIVE   = 'active';
	public const EXPIRED  = 'expired';
	public const REVOKED  = 'revoked';
	public const REFUNDED = 'refunded';
	public const INVALID  = 'invalid';

	/**
	 * The license key, empty if none.
	 *
	 * @var string
	 */
	public $key = '';

	/**
	 * One of the status constants.
	 *
	 * @var string
	 */
	public $status = self::INACTIVE;

	/**
	 * Expiry as a Unix timestamp, null for a lifetime license.
	 *
	 * @var int|null
	 */
	public $expires_at = null;

	/**
	 * Sites allowed, 0 for unlimited.
	 *
	 * @var int
	 */
	public $limit = 0;

	/**
	 * Sites in use.
	 *
	 * @var int
	 */
	public $used = 0;

	/**
	 * Unix time of the last successful check with the server.
	 *
	 * @var int
	 */
	public $last_checked = 0;

	/**
	 * A message for the administrator, for example why the license is not active.
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Whether the license can be used at the given time, by what the site last learned.
	 *
	 * @param int $now Current Unix time.
	 */
	public function is_active( int $now ): bool {
		return self::ACTIVE === $this->status && '' !== $this->key && ( null === $this->expires_at || $this->expires_at > $now );
	}

	/**
	 * Array form for storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Rebuilds a state from stored data.
	 *
	 * @param mixed $data Stored array.
	 */
	public static function from_array( $data ): self {
		$state = new self();

		if ( ! is_array( $data ) ) {
			return $state;
		}

		$state->key          = isset( $data['key'] ) ? (string) $data['key'] : '';
		$state->status       = isset( $data['status'] ) ? (string) $data['status'] : self::INACTIVE;
		$state->expires_at   = isset( $data['expires_at'] ) && null !== $data['expires_at'] ? (int) $data['expires_at'] : null;
		$state->limit        = isset( $data['limit'] ) ? (int) $data['limit'] : 0;
		$state->used         = isset( $data['used'] ) ? (int) $data['used'] : 0;
		$state->last_checked = isset( $data['last_checked'] ) ? (int) $data['last_checked'] : 0;
		$state->message      = isset( $data['message'] ) ? (string) $data['message'] : '';

		return $state;
	}
}
