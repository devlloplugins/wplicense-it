<?php
/**
 * HTTP transport using WordPress.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Uses wp_remote_post(), so proxies, SSL settings and the site's HTTP filters apply.
 */
final class WpTransport implements Transport {

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $body Fields.
	 */
	public function post( string $url, array $body ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => $response->get_error_message(),
			);
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'error'  => '',
		);
	}
}
