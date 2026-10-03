<?php
/**
 * Tests for the key generator.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Licenses\KeyGenerator
 */
final class KeyGeneratorTest extends TestCase {

	public function test_keys_have_six_groups_of_unambiguous_characters(): void {
		$key = ( new KeyGenerator() )->generate();

		$this->assertMatchesRegularExpression( '/^([A-HJ-NP-Z2-9]{5}-){5}[A-HJ-NP-Z2-9]{5}$/', $key );
		$this->assertLessThanOrEqual( 64, strlen( $key ) );
	}

	public function test_keys_do_not_repeat(): void {
		$generator = new KeyGenerator();
		$keys      = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$keys[ $generator->generate() ] = true;
		}

		$this->assertCount( 500, $keys );
	}
}
