<?php
/**
 * Expiry reminders.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Reminders;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseRepository;
use Devllo\WPLicenseIt\Privacy\PrivacyService;

/**
 * Emails customers before their license expires.
 *
 * With the days 30 and 7, a license gets one email when it is within 30 days of its expiry and one when it is within 7. A license
 * seen for the first time at 5 days gets only the 7 day email, not both. What was sent is written to the license history, keyed by
 * the expiry date, so nothing is sent twice, and a renewal (a new expiry date) starts a new cycle.
 */
final class ReminderService {

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository $licenses Licenses.
	 * @param EventLog          $events   License history.
	 * @param ReminderMailer    $mailer   Sends the email.
	 * @param callable|null     $clock    Returns the current time (UTC). Tests pass a fixed one.
	 */
	public function __construct(
		private LicenseRepository $licenses,
		private EventLog $events,
		private ReminderMailer $mailer,
		private $clock = null
	) {
	}

	/**
	 * Sends every reminder that is due.
	 *
	 * @param int[] $days      Days before expiry, any order.
	 * @param int   $max_sends Most emails in one run. The rest go out on the next run.
	 * @param int   $batch     Licenses read at a time.
	 * @return int Emails sent.
	 */
	public function send_due( array $days, int $max_sends = 500, int $batch = 200 ): int {
		$days = array_values( array_unique( array_filter( array_map( 'intval', $days ), static fn( int $d ): bool => $d > 0 ) ) );
		sort( $days );

		if ( array() === $days || $max_sends < 1 ) {
			return 0;
		}

		$now   = $this->now();
		$until = $now->modify( '+' . max( $days ) . ' days' );
		$after = null;
		$sent  = 0;

		$fetched = $batch;

		while ( $fetched === $batch ) {
			$licenses = $this->licenses->find_expiring( $now, $until, $after, $batch );
			$fetched  = count( $licenses );

			foreach ( $licenses as $license ) {
				$after = $license;

				if ( $sent >= $max_sends ) {
					return $sent;
				}

				if ( $this->remind( $license, $days, $now ) ) {
					++$sent;
				}
			}
		}

		return $sent;
	}

	/**
	 * Sends the reminder a license is due for, if there is one and it was not sent yet.
	 *
	 * @param License           $license The license.
	 * @param int[]             $days    Days before expiry, smallest first.
	 * @param DateTimeImmutable $now     Current time.
	 */
	private function remind( License $license, array $days, DateTimeImmutable $now ): bool {
		if ( null === $license->expires_at || false === filter_var( trim( $license->email ), FILTER_VALIDATE_EMAIL ) || PrivacyService::is_placeholder( trim( $license->email ) ) ) {
			return false;
		}

		$left = (int) ceil( ( $license->expires_at->getTimestamp() - $now->getTimestamp() ) / 86400 );
		$due  = null;

		// The smallest threshold the license has reached.
		foreach ( $days as $day ) {
			if ( $left <= $day ) {
				$due = $day;
				break;
			}
		}

		if ( null === $due || $left < 1 ) {
			return false;
		}

		$expires = $license->expires_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM );

		foreach ( $this->events->of_type( $license->id, EventLog::REMINDER, 50 ) as $event ) {
			if ( ( $event['data']['expires_at'] ?? null ) === $expires && (int) ( $event['data']['days'] ?? 0 ) <= $due ) {
				return false;
			}
		}

		// Lets a site owner skip some licenses, for example those of a customer who asked not to be emailed.
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'wplicense_it_send_reminder', true, $license, $due ) ) {
			return false;
		}

		if ( ! $this->mailer->send( $license, $left ) ) {
			return false;
		}

		$this->events->record(
			$license->id,
			EventLog::REMINDER,
			array(
				'days'       => $due,
				'expires_at' => $expires,
			)
		);

		return true;
	}

	/**
	 * Current time in UTC.
	 */
	private function now(): DateTimeImmutable {
		return null === $this->clock ? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) : ( $this->clock )();
	}
}
