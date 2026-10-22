<?php
/**
 * Tests for expiry reminders.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\DryRun\MemoryEventLog;
use Devllo\WPLicenseIt\DryRun\MemoryLicenseRepository;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Reminders\ReminderMailer;
use Devllo\WPLicenseIt\Reminders\ReminderService;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Reminders\ReminderService
 */
final class ReminderServiceTest extends TestCase {

	private FixedClock $clock;
	private MemoryLicenseRepository $licenses;
	private MemoryEventLog $events;
	private object $mailer;
	private ReminderService $service;
	private int $counter = 0;

	protected function setUp(): void {
		$this->clock    = new FixedClock( '2026-06-01 12:00:00' );
		$this->licenses = new MemoryLicenseRepository();
		$this->events   = new MemoryEventLog();
		$this->mailer   = new class() implements ReminderMailer {
			/** @var array<int, array{id:int,days:int}> */
			public array $sent = array();
			public bool $works = true;

			public function send( License $license, int $days_left ): bool {
				if ( ! $this->works ) {
					return false;
				}

				$this->sent[] = array(
					'id'   => $license->id,
					'days' => $days_left,
				);

				return true;
			}
		};
		$this->service  = new ReminderService( $this->licenses, $this->events, $this->mailer, $this->clock );
	}

	/**
	 * Adds a license that expires in a number of days from the fixed "now".
	 *
	 * @param string|null $in     Relative time such as "+10 days", or null for a license that never expires.
	 * @param string      $status Status.
	 * @param string      $email  Email.
	 */
	private function license( ?string $in, string $status = Status::ACTIVE, string $email = 'buyer@example.com' ): License {
		$now = ( $this->clock )();

		++$this->counter;

		return $this->licenses->insert(
			new License(
				0,
				1,
				null,
				null,
				'KEY-' . $this->counter,
				$email,
				$status,
				1,
				null === $in ? null : $now->modify( $in ),
				$now,
				$now
			)
		);
	}

	public function test_a_license_inside_the_first_window_gets_one_email(): void {
		$license = $this->license( '+29 days' );

		$this->assertSame( 1, $this->service->send_due( array( 30, 7 ) ) );
		$this->assertSame(
			array(
				array(
					'id'   => $license->id,
					'days' => 29,
				),
			),
			$this->mailer->sent
		);
	}

	public function test_nothing_is_sent_twice(): void {
		$this->license( '+29 days' );

		$this->service->send_due( array( 30, 7 ) );
		$this->assertSame( 0, $this->service->send_due( array( 30, 7 ) ) );
		$this->assertCount( 1, $this->mailer->sent );
	}

	public function test_the_second_window_sends_a_second_email_later(): void {
		$this->license( '+29 days' );
		$this->service->send_due( array( 30, 7 ) );

		$this->clock->advance( '+23 days' ); // 6 days left.
		$this->assertSame( 1, $this->service->send_due( array( 30, 7 ) ) );
		$this->assertSame( 6, $this->mailer->sent[1]['days'] );

		$this->clock->advance( '+1 day' );
		$this->assertSame( 0, $this->service->send_due( array( 30, 7 ) ), 'the 7 day email was sent, so nothing more until expiry' );
	}

	public function test_a_license_first_seen_late_gets_only_the_most_urgent_email(): void {
		$this->license( '+5 days' );

		$this->assertSame( 1, $this->service->send_due( array( 30, 7 ) ) );
		$this->assertSame( 0, $this->service->send_due( array( 30, 7 ) ) );
	}

	public function test_licenses_that_should_not_be_mailed_are_skipped(): void {
		$this->license( null );                          // Never expires.
		$this->license( '+45 days' );                    // Not within 30 days yet.
		$this->license( '-1 day' );                      // Already past its expiry.
		$this->license( '+10 days', Status::REVOKED );
		$this->license( '+10 days', Status::REFUNDED );
		$this->license( '+10 days', Status::EXPIRED );
		$this->license( '+10 days', Status::ACTIVE, '' );
		$this->license( '+10 days', Status::ACTIVE, 'not an email' );
		$this->license( '+10 days', Status::ACTIVE, 'erased-7@erased.invalid' ); // Erased with the WordPress privacy tool.

		$this->assertSame( 0, $this->service->send_due( array( 30, 7 ) ) );
		$this->assertSame( array(), $this->mailer->sent );
	}

	public function test_a_license_that_expires_within_the_hour_is_not_mailed(): void {
		$this->license( '+30 minutes' );

		$this->assertSame( 1, $this->service->send_due( array( 7 ) ), 'part of a day left counts as 1 day' );
		$this->assertSame( 1, $this->mailer->sent[0]['days'] );
	}

	public function test_a_renewal_starts_a_new_cycle(): void {
		$license = $this->license( '+6 days' );
		$this->service->send_due( array( 30, 7 ) );
		$this->assertCount( 1, $this->mailer->sent );

		$renewed = $this->licenses->find( $license->id );
		$this->licenses->update( new License( $renewed->id, $renewed->product_id, $renewed->user_id, $renewed->order_id, $renewed->license_key, $renewed->email, $renewed->status, $renewed->activation_limit, ( $this->clock )()->modify( '+370 days' ), $renewed->created_at, $renewed->updated_at ) );

		$this->clock->advance( '+345 days' ); // 25 days before the new expiry.
		$this->assertSame( 1, $this->service->send_due( array( 30, 7 ) ) );
		$this->assertCount( 2, $this->mailer->sent );
	}

	public function test_a_failed_send_is_tried_again_and_not_recorded(): void {
		$this->license( '+10 days' );
		$this->mailer->works = false;

		$this->assertSame( 0, $this->service->send_due( array( 30, 7 ) ) );
		$this->assertSame( array(), $this->events->types() );

		$this->mailer->works = true;
		$this->assertSame( 1, $this->service->send_due( array( 30, 7 ) ) );
	}

	public function test_what_was_sent_is_written_to_the_history(): void {
		$license = $this->license( '+10 days' );
		$this->service->send_due( array( 30, 7 ) );

		$events = $this->events->of_type( $license->id, EventLog::REMINDER );
		$this->assertCount( 1, $events );
		$this->assertSame( 30, $events[0]['data']['days'] );
		$this->assertSame( $license->expires_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ), $events[0]['data']['expires_at'] );
		$this->assertArrayNotHasKey( 'email', $events[0]['data'], 'the customer address is not copied into the history' );
	}

	public function test_busy_activity_does_not_hide_a_sent_reminder(): void {
		$license = $this->license( '+10 days' );
		$this->service->send_due( array( 30, 7 ) );

		for ( $i = 0; $i < 80; $i++ ) {
			$this->events->record( $license->id, EventLog::ACTIVATED, array( 'site' => "s{$i}.example.com" ) );
		}

		$this->assertSame( 0, $this->service->send_due( array( 30, 7 ) ) );
	}

	public function test_a_run_stops_at_the_limit_and_the_rest_goes_next_time(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->license( '+10 days' );
		}

		$this->assertSame( 3, $this->service->send_due( array( 30, 7 ), 3 ) );
		$this->assertSame( 2, $this->service->send_due( array( 30, 7 ), 3 ) );
		$this->assertSame( 0, $this->service->send_due( array( 30, 7 ), 3 ) );
	}

	public function test_every_license_is_reached_when_there_are_several_batches(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->license( '+10 days' );
		}

		$this->assertSame( 7, $this->service->send_due( array( 30 ), 500, 2 ) );
	}

	public function test_licenses_with_different_and_equal_expiry_dates_are_all_reached_in_small_batches(): void {
		foreach ( array( '+20 days', '+3 days', '+3 days', '+12 days', '+3 days', '+20 days', '+1 day' ) as $in ) {
			$this->license( $in );
		}

		$this->assertSame( 7, $this->service->send_due( array( 30 ), 500, 2 ) );
		$this->assertCount( 7, array_unique( array_column( $this->mailer->sent, 'id' ) ), 'each license once' );
	}

	public function test_no_days_means_no_emails(): void {
		$this->license( '+10 days' );

		$this->assertSame( 0, $this->service->send_due( array() ) );
		$this->assertSame( 0, $this->service->send_due( array( 0, -5 ) ) );
	}

	public function test_the_listing_does_not_include_the_boundary_before_now(): void {
		$now = ( $this->clock )();
		$this->license( '+0 seconds' );

		$this->assertSame( array(), $this->licenses->find_expiring( $now, $now->modify( '+30 days' ), null, 10 ) );
		$this->assertInstanceOf( DateTimeImmutable::class, $now );
	}

	// After expiry (card 140).

	public function test_an_expired_license_gets_one_email_the_chosen_days_after(): void {
		$early = $this->license( '-2 days', Status::EXPIRED );
		$due   = $this->license( '-4 days', Status::EXPIRED );

		$this->assertSame( 1, $this->service->send_after( 3 ) );
		$this->assertSame( $due->id, $this->mailer->sent[0]['id'] );
		$this->assertSame( -4, $this->mailer->sent[0]['days'], 'the mailer is told how long ago it expired, as a negative number' );

		$this->assertSame( 0, $this->service->send_after( 3 ), 'nothing twice' );

		$this->clock->advance( '+1 day' );
		$this->assertSame( 1, $this->service->send_after( 3 ), 'the other one, once it reaches the day' );
		$this->assertSame( $early->id, $this->mailer->sent[1]['id'] );

		$events = $this->events->of_type( $due->id, EventLog::REMINDER );
		$this->assertSame( 3, $events[0]['data']['after'] );
		$this->assertSame( $due->expires_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ), $events[0]['data']['expires_at'] );
	}

	public function test_licenses_that_expired_long_ago_are_left_alone(): void {
		$this->license( '-34 days', Status::EXPIRED ); // Reached day 3 thirty-one days ago.
		$recent = $this->license( '-32 days', Status::EXPIRED ); // Reached day 3 twenty-nine days ago: a missed day is caught up.

		$this->assertSame( 1, $this->service->send_after( 3 ) );
		$this->assertSame( $recent->id, $this->mailer->sent[0]['id'] );
	}

	public function test_only_expired_licenses_with_an_address_are_emailed_after(): void {
		$this->license( '-5 days', Status::REVOKED );
		$this->license( '-5 days', Status::REFUNDED );
		$this->license( '-5 days', Status::ACTIVE ); // Not marked expired yet; the daily job marks it first.
		$this->license( null, Status::EXPIRED );
		$this->license( '-5 days', Status::EXPIRED, 'not-an-address' );

		$this->assertSame( 0, $this->service->send_after( 3 ) );
	}

	public function test_the_emails_before_and_after_do_not_hide_each_other(): void {
		$license = $this->license( '+5 days' );
		$this->assertSame( 1, $this->service->send_due( array( 30, 7 ) ) );

		$this->clock->advance( '+9 days' );
		$this->licenses->update( $license->with_status( Status::EXPIRED, ( $this->clock )() ) );
		$this->assertSame( 1, $this->service->send_after( 3 ), 'a reminder before expiry does not count as the email after' );

		// A renewal gives a new expiry date and a new cycle of reminders before it.
		$renewed             = $this->licenses->find( $license->id );
		$renewed->expires_at = ( $this->clock )()->modify( '+20 days' );
		$this->licenses->update( $renewed->with_status( Status::ACTIVE, ( $this->clock )() ) );
		$this->assertSame( 1, $this->service->send_due( array( 30, 7 ) ), 'the email after expiry does not count as a reminder before the new expiry' );
	}

	public function test_a_failed_email_after_expiry_is_tried_again(): void {
		$this->license( '-4 days', Status::EXPIRED );
		$this->mailer->works = false;

		$this->assertSame( 0, $this->service->send_after( 3 ) );
		$this->assertSame( array(), $this->events->types() );

		$this->mailer->works = true;
		$this->assertSame( 1, $this->service->send_after( 3 ) );
	}

	public function test_no_days_after_means_no_emails(): void {
		$this->license( '-4 days', Status::EXPIRED );

		$this->assertSame( 0, $this->service->send_after( 0 ) );
	}

	// Stopped by the customer (card 141).

	public function test_an_address_that_stopped_the_emails_gets_neither_reminder(): void {
		$service = new ReminderService( $this->licenses, $this->events, $this->mailer, $this->clock, static fn( string $email ): bool => 'stopped@example.com' === strtolower( $email ) );
		$this->license( '+10 days', Status::ACTIVE, 'Stopped@Example.com' );
		$this->license( '-4 days', Status::EXPIRED, 'stopped@example.com' );
		$kept = $this->license( '+10 days' );

		$this->assertSame( 1, $service->send_due( array( 30, 7 ) ) );
		$this->assertSame( 0, $service->send_after( 3 ) );
		$this->assertSame( array( $kept->id ), array_column( $this->mailer->sent, 'id' ) );
	}
}
