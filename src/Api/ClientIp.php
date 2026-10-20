<?php
/**
 * The client address that wrong keys are counted against.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Works out the visitor's real address when the site is behind Cloudflare or another proxy, without trusting headers
 * that anyone could send: a forwarding header is only read when the request came from an address the site trusts.
 *
 * Sources: "direct" (REMOTE_ADDR, the default), "cloudflare" (CF-Connecting-IP, read only when the request came from one
 * of Cloudflare's published ranges) and "proxy" (X-Forwarded-For, read only when the request came from one of the
 * addresses the site owner listed; the rightmost address that is not one of those proxies is the client).
 */
final class ClientIp {

	public const OPTION_SOURCE  = 'wplit_client_ip_source';
	public const OPTION_PROXIES = 'wplit_trusted_proxies';

	public const DIRECT     = 'direct';
	public const CLOUDFLARE = 'cloudflare';
	public const PROXY      = 'proxy';

	private const MAX_PROXIES = 50;

	/**
	 * Cloudflare's published ranges (https://www.cloudflare.com/ips/). They rarely change; the wplicense_it_cloudflare_ranges
	 * filter can replace them.
	 */
	public const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The client's address.
	 *
	 * @param string                $remote  REMOTE_ADDR: the address the request really came from.
	 * @param array<string, string> $headers Request headers, lower-case names (cf-connecting-ip, x-forwarded-for).
	 * @param string                $source  DIRECT, CLOUDFLARE or PROXY.
	 * @param string[]              $trusted Addresses or ranges of the proxies in front of the site (for PROXY), or Cloudflare's.
	 */
	public static function resolve( string $remote, array $headers, string $source, array $trusted ): string {
		if ( self::DIRECT === $source || ! self::in_ranges( $remote, $trusted ) ) {
			return $remote;
		}

		if ( self::CLOUDFLARE === $source ) {
			$ip = trim( $headers['cf-connecting-ip'] ?? '' );

			return self::valid( $ip ) ? $ip : $remote;
		}

		if ( self::PROXY === $source ) {
			$hops = array_reverse( array_map( 'trim', explode( ',', $headers['x-forwarded-for'] ?? '' ) ) );

			foreach ( $hops as $hop ) {
				if ( ! self::valid( $hop ) ) {
					// A malformed entry cannot be trusted, and nothing to the left of it can either.
					return $remote;
				}

				if ( ! self::in_ranges( $hop, $trusted ) ) {
					return $hop;
				}
			}
		}

		return $remote;
	}

	/**
	 * Keeps the lines that are an IP address or a range (CIDR), at most 50, one per line.
	 *
	 * @param mixed $value Typed value.
	 */
	public static function sanitize_proxies( $value ): string {
		return implode( "\n", self::parse_proxies( is_string( $value ) ? $value : '' ) );
	}

	/**
	 * The listed proxies.
	 *
	 * @param string $text One address or range per line (commas and spaces work too).
	 * @return string[]
	 */
	public static function parse_proxies( string $text ): array {
		$parts = preg_split( '/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$kept  = array();

		foreach ( false === $parts ? array() : $parts as $part ) {
			if ( null !== self::range( $part ) ) {
				$kept[ strtolower( $part ) ] = strtolower( $part );
			}
		}

		return array_slice( array_values( $kept ), 0, self::MAX_PROXIES );
	}

	/**
	 * Accepts only the three sources.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_source( $value ): string {
		return in_array( $value, array( self::DIRECT, self::CLOUDFLARE, self::PROXY ), true ) ? (string) $value : self::DIRECT;
	}

	/**
	 * Whether an address is in any of the ranges.
	 *
	 * @param string   $ip     Address.
	 * @param string[] $ranges Addresses or CIDR ranges.
	 */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$address = self::valid( $ip ) ? inet_pton( $ip ) : false;

		if ( false === $address ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			$parsed = self::range( $range );

			if ( null === $parsed || strlen( $parsed[0] ) !== strlen( $address ) ) {
				continue;
			}

			list( $network, $bits ) = $parsed;
			$bytes                  = intdiv( $bits, 8 );
			$rest                   = $bits % 8;

			if ( substr( $address, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
				continue;
			}

			if ( 0 === $rest ) {
				return true;
			}

			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

			if ( ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}//end foreach

		return false;
	}

	/**
	 * Whether a string is an IPv4 or IPv6 address.
	 *
	 * @param string $ip Text.
	 */
	private static function valid( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * A range as packed network address and prefix length, or null if it is not one.
	 *
	 * @param string $range Address or CIDR range.
	 * @return array{0: string, 1: int}|null
	 */
	private static function range( string $range ): ?array {
		$parts = explode( '/', trim( $range ), 2 );

		if ( ! self::valid( $parts[0] ) ) {
			return null;
		}

		$packed = (string) inet_pton( $parts[0] );
		$max    = 8 * strlen( $packed );

		if ( ! isset( $parts[1] ) ) {
			return array( $packed, $max );
		}

		if ( 1 !== preg_match( '/^\d{1,3}$/', $parts[1] ) || (int) $parts[1] > $max ) {
			return null;
		}

		return array( $packed, (int) $parts[1] );
	}
}
