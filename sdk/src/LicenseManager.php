<?php
/**
 * License management.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Activates, deactivates and re-checks this site's license, and remembers the outcome.
 *
 * The rule that matters most: a license is only ever downgraded because the server said so.
 * No connection, a slow server or a rate limit never deactivates a customer.
 */
final class LicenseManager {

	public const REFRESH_INTERVAL = 86400;

	/**
	 * Server API.
	 *
	 * @var ServerApi
	 */
	private $api;

	/**
	 * Storage.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * This site's address.
	 *
	 * @var string
	 */
	private $site_url;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param ServerApi     $api      Server API.
	 * @param Store         $store    Storage.
	 * @param string        $site_url This site's address (home_url()).
	 * @param callable|null $clock    Optional, returns the current Unix time.
	 */
	public function __construct( ServerApi $api, Store $store, string $site_url, ?callable $clock = null ) {
		$this->api      = $api;
		$this->store    = $store;
		$this->site_url = $site_url;
		$this->clock    = $clock ?? 'time';
	}

	/**
	 * The stored state.
	 */
	public function state(): LicenseState {
		return $this->store->load();
	}

	/**
	 * Whether the license is active right now. A license whose expiry date has passed counts as
	 * inactive immediately, without waiting for the next check with the server.
	 */
	public function is_active(): bool {
		return $this->store->load()->is_active( $this->now() );
	}

	/**
	 * Activates a license key on this site.
	 *
	 * Nothing is stored unless the server accepts the key.
	 *
	 * @param string $key License key.
	 */
	public function activate( string $key ): ApiResult {
		$key = trim( $key );

		if ( '' === $key || strlen( $key ) > 64 ) {
			return new ApiResult( false, 'not_found', ServerApi::message_for( 'not_found', '' ) );
		}

		$result = $this->api->activate( $key, $this->site_url );

		if ( $result->ok ) {
			$state = new LicenseState();
			$this->apply_license( $state, $key, $result->data );
			$state->status       = LicenseState::ACTIVE;
			$state->last_checked = $this->now();
			$this->store->save( $state );
			$this->store->cache_delete( 'update' );
		}

		return $result;
	}

	/**
	 * Deactivates this site, freeing the slot on the server.
	 *
	 * @param bool $local_only Forget the license here even if the server cannot be reached.
	 */
	public function deactivate( bool $local_only = false ): ApiResult {
		$key = $this->store->load()->key;

		if ( '' === $key ) {
			return new ApiResult( true, 'deactivated', '' );
		}

		if ( $local_only ) {
			$this->store->clear();

			return new ApiResult( true, 'deactivated', '' );
		}

		$result = $this->api->deactivate( $key, $this->site_url );

		// "Not active" and "not found" mean the server no longer has this site: forget the key here too.
		if ( $result->ok || in_array( $result->code, array( 'not_active', 'not_found' ), true ) ) {
			$this->store->clear();

			return new ApiResult( true, 'deactivated', '' );
		}

		return $result;
	}

	/**
	 * Whether it is time to check with the server again.
	 */
	public function needs_refresh(): bool {
		$state = $this->store->load();

		return '' !== $state->key && $this->now() - $state->last_checked >= self::REFRESH_INTERVAL;
	}

	/**
	 * Checks the license with the server and updates what the site knows.
	 *
	 * @return ApiResult|null Null if there is no license to check.
	 */
	public function refresh(): ?ApiResult {
		$state = $this->store->load();

		if ( '' === $state->key ) {
			return null;
		}

		$result = $this->api->validate( $state->key, $this->site_url );

		if ( $result->is_temporary() ) {
			return $result; // Learned nothing: keep everything as it was.
		}

		$state->last_checked = $this->now();

		if ( $result->ok ) {
			$this->apply_license( $state, $state->key, $result->data );

			if ( isset( $result->data['site_active'] ) && false === $result->data['site_active'] ) {
				$state->status  = LicenseState::INACTIVE;
				$state->message = 'This site is no longer activated for the license. Activate it again to receive updates.';
			} else {
				$state->status  = LicenseState::ACTIVE;
				$state->message = '';
			}
		} else {
			$state->status  = $this->status_for_code( $result->code );
			$state->message = $result->message;

			// The key is kept, so a renewed license starts working again at the next check.
			if ( isset( $result->data['license'] ) && is_array( $result->data['license'] ) ) {
				$this->apply_license( $state, $state->key, $result->data );
			}
		}

		$this->store->save( $state );
		$this->store->cache_delete( 'update' );

		return $result;
	}

	/**
	 * Copies the license details from a server response into the state.
	 *
	 * @param LicenseState         $state State to update.
	 * @param string               $key   License key.
	 * @param array<string, mixed> $data  Server response.
	 */
	private function apply_license( LicenseState $state, string $key, array $data ): void {
		$state->key     = $key;
		$state->message = '';
		$license        = isset( $data['license'] ) && is_array( $data['license'] ) ? $data['license'] : array();

		if ( array_key_exists( 'expires_at', $license ) ) {
			$expires           = is_string( $license['expires_at'] ) ? strtotime( $license['expires_at'] ) : false;
			$state->expires_at = false === $expires ? null : $expires;
		}

		$state->limit = isset( $license['activation_limit'] ) ? (int) $license['activation_limit'] : $state->limit;
		$state->used  = isset( $license['activations_used'] ) ? (int) $license['activations_used'] : $state->used;
	}

	/**
	 * The status a definite server answer means.
	 *
	 * @param string $code Server code.
	 */
	private function status_for_code( string $code ): string {
		$map = array(
			'expired'  => LicenseState::EXPIRED,
			'revoked'  => LicenseState::REVOKED,
			'refunded' => LicenseState::REFUNDED,
		);

		return $map[ $code ] ?? LicenseState::INVALID;
	}

	/**
	 * Current Unix time.
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}
}
