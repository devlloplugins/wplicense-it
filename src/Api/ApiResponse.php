<?php
/**
 * API response.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * What an API handler wants sent back, independent of WordPress.
 */
final class ApiResponse {

	/**
	 * Constructor.
	 *
	 * @param int         $status  HTTP status code.
	 * @param mixed       $body    Value to send as JSON.
	 * @param string|null $file    Absolute path of a file to stream instead of a JSON body.
	 * @param int|null    $retry_after Seconds to wait, for rate-limited responses.
	 */
	public function __construct(
		public int $status,
		public $body = array(),
		public ?string $file = null,
		public ?int $retry_after = null
	) {
	}

	/**
	 * Error response in the 2.0 format.
	 *
	 * @param int    $status  HTTP status code.
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 */
	public static function error( int $status, string $code, string $message ): self {
		return new self(
			$status,
			array(
				'success' => false,
				'code'    => $code,
				'message' => $message,
			)
		);
	}
}
