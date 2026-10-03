<?php
/**
 * Result of a server call.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * What the license server answered, or why it could not be asked.
 */
final class ApiResult {

	public const NETWORK_ERROR = 'network_error';
	public const BAD_RESPONSE  = 'bad_response';
	public const SERVER_ERROR  = 'server_error';
	public const RATE_LIMITED  = 'rate_limited';

	/**
	 * Whether the server accepted the request.
	 *
	 * @var bool
	 */
	public $ok;

	/**
	 * The server's code (activated, expired, not_found, ...) or one of the constants above.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * A message fit for the administrator.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * The decoded response.
	 *
	 * @var array<string, mixed>
	 */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param bool                 $ok      Success.
	 * @param string               $code    Code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $data    Decoded response.
	 */
	public function __construct( bool $ok, string $code, string $message, array $data = array() ) {
		$this->ok      = $ok;
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * Whether the problem is temporary (no connection, server trouble, slow down), as opposed to the
	 * server giving a definite answer about the license. A temporary problem never changes a license.
	 */
	public function is_temporary(): bool {
		return ! $this->ok && in_array( $this->code, array( self::NETWORK_ERROR, self::BAD_RESPONSE, self::SERVER_ERROR, self::RATE_LIMITED ), true );
	}
}
