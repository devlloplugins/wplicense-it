<?php
/**
 * 1.x API compatibility.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\ValidationResult;

/**
 * Answers the deprecated /api/wplicense-it-api/v1/{info|get|status} requests the way 1.x did,
 * using the 2.0 licenses table, so plugins and themes that ship the 1.x updater keep working.
 *
 * Differences from 2.0 on purpose: the response shapes and the always-200 status codes are
 * unchanged, no site is recorded, and activation limits do not apply (1.x clients send no site).
 * Wrong keys are counted by the rate limiter.
 */
final class LegacyApi {

	/**
	 * Builds the 1.x package URL from the request parameters.
	 *
	 * @var callable
	 */
	private $package_url;

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses    Licensing core.
	 * @param ProductCatalog $products    Product lookup.
	 * @param RateLimiter    $limiter     Rate limiter.
	 * @param callable       $package_url Receives product_id, api key, email and license key, returns the URL.
	 */
	public function __construct(
		private LicenseService $licenses,
		private ProductCatalog $products,
		private RateLimiter $limiter,
		callable $package_url
	) {
		$this->package_url = $package_url;
	}

	/**
	 * Handles a 1.x request.
	 *
	 * @param string               $action One of info, get, status.
	 * @param array<string, mixed> $params Query variables p (product), e (email), l (license key), k (product API key).
	 * @param string               $client Client identifier for rate limiting.
	 */
	public function handle( string $action, array $params, string $client ): ApiResponse {
		if ( ! in_array( $action, array( 'info', 'get', 'status' ), true ) ) {
			return new ApiResponse( 200, array( 'error' => 'No such API action' ) );
		}

		if ( $this->limiter->blocked_for( $client ) > 0 ) {
			$response              = new ApiResponse( 429, array( 'error' => 'Too many invalid requests. Try again later.' ) );
			$response->retry_after = $this->limiter->blocked_for( $client );

			return $response;
		}

		$request = $this->parse( $params );
		if ( null === $request ) {
			return 'status' === $action ? new ApiResponse( 200, 'inactive' ) : new ApiResponse( 200, array( 'error' => 'Invalid request' ) );
		}

		list( $product_id, $email, $license_key, $api_key ) = $request;

		$product = $this->products->find( $product_id );
		if ( null === $product ) {
			$this->limiter->record_failure( $client );

			return 'status' === $action ? new ApiResponse( 200, 'inactive' ) : new ApiResponse( 200, array( 'error' => 'Product not found.' ) );
		}

		$product_key = $this->products->api_key( $product_id );
		$result      = $this->licenses->validate( $license_key, $product_id, $email );
		$valid       = '' !== $product_key && hash_equals( $product_key, $api_key ) && $result->is_valid();

		if ( ! $valid ) {
			if ( in_array( $result->code, array( ValidationResult::NOT_FOUND, ValidationResult::PRODUCT_MISMATCH, ValidationResult::EMAIL_MISMATCH ), true ) ) {
				$this->limiter->record_failure( $client );
			}

			return 'status' === $action ? new ApiResponse( 200, 'inactive' ) : new ApiResponse( 200, array( 'error' => 'Invalid license or license expired.' ) );
		}

		if ( 'status' === $action ) {
			return new ApiResponse( 200, 'active' );
		}

		if ( 'get' === $action ) {
			$file = $this->products->package_file( $product_id );

			return null === $file ? new ApiResponse( 404, array( 'error' => 'File not found.' ) ) : new ApiResponse( 200, array(), $file );
		}

		return new ApiResponse(
			200,
			array(
				'name'         => $product->name,
				'description'  => $product->description,
				'version'      => $product->version,
				'tested'       => $product->tested,
				'banner_low'   => $product->logo_url,
				'banner_high'  => $product->banner_url,
				'package_url'  => ( $this->package_url )( $product_id, $api_key, $email, $license_key ),
			)
		);
	}

	/**
	 * Reads the 1.x query variables.
	 *
	 * @param array<string, mixed> $params Query variables.
	 * @return array{0:int,1:string,2:string,3:string}|null
	 */
	private function parse( array $params ): ?array {
		foreach ( array( 'p', 'e', 'l', 'k' ) as $name ) {
			if ( ! isset( $params[ $name ] ) || ! is_scalar( $params[ $name ] ) || '' === (string) $params[ $name ] ) {
				return null;
			}
		}

		$product_id = (int) $params['p'];
		if ( $product_id <= 0 ) {
			return null;
		}

		return array( $product_id, trim( (string) $params['e'] ), trim( (string) $params['l'] ), trim( (string) $params['k'] ) );
	}
}
