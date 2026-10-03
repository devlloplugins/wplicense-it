<?php
/**
 * Tests for the REST API logic.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Api\ApiService;
use Devllo\WPLicenseIt\Api\DownloadSigner;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Tests\Support\FakeProductCatalog;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryRateLimiter;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Api\ApiService
 * @covers \Devllo\WPLicenseIt\Api\ApiResponse
 */
final class ApiServiceTest extends TestCase {

	private const CLIENT = '203.0.113.9';

	private ApiService $api;
	private LicenseService $licenses;
	private FixedClock $clock;
	private InMemoryRateLimiter $limiter;
	private FakeProductCatalog $products;
	private DownloadSigner $signer;
	private int $now = 1800000000;

	protected function setUp(): void {
		$this->clock    = new FixedClock( '2026-01-01 00:00:00' );
		$this->licenses = new LicenseService( new InMemoryLicenseRepository(), new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), $this->clock );
		$this->limiter  = new InMemoryRateLimiter( 3 );
		$this->products = new FakeProductCatalog();
		$this->signer   = new DownloadSigner( 'secret', fn(): int => $this->now );
		$this->api      = new ApiService(
			$this->licenses,
			$this->products,
			$this->signer,
			$this->limiter,
			static fn( string $token ): string => 'https://shop.test/download?token=' . $token
		);
	}

	private function license( int $limit = 1, ?string $expires = null ): License {
		$expiry = null === $expires ? null : new \DateTimeImmutable( $expires, new \DateTimeZone( 'UTC' ) );

		return $this->licenses->issue_license( 7, 'buyer@example.com', null, null, $limit, $expiry );
	}

	// Validate.

	public function test_validate_returns_the_license(): void {
		$license = $this->license( 2, '2027-01-01 00:00:00' );

		$response = $this->api->validate( array( 'license_key' => $license->license_key, 'product_id' => 7 ), self::CLIENT );

		$this->assertSame( 200, $response->status );
		$this->assertTrue( $response->body['success'] );
		$this->assertSame( 'active', $response->body['license']['status'] );
		$this->assertSame( '2027-01-01T00:00:00+00:00', $response->body['license']['expires_at'] );
		$this->assertSame( 2, $response->body['license']['activation_limit'] );
		$this->assertSame( 0, $response->body['license']['activations_used'] );
	}

	public function test_validate_rejects_malformed_requests(): void {
		foreach (
			array(
				array(),
				array( 'license_key' => '', 'product_id' => 7 ),
				array( 'license_key' => 'abc' ),
				array( 'license_key' => 'abc', 'product_id' => 'x' ),
				array( 'license_key' => 'abc', 'product_id' => 0 ),
				array( 'license_key' => str_repeat( 'a', 65 ), 'product_id' => 7 ),
				array( 'license_key' => array( 'a' ), 'product_id' => 7 ),
			) as $params
		) {
			$response = $this->api->validate( $params, self::CLIENT );
			$this->assertSame( 400, $response->status );
			$this->assertSame( 'invalid_request', $response->body['code'] );
		}
	}

	public function test_wrong_product_and_wrong_email_look_like_not_found(): void {
		$license = $this->license();

		$unknown = $this->api->validate( array( 'license_key' => 'NOPE', 'product_id' => 7 ), self::CLIENT );
		$product = $this->api->validate( array( 'license_key' => $license->license_key, 'product_id' => 8 ), self::CLIENT );
		$email   = $this->api->validate( array( 'license_key' => $license->license_key, 'product_id' => 7, 'email' => 'other@example.com' ), self::CLIENT );

		foreach ( array( $unknown, $product, $email ) as $response ) {
			$this->assertSame( 404, $response->status );
			$this->assertSame( 'not_found', $response->body['code'] );
		}
		$this->assertSame( $unknown->body, $product->body );
		$this->assertSame( $unknown->body, $email->body );
	}

	public function test_expired_revoked_and_refunded_are_forbidden_with_their_own_code(): void {
		$expired  = $this->license( 1, '2026-02-01 00:00:00' );
		$revoked  = $this->license();
		$refunded = $this->license();
		$this->licenses->revoke_license( $revoked->id );
		$this->licenses->revoke_license( $refunded->id, 'refunded' );
		$this->clock->advance( '+2 months' );

		foreach ( array( 'expired' => $expired, 'revoked' => $revoked, 'refunded' => $refunded ) as $code => $license ) {
			$response = $this->api->validate( array( 'license_key' => $license->license_key, 'product_id' => 7 ), self::CLIENT );
			$this->assertSame( 403, $response->status );
			$this->assertSame( $code, $response->body['code'] );
		}
	}

	public function test_validate_reports_whether_the_calling_site_is_still_activated(): void {
		$license = $this->license( 2 );
		$params  = array( 'license_key' => $license->license_key, 'product_id' => 7, 'site_url' => 'https://www.one.com/' );

		$this->assertFalse( $this->api->validate( $params, self::CLIENT )->body['site_active'] );

		$this->api->activate( $params, self::CLIENT );
		$this->assertTrue( $this->api->validate( $params, self::CLIENT )->body['site_active'] );

		$this->api->deactivate( $params, self::CLIENT );
		$this->assertFalse( $this->api->validate( $params, self::CLIENT )->body['site_active'] );
	}

	public function test_validate_without_a_site_does_not_mention_site_state(): void {
		$license = $this->license();

		$body = $this->api->validate( array( 'license_key' => $license->license_key, 'product_id' => 7 ), self::CLIENT )->body;

		$this->assertArrayNotHasKey( 'site_active', $body );
	}

	// Rate limiting.

	public function test_clients_are_blocked_after_too_many_bad_keys(): void {
		$license = $this->license();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->api->validate( array( 'license_key' => "BAD{$i}", 'product_id' => 7 ), self::CLIENT );
		}

		$blocked = $this->api->validate( array( 'license_key' => $license->license_key, 'product_id' => 7 ), self::CLIENT );
		$this->assertSame( 429, $blocked->status );
		$this->assertSame( 'rate_limited', $blocked->body['code'] );
		$this->assertSame( 60, $blocked->retry_after );

		// Other clients are not affected.
		$this->assertSame( 200, $this->api->validate( array( 'license_key' => $license->license_key, 'product_id' => 7 ), '198.51.100.1' )->status );
	}

	public function test_expired_licenses_do_not_count_as_guessing(): void {
		$expired = $this->license( 1, '2026-02-01 00:00:00' );
		$this->clock->advance( '+2 months' );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->api->validate( array( 'license_key' => $expired->license_key, 'product_id' => 7 ), self::CLIENT );
		}

		$this->assertSame( 0, $this->limiter->failures[ self::CLIENT ] ?? 0 );
	}

	// Activate and deactivate.

	public function test_activate_and_deactivate(): void {
		$license = $this->license( 1 );
		$params  = array( 'license_key' => $license->license_key, 'product_id' => 7, 'site_url' => 'https://www.one.com/', 'product_version' => '1.0.0' );

		$first = $this->api->activate( $params, self::CLIENT );
		$this->assertSame( 200, $first->status );
		$this->assertSame( 'activated', $first->body['code'] );
		$this->assertSame( 'one.com', $first->body['activation']['site'] );
		$this->assertSame( 1, $first->body['license']['activations_used'] );

		$again = $this->api->activate( $params, self::CLIENT );
		$this->assertSame( 'already_active', $again->body['code'] );

		$other = $this->api->activate( array( 'site_url' => 'two.com' ) + $params, self::CLIENT );
		$this->assertSame( 403, $other->status );
		$this->assertSame( 'limit_reached', $other->body['code'] );
		$this->assertSame( 1, $other->body['license']['activations_used'] );

		$gone = $this->api->deactivate( array( 'license_key' => $license->license_key, 'site_url' => 'one.com' ), self::CLIENT );
		$this->assertSame( 200, $gone->status );
		$this->assertSame( 0, $gone->body['license']['activations_used'] );

		$twice = $this->api->deactivate( array( 'license_key' => $license->license_key, 'site_url' => 'one.com' ), self::CLIENT );
		$this->assertSame( 404, $twice->status );
		$this->assertSame( 'not_active', $twice->body['code'] );
	}

	public function test_a_hostile_product_version_is_cleaned_before_it_is_stored(): void {
		$license = $this->license( 2 );

		$response = $this->api->activate( array( 'license_key' => $license->license_key, 'product_id' => 7, 'site_url' => 'one.com', 'product_version' => '1.0<script>alert(1)</script>' ), self::CLIENT );

		$this->assertSame( 200, $response->status );
		$this->assertSame( '1.0scriptalert1script', $this->licenses->active_sites( $license )[0]->product_version );
	}

	public function test_activate_needs_a_site(): void {
		$license = $this->license();

		$response = $this->api->activate( array( 'license_key' => $license->license_key, 'product_id' => 7 ), self::CLIENT );

		$this->assertSame( 400, $response->status );
		$this->assertSame( 'invalid_site', $response->body['code'] );
	}

	public function test_activate_with_a_bad_key_is_not_found(): void {
		$response = $this->api->activate( array( 'license_key' => 'NOPE', 'product_id' => 7, 'site_url' => 'one.com' ), self::CLIENT );

		$this->assertSame( 404, $response->status );
		$this->assertSame( 'not_found', $response->body['code'] );
	}

	// Updates and downloads.

	public function test_update_check_returns_product_details_and_a_signed_link(): void {
		$license = $this->license();

		$response = $this->api->check_update( array( 'license_key' => $license->license_key, 'product_id' => 7, 'current_version' => '1.9.0' ), self::CLIENT );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'Great Plugin', $response->body['product']['name'] );
		$this->assertSame( '2.0.0', $response->body['product']['version'] );
		$this->assertTrue( $response->body['update_available'] );
		$this->assertSame( 900, $response->body['expires_in'] );
		$this->assertStringStartsWith( 'https://shop.test/download?token=', $response->body['download_url'] );
		$this->assertStringNotContainsString( $license->license_key, $response->body['download_url'] );
	}

	public function test_update_available_is_false_when_current_and_unknown_when_not_sent(): void {
		$license = $this->license();
		$params  = array( 'license_key' => $license->license_key, 'product_id' => 7 );

		$this->assertSame( false, $this->api->check_update( $params + array( 'current_version' => '2.0.0' ), self::CLIENT )->body['update_available'] );
		$this->assertNull( $this->api->check_update( $params, self::CLIENT )->body['update_available'] );
	}

	public function test_update_check_refuses_expired_licenses_and_products_without_a_package(): void {
		$expired = $this->license( 1, '2026-02-01 00:00:00' );
		$valid   = $this->license();
		$this->clock->advance( '+2 months' );

		$this->assertSame( 403, $this->api->check_update( array( 'license_key' => $expired->license_key, 'product_id' => 7 ), self::CLIENT )->status );

		$this->products->package = null;
		$no_package = $this->api->check_update( array( 'license_key' => $valid->license_key, 'product_id' => 7 ), self::CLIENT );
		$this->assertSame( 404, $no_package->status );
		$this->assertSame( 'no_package', $no_package->body['code'] );
	}

	public function test_download_streams_the_package_for_a_good_token(): void {
		$license = $this->license();
		$token   = $this->signer->sign( $license->id, 7, 900 );

		$response = $this->api->download( $token, self::CLIENT );

		$this->assertSame( 200, $response->status );
		$this->assertSame( '/tmp/pkg.zip', $response->file );
	}

	public function test_download_rejects_bad_tokens_and_counts_them(): void {
		$response = $this->api->download( 'garbage.token', self::CLIENT );

		$this->assertSame( 403, $response->status );
		$this->assertSame( 'invalid_token', $response->body['code'] );
		$this->assertSame( 1, $this->limiter->failures[ self::CLIENT ] );
	}

	public function test_download_rechecks_the_license(): void {
		$license = $this->license();
		$token   = $this->signer->sign( $license->id, 7, 900 );
		$this->licenses->revoke_license( $license->id );

		$response = $this->api->download( $token, self::CLIENT );

		$this->assertSame( 403, $response->status );
		$this->assertSame( 'revoked', $response->body['code'] );
		$this->assertNull( $response->file );
	}
}
