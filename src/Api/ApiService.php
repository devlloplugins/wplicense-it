<?php
/**
 * REST API logic.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

use DateTimeInterface;
use Devllo\WPLicenseIt\Licenses\ActivationResult;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\ValidationResult;

/**
 * The endpoints' behaviour, independent of WordPress: takes plain arrays, returns ApiResponse.
 *
 * Failed lookups are counted against the client, so license keys cannot be guessed.
 * A wrong product or email is reported as "not found", so the API does not reveal
 * whether a key exists.
 */
final class ApiService {

	public const DOWNLOAD_TTL = 900;

	/**
	 * Builds the download URL for a token.
	 *
	 * @var callable
	 */
	private $download_url;

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses     Licensing core.
	 * @param ProductCatalog $products     Product lookup.
	 * @param DownloadSigner $signer       Download token signer.
	 * @param RateLimiter    $limiter      Rate limiter.
	 * @param callable       $download_url Receives a token, returns the full download URL.
	 */
	public function __construct(
		private LicenseService $licenses,
		private ProductCatalog $products,
		private DownloadSigner $signer,
		private RateLimiter $limiter,
		callable $download_url
	) {
		$this->download_url = $download_url;
	}

	/**
	 * Checks a license.
	 *
	 * @param array<string, mixed> $params Request parameters: license_key, product_id, email (optional).
	 * @param string               $client Client identifier for rate limiting.
	 */
	public function validate( array $params, string $client ): ApiResponse {
		$blocked = $this->blocked( $client );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$input = $this->license_input( $params, true );
		if ( $input instanceof ApiResponse ) {
			return $input;
		}

		$email  = isset( $params['email'] ) && is_string( $params['email'] ) ? $params['email'] : null;
		$result = $this->licenses->validate( $input['license_key'], $input['product_id'], $email );

		$error = $this->validation_error( $result->code, $client );
		if ( null !== $error ) {
			return $error;
		}

		return new ApiResponse(
			200,
			array(
				'success' => true,
				'code'    => ValidationResult::OK,
				'license' => $this->license_body( $result->license ),
			)
		);
	}

	/**
	 * Activates a license on a site.
	 *
	 * @param array<string, mixed> $params Request parameters: license_key, product_id, site_url, product_version (optional).
	 * @param string               $client Client identifier for rate limiting.
	 */
	public function activate( array $params, string $client ): ApiResponse {
		$blocked = $this->blocked( $client );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$input = $this->license_input( $params, true );
		if ( $input instanceof ApiResponse ) {
			return $input;
		}

		$version = isset( $params['product_version'] ) && is_string( $params['product_version'] ) && '' !== $params['product_version']
			? substr( $params['product_version'], 0, 32 )
			: null;

		$result = $this->licenses->activate( $input['license_key'], $this->string_param( $params, 'site_url' ), $input['product_id'], $version );

		if ( ActivationResult::INVALID_SITE === $result->code ) {
			return ApiResponse::error( 400, 'invalid_site', 'A valid site_url is required.' );
		}

		if ( ActivationResult::LIMIT_REACHED === $result->code ) {
			return new ApiResponse(
				403,
				array(
					'success' => false,
					'code'    => ActivationResult::LIMIT_REACHED,
					'message' => 'This license has reached its activation limit. Deactivate it on another site first.',
					'license' => $this->license_body( $result->license ),
				)
			);
		}

		if ( ! $result->is_success() ) {
			return $this->validation_error( $result->code, $client ) ?? ApiResponse::error( 400, 'invalid_request', 'The request could not be processed.' );
		}

		return new ApiResponse(
			200,
			array(
				'success'    => true,
				'code'       => $result->code,
				'license'    => $this->license_body( $result->license ),
				'activation' => array(
					'site'   => $result->activation->site,
					'status' => $result->activation->status,
				),
			)
		);
	}

	/**
	 * Deactivates a license on a site.
	 *
	 * @param array<string, mixed> $params Request parameters: license_key, site_url.
	 * @param string               $client Client identifier for rate limiting.
	 */
	public function deactivate( array $params, string $client ): ApiResponse {
		$blocked = $this->blocked( $client );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$input = $this->license_input( $params, false );
		if ( $input instanceof ApiResponse ) {
			return $input;
		}

		$result = $this->licenses->deactivate( $input['license_key'], $this->string_param( $params, 'site_url' ) );

		if ( ActivationResult::INVALID_SITE === $result->code ) {
			return ApiResponse::error( 400, 'invalid_site', 'A valid site_url is required.' );
		}

		if ( ActivationResult::NOT_ACTIVE === $result->code ) {
			return ApiResponse::error( 404, 'not_active', 'This license is not active on that site.' );
		}

		if ( ValidationResult::NOT_FOUND === $result->code ) {
			return $this->validation_error( ValidationResult::NOT_FOUND, $client );
		}

		return new ApiResponse(
			200,
			array(
				'success' => true,
				'code'    => ActivationResult::DEACTIVATED,
				'license' => $this->license_body( $result->license ),
			)
		);
	}

	/**
	 * Update check: product details and a short-lived download link for valid licenses.
	 *
	 * @param array<string, mixed> $params Request parameters: license_key, product_id, current_version (optional).
	 * @param string               $client Client identifier for rate limiting.
	 */
	public function check_update( array $params, string $client ): ApiResponse {
		$blocked = $this->blocked( $client );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$input = $this->license_input( $params, true );
		if ( $input instanceof ApiResponse ) {
			return $input;
		}

		$result = $this->licenses->validate( $input['license_key'], $input['product_id'] );
		$error  = $this->validation_error( $result->code, $client );
		if ( null !== $error ) {
			return $error;
		}

		$product = $this->products->find( $input['product_id'] );
		if ( null === $product || ! $product->has_package ) {
			return ApiResponse::error( 404, 'no_package', 'No download is available for this product.' );
		}

		$current = isset( $params['current_version'] ) && is_string( $params['current_version'] ) ? $params['current_version'] : '';
		$token   = $this->signer->sign( $result->license->id, $product->id, self::DOWNLOAD_TTL );

		return new ApiResponse(
			200,
			array(
				'success'          => true,
				'product'          => array(
					'id'          => $product->id,
					'name'        => $product->name,
					'version'     => $product->version,
					'requires'    => $product->requires,
					'tested'      => $product->tested,
					'description' => $product->description,
					'banners'     => array(
						'low'  => $product->logo_url,
						'high' => $product->banner_url,
					),
				),
				'update_available' => '' === $current ? null : version_compare( $product->version, $current, '>' ),
				'download_url'     => ( $this->download_url )( $token ),
				'expires_in'       => self::DOWNLOAD_TTL,
			)
		);
	}

	/**
	 * Streams a package for a valid download token.
	 *
	 * @param string $token  Token from the download URL.
	 * @param string $client Client identifier for rate limiting.
	 */
	public function download( string $token, string $client ): ApiResponse {
		$blocked = $this->blocked( $client );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$claims = $this->signer->verify( $token );
		if ( null === $claims ) {
			$this->limiter->record_failure( $client );

			return ApiResponse::error( 403, 'invalid_token', 'This download link is invalid or has expired.' );
		}

		// The license may have been revoked or expired since the link was created.
		$license = $this->licenses->find( $claims['license_id'] );
		$result  = null === $license ? new ValidationResult( ValidationResult::NOT_FOUND ) : $this->licenses->validate( $license->license_key, $claims['product_id'] );
		$error   = $this->validation_error( $result->code, $client );
		if ( null !== $error ) {
			return $error;
		}

		$file = $this->products->package_file( $claims['product_id'] );
		if ( null === $file ) {
			return ApiResponse::error( 404, 'no_package', 'No download is available for this product.' );
		}

		return new ApiResponse( 200, array(), $file );
	}

	/**
	 * Rate-limit check.
	 *
	 * @param string $client Client identifier.
	 */
	private function blocked( string $client ): ?ApiResponse {
		$wait = $this->limiter->blocked_for( $client );

		if ( $wait <= 0 ) {
			return null;
		}

		$response              = ApiResponse::error( 429, 'rate_limited', 'Too many invalid requests. Try again later.' );
		$response->retry_after = $wait;

		return $response;
	}

	/**
	 * Reads and validates license_key and product_id.
	 *
	 * @param array<string, mixed> $params           Request parameters.
	 * @param bool                 $require_product  Whether product_id is required.
	 * @return array{license_key:string,product_id:int|null}|ApiResponse
	 */
	private function license_input( array $params, bool $require_product ) {
		$key = $this->string_param( $params, 'license_key' );
		if ( '' === $key || strlen( $key ) > 64 ) {
			return ApiResponse::error( 400, 'invalid_request', 'A license_key is required.' );
		}

		$product_id = null;
		if ( $require_product ) {
			$product_id = isset( $params['product_id'] ) && is_numeric( $params['product_id'] ) ? (int) $params['product_id'] : 0;
			if ( $product_id <= 0 ) {
				return ApiResponse::error( 400, 'invalid_request', 'A product_id is required.' );
			}
		}

		return array(
			'license_key' => $key,
			'product_id'  => $product_id,
		);
	}

	/**
	 * A trimmed string parameter, or an empty string.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @param string               $name   Parameter name.
	 */
	private function string_param( array $params, string $name ): string {
		return isset( $params[ $name ] ) && is_string( $params[ $name ] ) ? trim( $params[ $name ] ) : '';
	}

	/**
	 * Turns a failed validation into an error response (and counts the failure when it looks like guessing).
	 *
	 * @param string $code   ValidationResult code.
	 * @param string $client Client identifier.
	 * @return ApiResponse|null Null if the code is OK.
	 */
	private function validation_error( string $code, string $client ): ?ApiResponse {
		switch ( $code ) {
			case ValidationResult::OK:
				return null;

			case ValidationResult::EXPIRED:
				return ApiResponse::error( 403, 'expired', 'This license has expired.' );

			case ValidationResult::REVOKED:
				return ApiResponse::error( 403, 'revoked', 'This license has been revoked.' );

			case ValidationResult::REFUNDED:
				return ApiResponse::error( 403, 'refunded', 'This license was refunded.' );

			default:
				// Not found, wrong product or wrong email: all look the same from outside.
				$this->limiter->record_failure( $client );

				return ApiResponse::error( 404, 'not_found', 'License not found.' );
		}
	}

	/**
	 * Public view of a license.
	 *
	 * @param License|null $license License.
	 * @return array<string, mixed>
	 */
	private function license_body( ?License $license ): array {
		if ( null === $license ) {
			return array();
		}

		return array(
			'status'           => $license->status,
			'expires_at'       => null === $license->expires_at ? null : $license->expires_at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DateTimeInterface::ATOM ),
			'activation_limit' => $license->activation_limit,
			'activations_used' => $this->licenses->activations_used( $license ),
		);
	}
}
