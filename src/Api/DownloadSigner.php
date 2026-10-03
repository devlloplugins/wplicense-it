<?php
/**
 * Signed download tokens.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Creates and checks short-lived download tokens, so license keys never appear in download URLs.
 */
final class DownloadSigner {

	/**
	 * Constructor.
	 *
	 * @param string        $secret Signing secret.
	 * @param callable|null $clock  Returns the current Unix time, defaults to time().
	 */
	public function __construct(
		private string $secret,
		private $clock = null
	) {
	}

	/**
	 * Creates a token.
	 *
	 * @param int $license_id License ID.
	 * @param int $product_id Product ID.
	 * @param int $ttl        Seconds the token stays valid.
	 */
	public function sign( int $license_id, int $product_id, int $ttl ): string {
		$data    = array(
			'l' => $license_id,
			'p' => $product_id,
			'e' => $this->now() + $ttl,
		);
		$payload = self::encode( (string) json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress dependency in this class.

		return $payload . '.' . self::encode( hash_hmac( 'sha256', $payload, $this->secret, true ) );
	}

	/**
	 * Checks a token.
	 *
	 * @param string $token Token from a download URL.
	 * @return array{license_id:int,product_id:int}|null The IDs if the token is genuine and not expired.
	 */
	public function verify( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$expected = self::encode( hash_hmac( 'sha256', $parts[0], $this->secret, true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}

		$data = json_decode( (string) self::decode( $parts[0] ), true );
		if ( ! is_array( $data ) || ! isset( $data['l'], $data['p'], $data['e'] ) || (int) $data['e'] < $this->now() ) {
			return null;
		}

		return array(
			'license_id' => (int) $data['l'],
			'product_id' => (int) $data['p'],
		);
	}

	/**
	 * Current Unix time.
	 */
	private function now(): int {
		return null === $this->clock ? time() : (int) ( $this->clock )();
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $value Raw value.
	 */
	private static function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Not obfuscation, URL-safe token encoding.
	}

	/**
	 * Reverses encode().
	 *
	 * @param string $value Encoded value.
	 * @return string|false
	 */
	private static function decode( string $value ) {
		return base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Not obfuscation, URL-safe token decoding.
	}
}
