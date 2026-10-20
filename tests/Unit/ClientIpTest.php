<?php
/**
 * Tests for finding the visitor's address behind Cloudflare or a proxy.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Api\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Api\ClientIp
 */
final class ClientIpTest extends TestCase {

	private const CLOUDFLARE_EDGE = '162.158.12.34';

	public function test_direct_ignores_every_header(): void {
		$headers = array(
			'cf-connecting-ip' => '198.51.100.7',
			'x-forwarded-for'  => '198.51.100.7',
		);

		$this->assertSame( '203.0.113.9', ClientIp::resolve( '203.0.113.9', $headers, ClientIp::DIRECT, array( '0.0.0.0/0' ) ) );
	}

	public function test_cloudflare_header_is_used_only_on_requests_from_cloudflare(): void {
		$headers = array( 'cf-connecting-ip' => '198.51.100.7' );

		$this->assertSame( '198.51.100.7', ClientIp::resolve( self::CLOUDFLARE_EDGE, $headers, ClientIp::CLOUDFLARE, ClientIp::CLOUDFLARE_RANGES ) );
		$this->assertSame( '203.0.113.9', ClientIp::resolve( '203.0.113.9', $headers, ClientIp::CLOUDFLARE, ClientIp::CLOUDFLARE_RANGES ), 'someone reaching the site directly cannot pick their address' );
		$this->assertSame( '2001:db8::7', ClientIp::resolve( '2606:4700:10::1', array( 'cf-connecting-ip' => '2001:db8::7' ), ClientIp::CLOUDFLARE, ClientIp::CLOUDFLARE_RANGES ), 'IPv6 edges too' );
	}

	public function test_a_missing_or_broken_cloudflare_header_falls_back_to_the_edge(): void {
		$this->assertSame( self::CLOUDFLARE_EDGE, ClientIp::resolve( self::CLOUDFLARE_EDGE, array(), ClientIp::CLOUDFLARE, ClientIp::CLOUDFLARE_RANGES ) );
		$this->assertSame( self::CLOUDFLARE_EDGE, ClientIp::resolve( self::CLOUDFLARE_EDGE, array( 'cf-connecting-ip' => '198.51.100.7, 1.2.3.4' ), ClientIp::CLOUDFLARE, ClientIp::CLOUDFLARE_RANGES ) );
	}

	public function test_proxy_takes_the_rightmost_address_that_is_not_a_listed_proxy(): void {
		$trusted = array( '10.0.0.0/8' );

		// The client could have written anything on the left; only what the trusted proxies added counts.
		$this->assertSame( '198.51.100.7', ClientIp::resolve( '10.0.0.5', array( 'x-forwarded-for' => '1.1.1.1, 198.51.100.7' ), ClientIp::PROXY, $trusted ) );
		$this->assertSame( '198.51.100.7', ClientIp::resolve( '10.0.0.5', array( 'x-forwarded-for' => '198.51.100.7, 10.0.0.9, 10.1.2.3' ), ClientIp::PROXY, $trusted ), 'a chain of proxies is skipped' );
		$this->assertSame( '203.0.113.9', ClientIp::resolve( '203.0.113.9', array( 'x-forwarded-for' => '198.51.100.7' ), ClientIp::PROXY, $trusted ), 'a request that did not come through a listed proxy is taken as it is' );
	}

	public function test_proxy_with_nothing_usable_falls_back_to_the_proxy(): void {
		$trusted = array( '10.0.0.0/8' );

		$this->assertSame( '10.0.0.5', ClientIp::resolve( '10.0.0.5', array(), ClientIp::PROXY, $trusted ) );
		$this->assertSame( '10.0.0.5', ClientIp::resolve( '10.0.0.5', array( 'x-forwarded-for' => '10.0.0.7' ), ClientIp::PROXY, $trusted ), 'only proxies in the chain' );
		$this->assertSame( '10.0.0.5', ClientIp::resolve( '10.0.0.5', array( 'x-forwarded-for' => '198.51.100.7, not-an-ip' ), ClientIp::PROXY, $trusted ), 'a broken hop stops the walk' );
		$this->assertSame( '10.0.0.5', ClientIp::resolve( '10.0.0.5', array( 'x-forwarded-for' => '198.51.100.7' ), ClientIp::PROXY, array() ), 'no proxies listed means none is trusted' );
	}

	public function test_ranges(): void {
		$this->assertTrue( ClientIp::in_ranges( '192.168.1.200', array( '192.168.1.128/25' ) ) );
		$this->assertFalse( ClientIp::in_ranges( '192.168.1.127', array( '192.168.1.128/25' ) ) );
		$this->assertTrue( ClientIp::in_ranges( '203.0.113.9', array( '203.0.113.9' ) ), 'a single address' );
		$this->assertTrue( ClientIp::in_ranges( '2a06:98c7::1', array( '2a06:98c0::/29' ) ) );
		$this->assertFalse( ClientIp::in_ranges( '2a06:98c8::1', array( '2a06:98c0::/29' ) ) );
		$this->assertFalse( ClientIp::in_ranges( '10.0.0.1', array( '::/0' ) ), 'an IPv4 address is never in an IPv6 range' );
		$this->assertFalse( ClientIp::in_ranges( '10.0.0.1', array( '10.0.0.0/33', 'nonsense' ) ), 'broken ranges match nothing' );
		$this->assertFalse( ClientIp::in_ranges( 'nonsense', array( '0.0.0.0/0' ) ) );
	}

	public function test_typed_proxies_and_sources_are_cleaned(): void {
		$this->assertSame( "10.0.0.0/8\n2001:db8::/32\n192.0.2.1", ClientIp::sanitize_proxies( " 10.0.0.0/8,\n nonsense 2001:DB8::/32\n192.0.2.1 10.0.0.0/8 300.1.1.1 10.0.0.0/40" ) );
		$this->assertSame( '', ClientIp::sanitize_proxies( array( '10.0.0.1' ) ) );
		$this->assertCount( 50, ClientIp::parse_proxies( implode( "\n", array_map( static fn( int $i ): string => '10.0.' . $i . '.1', range( 1, 80 ) ) ) ) );
		$this->assertSame( ClientIp::CLOUDFLARE, ClientIp::sanitize_source( 'cloudflare' ) );
		$this->assertSame( ClientIp::DIRECT, ClientIp::sanitize_source( 'everything' ) );
	}
}
