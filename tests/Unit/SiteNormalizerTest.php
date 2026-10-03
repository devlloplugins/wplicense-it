<?php
/**
 * Tests for site normalisation.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Licenses\SiteNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Licenses\SiteNormalizer
 */
final class SiteNormalizerTest extends TestCase {

	/**
	 * @dataProvider normalize_cases
	 */
	public function test_normalize( string $input, ?string $expected ): void {
		$this->assertSame( $expected, SiteNormalizer::normalize( $input ) );
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public function normalize_cases(): array {
		return array(
			'scheme and www removed'         => array( 'https://www.Example.com/', 'example.com' ),
			'no scheme'                      => array( 'example.com', 'example.com' ),
			'subdirectory install kept'      => array( 'https://example.com/blog/', 'example.com/blog' ),
			'query and fragment dropped'     => array( 'http://example.com/?a=1#top', 'example.com' ),
			'default ports dropped'          => array( 'https://example.com:443', 'example.com' ),
			'other ports kept'               => array( 'http://localhost:8080/', 'localhost:8080' ),
			'surrounding whitespace'         => array( '  example.com  ', 'example.com' ),
			'empty'                          => array( '', null ),
			'blank'                          => array( '   ', null ),
			'no host'                        => array( 'http:///', null ),
			'invalid characters'             => array( 'ex ample.com', null ),
			'markup in the path'             => array( 'example.com/<script>alert(1)</script>', null ),
			'quotes in the path'             => array( 'example.com/a"b', null ),
			'a newline in the path'          => array( "example.com/a\nb", 'example.com/a_b' ), // parse_url already neutralises control characters.
			'normal folder names'            => array( 'https://example.com/my-blog_2/sub.dir/', 'example.com/my-blog_2/sub.dir' ),
		);
	}

	public function test_overlong_sites_are_rejected(): void {
		$this->assertNull( SiteNormalizer::normalize( 'example.com/' . str_repeat( 'a', 200 ) ) );
	}

	/**
	 * @dataProvider local_cases
	 */
	public function test_is_local( string $site, bool $expected ): void {
		$this->assertSame( $expected, SiteNormalizer::is_local( $site ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public function local_cases(): array {
		return array(
			'localhost'      => array( 'localhost', true ),
			'localhost port' => array( 'localhost:8080/shop', true ),
			'loopback'       => array( '127.0.0.1', true ),
			'.test'          => array( 'shop.test', true ),
			'.local'         => array( 'shop.local', true ),
			'staging prefix' => array( 'staging.example.com', true ),
			'dev prefix'     => array( 'dev.example.com', true ),
			'production'     => array( 'example.com', false ),
			'contains test'  => array( 'testimonials.com', false ),
			'contains dev'   => array( 'devices.example.com', false ),
		);
	}
}
