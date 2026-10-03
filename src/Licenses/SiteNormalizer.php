<?php
/**
 * Site URL normalisation.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * Turns the site URL reported by a client into the form stored in the activations table.
 *
 * Lower-case host, no scheme, no "www.", no query or fragment, no trailing slash.
 * A path is kept, so example.com/blog and example.com are different installs.
 */
final class SiteNormalizer {

	private const MAX_LENGTH = 190;

	/**
	 * Normalises a site URL.
	 *
	 * @param string $url Site URL as reported by the client.
	 * @return string|null Normalised site, or null if the URL has no usable host.
	 */
	public static function normalize( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		if ( false === strpos( $url, '://' ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}

		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress dependency in this class.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$host = strtolower( $parts['host'] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		if ( '' === $host || ! preg_match( '/^[a-z0-9.\-\[\]:]+$/', $host ) ) {
			return null;
		}

		$site = $host;

		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
			$site .= ':' . (int) $parts['port'];
		}

		if ( isset( $parts['path'] ) ) {
			$path = rtrim( $parts['path'], '/' );
			if ( '' !== $path ) {
				$site .= $path;
			}
		}

		if ( strlen( $site ) > self::MAX_LENGTH ) {
			return null;
		}

		return $site;
	}

	/**
	 * Whether a normalised site is a local or staging install, which does not count against the limit.
	 *
	 * @param string $site Normalised site.
	 */
	public static function is_local( string $site ): bool {
		$host = strtolower( (string) preg_replace( '#[:/].*$#', '', $site ) );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) {
			return true;
		}

		foreach ( array( '.local', '.localhost', '.test', '.invalid', '.example' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		foreach ( array( 'staging.', 'stage.', 'dev.' ) as $prefix ) {
			if ( 0 === strpos( $host, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
