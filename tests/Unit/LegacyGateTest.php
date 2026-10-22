<?php
/**
 * Tests for counting and stopping calls to the 1.x API addresses.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Api\LegacyGate;
use Devllo\WPLicenseIt\Api\LegacyUsage;
use Devllo\WPLicenseIt\DryRun\MemoryRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Api\LegacyUsage
 * @covers \Devllo\WPLicenseIt\Api\LegacyGate
 */
final class LegacyGateTest extends TestCase {

	private const NOW = 1792497600; // 2026-10-20 12:00 UTC.
	private const DAY = 86400;

	public function test_calls_are_counted_per_day_and_product(): void {
		$state = LegacyUsage::record( array(), 7, self::NOW );
		$state = LegacyUsage::record( $state, 7, self::NOW + 60 );
		$state = LegacyUsage::record( $state, 9, self::NOW - self::DAY );

		$summary = LegacyUsage::summary( $state, self::NOW + 60 );

		$this->assertSame( 3, $summary['calls'] );
		$this->assertSame(
			array(
				7 => 2,
				9 => 1,
			),
			$summary['products'],
			'most used product first'
		);
		$this->assertSame( self::NOW + 60, $summary['last'] );
	}

	public function test_only_the_last_30_days_count(): void {
		$state = LegacyUsage::record( array(), 7, self::NOW - 30 * self::DAY );
		$state = LegacyUsage::record( $state, 8, self::NOW - 29 * self::DAY );

		$this->assertSame( array( 8 => 1 ), LegacyUsage::summary( $state, self::NOW )['products'], 'a call 30 days ago has dropped off, 29 days ago has not' );

		$later = LegacyUsage::summary( $state, self::NOW + 2 * self::DAY );
		$this->assertSame( 0, $later['calls'] );
		$this->assertNull( $later['last'], 'with no calls left there is no last call to show' );

		$state = LegacyUsage::record( $state, 9, self::NOW + 2 * self::DAY );
		$this->assertSame( array( '2026-10-22' ), array_keys( $state['days'] ), 'old days are not kept in the option' );
	}

	public function test_a_damaged_state_is_read_as_empty(): void {
		$broken = array(
			'days' => array(
				'yesterday'  => array( 7 => 3 ),
				'2026-10-20' => 'many',
				'2026-10-19' => array(
					7 => -2,
					8 => '4',
					9 => 1,
				),
			),
			'last' => 'soon',
		);

		$summary = LegacyUsage::summary( $broken, self::NOW );

		$this->assertSame( 1, $summary['calls'] );
		$this->assertSame( array( 9 => 1 ), $summary['products'] );
		$this->assertNull( $summary['last'] );
		$this->assertSame( 1, LegacyUsage::summary( LegacyUsage::record( $broken, 0, self::NOW ), self::NOW )['products'][0], 'a call without a product counts under 0' );
	}

	public function test_switched_off_addresses_answer_gone(): void {
		$stop = LegacyGate::decide( false, true, new MemoryRateLimiter(), '203.0.113.5' );

		$this->assertNotNull( $stop );
		$this->assertSame( 410, $stop->status );
		$this->assertStringContainsString( 'no longer available', $stop->body['error'] );
	}

	public function test_before_the_migration_a_client_with_too_many_wrong_keys_is_stopped(): void {
		$limiter = new MemoryRateLimiter( 3, 900 );

		$this->assertNull( LegacyGate::decide( true, false, $limiter, '203.0.113.5' ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->record_failure( '203.0.113.5' );
		}

		$stop = LegacyGate::decide( true, false, $limiter, '203.0.113.5' );
		$this->assertNotNull( $stop );
		$this->assertSame( 429, $stop->status );
		$this->assertGreaterThan( 0, $stop->retry_after );
		$this->assertNull( LegacyGate::decide( true, false, $limiter, '198.51.100.7' ), 'another client is not affected' );
		$this->assertNull( LegacyGate::decide( true, true, $limiter, '203.0.113.5' ), 'after the migration the 2.0 handler limits wrong keys itself' );
	}
}
