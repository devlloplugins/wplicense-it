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
	 * Whether this site is a local or staging site, which the server does not count as a used site.
	 *
	 * @var bool
	 */
	public $local = false;

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
	 * The site address the license was activated for (empty for licenses activated with an older copy of the SDK until
	 * the next successful check). When it differs from this site's address, the site was moved or copied.
	 *
	 * @var string
	 */
	public $site = '';

	/**
	 * The license server, so the site can be freed there when the plugin is deleted (when the SDK classes are not loaded).
	 *
	 * @var string
	 */
	public $server = '';

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
		$state->local        = ! empty( $data['local'] );
		$state->last_checked = isset( $data['last_checked'] ) ? (int) $data['last_checked'] : 0;
		$state->message      = isset( $data['message'] ) ? (string) $data['message'] : '';
		$state->site         = isset( $data['site'] ) ? (string) $data['site'] : '';
		$state->server       = isset( $data['server'] ) ? (string) $data['server'] : '';

		return $state;
	}

	/**
	 * A site address as the license server compares them: lower-case host without "www.", no scheme, no trailing slash.
	 *
	 * @param string $url Address.
	 */
	public static function same_site_key( string $url ): string {
		$url = strtolower( trim( $url ) );
		$url = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $url );
		$url = (string) preg_replace( '#^www\.#', '', $url );
		$url = (string) preg_replace( '#[?\#].*$#', '', $url );

		return rtrim( $url, '/' );
	}
}
