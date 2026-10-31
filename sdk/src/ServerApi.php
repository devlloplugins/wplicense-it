<?php
/**
 * License server API.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Talks to the WPLicense It REST API (see docs/API.md on the server side).
 *
 * Only the license key, the product, the site address and the installed version are ever sent.
 */
final class ServerApi {

	private const NAMESPACE_PATH = 'wplicense-it/v1/';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param Config    $config    Configuration.
	 * @param Transport $transport Transport.
	 */
	public function __construct( Config $config, Transport $transport ) {
		$this->config    = $config;
		$this->transport = $transport;
	}

	/**
	 * The license server's address.
	 */
	public function server(): string {
		return $this->config->server;
	}

	/**
	 * Checks a license, and whether this site is still activated on it.
	 *
	 * @param string $key      License key.
	 * @param string $site_url This site's address.
	 */
	public function validate( string $key, string $site_url ): ApiResult {
		return $this->request(
			'licenses/validate',
			array(
				'license_key' => $key,
				'product_id'  => $this->config->product_id,
				'site_url'    => $site_url,
			)
		);
	}

	/**
	 * Activates the license on this site.
	 *
	 * @param string $key      License key.
	 * @param string $site_url This site's address.
	 */
	public function activate( string $key, string $site_url ): ApiResult {
		return $this->request(
			'licenses/activate',
			array(
				'license_key'     => $key,
				'product_id'      => $this->config->product_id,
				'site_url'        => $site_url,
				'product_version' => $this->config->version,
			)
		);
	}

	/**
	 * Deactivates the license on this site.
	 *
	 * @param string $key      License key.
	 * @param string $site_url This site's address.
	 */
	public function deactivate( string $key, string $site_url ): ApiResult {
		return $this->request(
			'licenses/deactivate',
			array(
				'license_key' => $key,
				'site_url'    => $site_url,
			)
		);
	}

	/**
	 * Asks for the latest version and a short-lived download link.
	 *
	 * @param string $key License key.
	 */
	public function check_update( string $key ): ApiResult {
		return $this->request(
			'updates/check',
			array(
				'license_key'     => $key,
				'product_id'      => $this->config->product_id,
				'current_version' => $this->config->version,
			)
		);
	}

	/**
	 * Sends a request and turns the answer into an ApiResult.
	 *
	 * @param string               $path Route below wplicense-it/v1/.
	 * @param array<string, mixed> $body Fields.
	 */
	private function request( string $path, array $body ): ApiResult {
		$response = $this->transport->post( $this->config->server . '/wp-json/' . self::NAMESPACE_PATH . $path, $body );

		// Sites without pretty permalinks (or with /wp-json blocked) serve the API from ?rest_route=.
		if ( 404 === $response['status'] && null === $this->decode( $response['body'] ) ) {
			$response = $this->transport->post( $this->config->server . '/?rest_route=/' . self::NAMESPACE_PATH . $path, $body );
		}

		if ( 0 === $response['status'] ) {
			return new ApiResult( false, ApiResult::NETWORK_ERROR, __( 'Could not reach the license server.', 'wplicense-it-client' ) . ' ' . $response['error'] );
		}

		$data = $this->decode( $response['body'] );

		if ( $response['status'] >= 500 ) {
			return new ApiResult( false, ApiResult::SERVER_ERROR, __( 'The license server had a problem. Try again later.', 'wplicense-it-client' ) );
		}

		if ( null === $data ) {
			return new ApiResult( false, ApiResult::BAD_RESPONSE, __( 'The license server sent an answer this plugin does not understand.', 'wplicense-it-client' ) );
		}

		if ( 429 === $response['status'] ) {
			return new ApiResult( false, ApiResult::RATE_LIMITED, __( 'Too many requests. Try again in a few minutes.', 'wplicense-it-client' ), $data );
		}

		if ( $response['status'] >= 200 && $response['status'] < 300 && ! empty( $data['success'] ) ) {
			return new ApiResult( true, isset( $data['code'] ) ? (string) $data['code'] : 'ok', '', $data );
		}

		$code    = isset( $data['code'] ) ? (string) $data['code'] : 'error';
		$message = self::message_for( $code, isset( $data['message'] ) ? (string) $data['message'] : '' );

		// The server lists the sites that use the license, so the customer knows which one to free.
		if ( 'limit_reached' === $code && isset( $data['sites'] ) && is_array( $data['sites'] ) && array() !== $data['sites'] ) {
			$message .= ' ' . sprintf( /* translators: %s: list of site addresses. */ __( 'It is active on: %s.', 'wplicense-it-client' ), implode( ', ', array_map( 'strval', array_slice( $data['sites'], 0, 20 ) ) ) );

			if ( ! empty( $data['account_url'] ) && is_string( $data['account_url'] ) ) {
				$message .= ' ' . sprintf( /* translators: %s: address of the customer's account. */ __( 'Free a site in your account: %s', 'wplicense-it-client' ), $data['account_url'] );
			}
		}

		return new ApiResult( false, $code, $message, $data );
	}

	/**
	 * Decodes a JSON object, or null.
	 *
	 * @param string $body Response body.
	 * @return array<string, mixed>|null
	 */
	private function decode( string $body ): ?array {
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * A message for a server code. The server's own message is used when there is no better one here.
	 *
	 * @param string $code   Server code.
	 * @param string $server Message from the server.
	 */
	public static function message_for( string $code, string $server ): string {
		$messages = array(
			'not_found'     => __( 'This license key was not found. Check it for typing mistakes.', 'wplicense-it-client' ),
			'expired'       => __( 'This license has expired. Renew it to keep receiving updates.', 'wplicense-it-client' ),
			'revoked'       => __( 'This license has been revoked.', 'wplicense-it-client' ),
			'refunded'      => __( 'This license was refunded.', 'wplicense-it-client' ),
			'limit_reached' => __( 'This license has reached its site limit. Deactivate it on another site first.', 'wplicense-it-client' ),
			'invalid_site'  => __( 'This site\'s address could not be used for activation.', 'wplicense-it-client' ),
			'not_active'    => __( 'This license is not active on this site.', 'wplicense-it-client' ),
			'no_package'    => __( 'No download is available for this product yet.', 'wplicense-it-client' ),
		);

		return $messages[ $code ] ?? ( '' !== $server ? $server : __( 'The license server could not process the request.', 'wplicense-it-client' ) );
	}
}
