<?php
/**
 * Reachability probe over HTTP.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

/**
 * Requests the license API's route index from the server itself. The index lists the routes of the
 * wplicense-it/v1 namespace. It needs no license key and counts as no failed lookup, so it cannot lock anyone
 * out.
 */
final class RestLoopbackProbe implements ReachabilityProbe {

	public const NAMESPACE_NAME = 'wplicense-it/v1';

	public function probe(): array {
		$url      = rest_url( self::NAMESPACE_NAME );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 8,
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => 'This server could not reach its own license API (' . $response->get_error_message() . '). Some hosts block requests from the server to itself. If customers can open ' . $url . ' in a browser, this is only a warning.',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'ok'      => false,
				'message' => 'The license API address answered with HTTP ' . $code . '. A firewall, a security plugin or the host may be blocking it. Check ' . $url . ' in a browser.',
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ( $body['namespace'] ?? '' ) !== self::NAMESPACE_NAME ) {
			return array(
				'ok'      => false,
				'message' => 'The license API address answered, but not with the license API (it may be a login page or a security check). Check ' . $url . ' in a browser.',
			);
		}

		return array(
			'ok'      => true,
			'message' => 'The license API answers at ' . $url . '.',
		);
	}
}
