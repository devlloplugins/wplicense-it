<?php
/**
 * Tests for the reminder settings.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Reminders\ReminderSettings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Reminders\ReminderSettings
 */
final class ReminderSettingsTest extends TestCase {

	/**
	 * @dataProvider day_lists
	 *
	 * @param string $typed    What was typed.
	 * @param int[]  $expected What is used.
	 */
	public function test_days_are_cleaned( string $typed, array $expected ): void {
		$this->assertSame( $expected, ReminderSettings::parse_days( $typed ) );
	}

	/**
	 * @return array<string, array{string, int[]}>
	 */
	public function day_lists(): array {
		return array(
			'the default'           => array( '30,7', array( 30, 7 ) ),
			'spaces and semicolons' => array( ' 7 ; 30 ,  14 ', array( 30, 14, 7 ) ),
			'repeats are dropped'   => array( '7,7,30', array( 30, 7 ) ),
			'largest first'         => array( '1,60,14', array( 60, 14, 1 ) ),
			'at most five'          => array( '1,2,3,4,5,6,7', array( 7, 6, 5, 4, 3 ) ),
			'zero is not a day'     => array( '0,7', array( 7 ) ),
			'negative is not a day' => array( '-3,7', array( 7 ) ),
			'a year is the limit'   => array( '365,366,9999', array( 365 ) ),
			'text and decimals'     => array( 'soon,2.5,10', array( 10 ) ),
			'nothing usable'        => array( 'abc', array( 30, 7 ) ),
			'empty'                 => array( '', array( 30, 7 ) ),
		);
	}

	public function test_the_saved_value_is_written_back_cleaned(): void {
		$this->assertSame( '30, 14, 7', ReminderSettings::sanitize_days( '7;14, 30,30' ) );
		$this->assertSame( '30, 7', ReminderSettings::sanitize_days( array( 'x' ) ) );
	}
}
