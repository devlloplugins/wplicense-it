<?php
/**
 * Tests for the 1.x API compatibility layer.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Api\LegacyApi;
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
 * @covers \Devllo\WPLicenseIt\Api\LegacyApi
 */
final class LegacyApiTest extends TestCase {

	private const CLIENT = '203.0.113.9';

	private LegacyApi $api;
	private LicenseService $licenses;
	private FixedClock $clock;
	private InMemoryRateLimiter $limiter;
	private FakeProductCatalog $products;

	protected function setUp(): void {
		$this->clock    = new FixedClock( '2026-01-01 00:00:00' );
		$this->licenses = new LicenseService( new InMemoryLicenseRepository(), new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), $this->clock );
		$this->limiter  = new InMemoryRateLimiter( 3 );
		$this->products = new FakeProductCatalog();
		$this->api      = new LegacyApi(
			$this->licenses,
			$this->products,
			$this->limiter,
			static fn( int $p, string $k, string $e, string $l ): string => "https://shop.test/get?p={$p}&k={$k}&e={$e}&l={$l}"
		);
	}

	private function license(): License {
		return $this->licenses->issue_license( 7, 'buyer@example.com', null, null, 1 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function params( License $license, array $override = array() ): array {
		return $override + array(
			'p' => '7',
			'e' => 'buyer@example.com',
			'l' => $license->license_key,
			'k' => 'prod-key',
		);
	}

	public function test_info_has_the_1x_shape(): void {
		$license  = $this->license();
		$response = $this->api->handle( 'info', $this->params( $license ), self::CLIENT );

		$this->assertSame( 200, $response->status );
		$this->assertSame(
			array( 'name', 'description', 'version', 'tested', 'banner_low', 'banner_high', 'package_url' ),
			array_keys( $response->body )
		);
		$this->assertSame( 'Great Plugin', $response->body['name'] );
		$this->assertSame( '2.0.0', $response->body['version'] );
		$this->assertSame( 'https://x.test/logo.png', $response->body['banner_low'] );
		$this->assertSame( "https://shop.test/get?p=7&k=prod-key&e=buyer@example.com&l={$license->license_key}", $response->body['package_url'] );
	}

	public function test_status_is_a_plain_active_or_inactive_string(): void {
		$license = $this->license();

		$this->assertSame( 'active', $this->api->handle( 'status', $this->params( $license ), self::CLIENT )->body );
		$this->assertSame( 'inactive', $this->api->handle( 'status', $this->params( $license, array( 'l' => 'WRONG' ) ), self::CLIENT )->body );
		$this->assertSame( 'inactive', $this->api->handle( 'status', $this->params( $license, array( 'k' => 'wrong-key' ) ), self::CLIENT )->body );
		$this->assertSame( 'inactive', $this->api->handle( 'status', $this->params( $license, array( 'p' => '99' ) ), self::CLIENT )->body );
		$this->assertSame( 'inactive', $this->api->handle( 'status', array(), self::CLIENT )->body );
	}

	public function test_info_errors_keep_the_1x_messages_and_status_200(): void {
		$license = $this->license();

		$bad_license = $this->api->handle( 'info', $this->params( $license, array( 'l' => 'WRONG' ) ), self::CLIENT );
		$this->assertSame( 200, $bad_license->status );
		$this->assertSame( array( 'error' => 'Invalid license or license expired.' ), $bad_license->body );

		$this->assertSame( array( 'error' => 'Product not found.' ), $this->api->handle( 'info', $this->params( $license, array( 'p' => '99' ) ), self::CLIENT )->body );
		$this->assertSame( array( 'error' => 'Invalid request' ), $this->api->handle( 'info', array( 'p' => '7' ), self::CLIENT )->body );
		$this->assertSame( array( 'error' => 'No such API action' ), $this->api->handle( 'bogus', $this->params( $license ), self::CLIENT )->body );
	}

	public function test_the_product_api_key_is_required(): void {
		$license = $this->license();

		$response = $this->api->handle( 'info', $this->params( $license, array( 'k' => 'nope' ) ), self::CLIENT );

		$this->assertSame( array( 'error' => 'Invalid license or license expired.' ), $response->body );
	}

	public function test_get_returns_the_package_file(): void {
		$license = $this->license();

		$response = $this->api->handle( 'get', $this->params( $license ), self::CLIENT );

		$this->assertSame( '/tmp/pkg.zip', $response->file );

		$this->products->package = null;
		$this->assertSame( 404, $this->api->handle( 'get', $this->params( $license ), self::CLIENT )->status );
	}

	public function test_expired_and_revoked_licenses_are_inactive_and_not_counted_as_guessing(): void {
		$expiring = $this->licenses->issue_license( 7, 'buyer@example.com', null, null, 1, new \DateTimeImmutable( '2026-02-01', new \DateTimeZone( 'UTC' ) ) );
		$revoked  = $this->license();
		$this->licenses->revoke_license( $revoked->id );
		$this->clock->advance( '+2 months' );

		$this->assertSame( 'inactive', $this->api->handle( 'status', $this->params( $expiring ), self::CLIENT )->body );
		$this->assertSame( 'inactive', $this->api->handle( 'status', $this->params( $revoked ), self::CLIENT )->body );
		$this->assertSame( 0, $this->limiter->failures[ self::CLIENT ] ?? 0 );
	}

	public function test_guessing_is_rate_limited(): void {
		$license = $this->license();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->api->handle( 'status', $this->params( $license, array( 'l' => "BAD{$i}" ) ), self::CLIENT );
		}

		$response = $this->api->handle( 'status', $this->params( $license ), self::CLIENT );

		$this->assertSame( 429, $response->status );
		$this->assertSame( 60, $response->retry_after );
	}
}
