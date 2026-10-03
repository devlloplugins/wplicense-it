<?php
/**
 * Update lookups.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Finds out what the latest version is, without asking the server more often than needed.
 */
final class UpdateService {

	private const CACHE_FOUND = 21600; // Six hours.
	private const CACHE_NONE  = 3600;  // One hour: do not hammer the server for a license that cannot update.

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
	 * Constructor.
	 *
	 * @param ServerApi $api   Server API.
	 * @param Store     $store Storage.
	 */
	public function __construct( ServerApi $api, Store $store ) {
		$this->api   = $api;
		$this->store = $store;
	}

	/**
	 * The latest version, if the license may receive updates.
	 *
	 * @param LicenseState $license License state.
	 * @param int          $now     Current Unix time.
	 * @param bool         $force   Ignore the cache.
	 */
	public function latest( LicenseState $license, int $now, bool $force = false ): ?UpdateInfo {
		if ( ! $license->is_active( $now ) ) {
			return null;
		}

		if ( ! $force ) {
			$cached = $this->store->cache_get( 'update' );

			if ( 'none' === $cached ) {
				return null;
			}

			$info = UpdateInfo::from_array( $cached );
			if ( null !== $info ) {
				return $info;
			}
		}

		$result = $this->api->check_update( $license->key );

		if ( ! $result->ok ) {
			// A definite "no" is remembered for a while. A temporary problem is not cached at all.
			if ( ! $result->is_temporary() ) {
				$this->store->cache_set( 'update', 'none', self::CACHE_NONE );
			}

			return null;
		}

		$info = UpdateInfo::from_response( $result->data );

		if ( null === $info ) {
			return null;
		}

		$this->store->cache_set( 'update', $info->to_array(), self::CACHE_FOUND );

		return $info;
	}

	/**
	 * A fresh download link. Never cached: links expire after 15 minutes.
	 *
	 * @param LicenseState $license License state.
	 * @return string|ApiResult The URL, or the failed result.
	 */
	public function download_url( LicenseState $license ) {
		$result = $this->api->check_update( $license->key );

		if ( ! $result->ok ) {
			return $result;
		}

		$url = isset( $result->data['download_url'] ) ? (string) $result->data['download_url'] : '';

		return '' === $url ? new ApiResult( false, 'no_package', ServerApi::message_for( 'no_package', '' ) ) : $url;
	}
}
