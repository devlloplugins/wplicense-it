<?php
/**
 * REST routes.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers /wp-json/wplicense-it/v1/ and hands requests to ApiService.
 *
 * The routes are public because the license key is the credential. State-changing
 * routes use POST so keys never end up in URLs and server logs.
 */
final class RestController {

	public const ROUTE_NAMESPACE = 'wplicense-it/v1';

	/**
	 * Constructor.
	 *
	 * @param ApiService $api API logic.
	 */
	public function __construct( private ApiService $api ) {
	}

	/**
	 * Registers the hooks. The routes themselves are registered on rest_api_init (see register_routes).
	 */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'document_errors' ), 10, 3 );
	}

	/**
	 * WordPress checks the body and the declared parameters before the callback runs, and answers a broken body or a missing
	 * or invalid parameter in its own shape ({code, message, data}). The API promises one shape for every failure, so those answers are rewritten.
	 *
	 * @param mixed           $result  Response.
	 * @param mixed           $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function document_errors( $result, $server, $request ) {
		unset( $server );

		if ( ! $result instanceof WP_REST_Response || 0 !== strpos( $request->get_route(), '/' . self::ROUTE_NAMESPACE . '/' ) ) {
			return $result;
		}

		$body = self::documented_error( $result->get_data(), $result->get_status() );

		if ( null !== $body ) {
			$result->set_data( $body );
			$result->header( 'Cache-Control', 'no-store' );
		}

		return $result;
	}

	/**
	 * The documented error body for one of WordPress's own parameter errors, or null for any other response.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status.
	 * @return array<string, mixed>|null
	 */
	public static function documented_error( $data, int $status ): ?array {
		if ( 400 !== $status || ! is_array( $data ) || ! isset( $data['code'] ) || ! in_array( $data['code'], array( 'rest_missing_callback_param', 'rest_invalid_param', 'rest_invalid_json' ), true ) ) {
			return null;
		}

		if ( 'rest_invalid_json' === $data['code'] ) {
			return array(
				'success' => false,
				'code'    => 'invalid_request',
				'message' => 'The request body is not valid JSON.',
			);
		}

		$names = array();
		if ( isset( $data['data']['params'] ) && is_array( $data['data']['params'] ) ) {
			$names = array_keys( 'rest_invalid_param' === $data['code'] ? $data['data']['params'] : array_flip( $data['data']['params'] ) );
		}

		$names = array_values( array_filter( array_map( 'strval', $names ), static fn( string $name ): bool => 1 === preg_match( '/^[a-z_]{1,32}$/', $name ) ) );

		return array(
			'success' => false,
			'code'    => 'invalid_request',
			'message' => 'rest_missing_callback_param' === $data['code']
				? 'A required parameter is missing: ' . implode( ', ', $names ) . '.'
				: 'A parameter is not valid: ' . implode( ', ', $names ) . '.',
		);
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$license_args = array(
			'license_key' => array(
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 64,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'product_id'  => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);

		$site_args = array(
			'site_url' => array(
				'type'              => 'string',
				'required'          => true,
				'maxLength'         => 255,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		$this->route(
			'/licenses/validate',
			'validate',
			$license_args + array(
				'email'    => array(
					'type'              => 'string',
					'maxLength'         => 190,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'site_url' => array(
					'type'              => 'string',
					'maxLength'         => 255,
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);

		$this->route(
			'/licenses/activate',
			'activate',
			$license_args + $site_args + array(
				'product_version' => array(
					'type'              => 'string',
					'maxLength'         => 32,
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);

		$deactivate_args                           = $license_args + $site_args;
		$deactivate_args['product_id']['required'] = false;
		$this->route( '/licenses/deactivate', 'deactivate', $deactivate_args );

		$this->route(
			'/updates/check',
			'check_update',
			$license_args + array(
				'current_version' => array(
					'type'              => 'string',
					'maxLength'         => 32,
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Registers one POST route backed by an ApiService method.
	 *
	 * @param string               $path   Route path.
	 * @param string               $method ApiService method name.
	 * @param array<string, mixed> $args   Argument definitions.
	 */
	private function route( string $path, string $method, array $args ): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			$path,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( WP_REST_Request $request ) use ( $method ): WP_REST_Response {
					try {
						return $this->respond( $this->api->$method( $request->get_params(), self::client_id() ) );
					} catch ( \Throwable $e ) {
						return $this->failed( $e );
					}
				},
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
	}

	/**
	 * A generic error for something unexpected (for example the database is down). Details go to the log,
	 * never to the client.
	 *
	 * @param \Throwable $error What went wrong.
	 */
	private function failed( \Throwable $error ): WP_REST_Response {
		error_log( 'WPLicense It API error: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operators need to see this.

		return $this->respond( ApiResponse::error( 500, 'server_error', 'The license server could not process the request. Try again later.' ) );
	}

	/**
	 * Download endpoint. Streams the file, or returns an error response.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function download( WP_REST_Request $request ): WP_REST_Response {
		try {
			$result = $this->api->download( (string) $request->get_param( 'token' ), self::client_id() );
		} catch ( \Throwable $e ) {
			return $this->failed( $e );
		}

		if ( null !== $result->file ) {
			FileStreamer::send( $result->file );
		}

		return $this->respond( $result );
	}

	/**
	 * Converts an ApiResponse to a WP_REST_Response.
	 *
	 * @param ApiResponse $result Result from ApiService.
	 */
	private function respond( ApiResponse $result ): WP_REST_Response {
		$response = new WP_REST_Response( $result->body, $result->status );
		$response->header( 'Cache-Control', 'no-store' );

		if ( null !== $result->retry_after ) {
			$response->header( 'Retry-After', (string) $result->retry_after );
		}

		return $response;
	}

	/**
	 * Identifies the client for rate limiting.
	 *
	 * Uses REMOTE_ADDR only. Sites behind a trusted proxy can supply the real address
	 * with the wplicense_it_client_ip filter.
	 */
	public static function client_id(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return (string) apply_filters( 'wplicense_it_client_ip', $ip );
	}
}
