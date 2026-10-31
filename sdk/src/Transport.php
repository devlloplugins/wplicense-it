<?php
/**
 * HTTP transport contract.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Sends a POST request. A seam, so the client can be tested without a network.
 */
interface Transport {

	/**
	 * Sends a form-encoded POST request.
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $body Fields.
	 * @return array{status:int,body:string,error:string} HTTP status (0 if the request failed), response body and an error message ('' if none).
	 */
	public function post( string $url, array $body ): array;
}
