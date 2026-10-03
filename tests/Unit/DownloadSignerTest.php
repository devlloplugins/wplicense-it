<?php
/**
 * Tests for signed download tokens and the rate limiter.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Api\DownloadSigner;
use Devllo\WPLicenseIt\Api\TransientRateLimiter;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/wp-transient-stubs.php';

/**
 * @covers \Devllo\WPLicenseIt\Api\DownloadSigner
 * @covers \Devllo\WPLicenseIt\Api\TransientRateLimiter
 */
final class DownloadSignerTest extends TestCase {

	private int $now = 1800000000;

	private function signer( string $secret = 'secret' ): DownloadSigner {
		return new DownloadSigner( $secret, fn(): int => $this->now );
	}

	public function test_a_fresh_token_verifies(): void {
		$token = $this->signer()->sign( 11, 7, 900 );

		$this->assertSame(
			array( 'license_id' => 11, 'product_id' => 7 ),
			$this->signer()->verify( $token )
		);
	}

	public function test_tokens_expire(): void {
		$token = $this->signer()->sign( 11, 7, 900 );

		$this->now += 899;
		$this->assertNotNull( $this->signer()->verify( $token ) );

		$this->now += 2;
		$this->assertNull( $this->signer()->verify( $token ) );
	}

	public function test_tampered_or_foreign_tokens_are_rejected(): void {
		$token = $this->signer()->sign( 11, 7, 900 );
		list( $payload, $signature ) = explode( '.', $token );

		$other = $this->signer()->sign( 99, 7, 900 );
		list( $other_payload ) = explode( '.', $other );

		$this->assertNull( $this->signer( 'different secret' )->verify( $token ) );
		$this->assertNull( $this->signer()->verify( $other_payload . '.' . $signature ) );
		$this->assertNull( $this->signer()->verify( $payload ) );
		$this->assertNull( $this->signer()->verify( '' ) );
		$this->assertNull( $this->signer()->verify( 'a.b.c' ) );
	}

	public function test_tokens_are_url_safe(): void {
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $this->signer()->sign( 11, 7, 900 ) );
	}

	public function test_the_rate_limiter_blocks_after_the_limit_and_isolates_clients(): void {
		$limiter = new TransientRateLimiter( 3, 900 );

		$this->assertSame( 0, $limiter->blocked_for( '203.0.113.50' ) );

		$limiter->record_failure( '203.0.113.50' );
		$limiter->record_failure( '203.0.113.50' );
		$this->assertSame( 0, $limiter->blocked_for( '203.0.113.50' ) );

		$limiter->record_failure( '203.0.113.50' );
		$this->assertTrue( $limiter->blocked_for( '203.0.113.50' ) > 0 );
		$this->assertTrue( $limiter->blocked_for( '203.0.113.50' ) <= 900 );

		$this->assertSame( 0, $limiter->blocked_for( '198.51.100.77' ) );
	}
}
