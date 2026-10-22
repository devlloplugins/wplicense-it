<?php
/**
 * Tests for the test reminder's made-up license.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Reminders\TestReminder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Reminders\TestReminder
 */
final class TestReminderTest extends TestCase {

	public function test_the_sample_license_is_never_a_saved_one_and_expires_in_the_given_days(): void {
		$now     = new DateTimeImmutable( '2026-10-22 12:00:00', new DateTimeZone( 'UTC' ) );
		$license = TestReminder::sample( 7, 'seller@example.com', 1, 30, $now );

		$this->assertSame( 0, $license->id, 'an ID no saved license has, so nothing can be recorded against it' );
		$this->assertSame( 7, $license->product_id );
		$this->assertSame( 'seller@example.com', $license->email );
		$this->assertSame( 1, $license->user_id );
		$this->assertSame( Status::ACTIVE, $license->status );
		$this->assertSame( '2026-11-21 12:00:00', $license->expires_at->format( 'Y-m-d H:i:s' ) );
	}

	public function test_at_least_one_day_is_left(): void {
		$now = new DateTimeImmutable( '2026-10-22 12:00:00', new DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-10-23', TestReminder::sample( 0, 'a@example.com', null, 0, $now )->expires_at->format( 'Y-m-d' ) );
	}
}
