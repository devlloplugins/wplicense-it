<?php
/**
 * Fake HTTP transport for the client SDK tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use WPLicenseIt\Client\Transport;

/**
 * Replays queued responses and records the requests.
 */
final class SdkFakeTransport implements Transport {

	/**
	 * Requests made: URL and body.
	 *
	 * @var array<int, array{url:string,body:array<string,mixed>}>
	 */
	public $requests = array();

	/**
	 * Responses still to be replayed.
	 *
	 * @var array<int, array{status:int,body:string,error:string}>
	 */
	private $queue = array();

	/**
	 * Queues a JSON response.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $body   Body, JSON encoded.
	 */
	public function reply( int $status, array $body ): self {
		$this->queue[] = array(
			'status' => $status,
			'body'   => (string) json_encode( $body ),
			'error'  => '',
		);

		return $this;
	}

	/**
	 * Queues a raw response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 */
	public function reply_raw( int $status, string $body ): self {
		$this->queue[] = array(
			'status' => $status,
			'body'   => $body,
			'error'  => '',
		);

		return $this;
	}

	/**
	 * Queues a connection failure.
	 */
	public function fail( string $error = 'cURL error 28: timed out' ): self {
		$this->queue[] = array(
			'status' => 0,
			'body'   => '',
			'error'  => $error,
		);

		return $this;
	}

	public function post( string $url, array $body ): array {
		$this->requests[] = array(
			'url'  => $url,
			'body' => $body,
		);

		return array_shift( $this->queue ) ?? array(
			'status' => 0,
			'body'   => '',
			'error'  => 'no response queued',
		);
	}

	/**
	 * The last request.
	 *
	 * @return array{url:string,body:array<string,mixed>}
	 */
	public function last(): array {
		return $this->requests[ count( $this->requests ) - 1 ];
	}
}
