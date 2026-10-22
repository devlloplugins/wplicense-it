<?php
/**
 * A test reminder, sent to the seller.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Reminders;

use DateInterval;
use DateTimeImmutable;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\Status;

/**
 * The reminder email a customer would get, sent to the seller's own address: built by the same mailer, from a license
 * that exists only for this email (it is never saved, and no history is written). The subject starts with [Test].
 */
final class TestReminder {

	public const SUBJECT_PREFIX = '[Test] ';

	/**
	 * Constructor.
	 *
	 * @param WpReminderMailer $mailer The mailer the daily job uses.
	 */
	public function __construct( private WpReminderMailer $mailer ) {
	}

	/**
	 * The made-up license: for this product, at this address and account, expiring in the given number of days (or, for a
	 * negative number, expired that many days ago).
	 *
	 * @param int               $product_id License product, or 0 when there is none yet.
	 * @param string            $email      Where to send it.
	 * @param int|null          $user_id    The seller's account, so the renewal link is the one a customer with an account gets.
	 * @param int               $days       Days left, or minus the days since it expired.
	 * @param DateTimeImmutable $now        Now, in UTC.
	 */
	public static function sample( int $product_id, string $email, ?int $user_id, int $days, DateTimeImmutable $now ): License {
		if ( $days < 0 ) {
			return new License( 0, $product_id, $user_id, null, 'TEST-0000-0000-0000', $email, Status::EXPIRED, 1, $now->sub( new DateInterval( 'P' . -$days . 'D' ) ), $now, $now );
		}

		return new License( 0, $product_id, $user_id, null, 'TEST-0000-0000-0000', $email, Status::ACTIVE, 1, $now->add( new DateInterval( 'P' . max( 1, $days ) . 'D' ) ), $now, $now );
	}

	/**
	 * The email, as it would go out.
	 *
	 * @param License $license The made-up license.
	 * @param int     $days    Days left, or minus the days since it expired.
	 * @return array{to:string,subject:string,message:string,headers:string[]}
	 */
	public function build( License $license, int $days ): array {
		$email            = $this->mailer->build( $license, $days < 0 ? $days : max( 1, $days ) );
		$email['to']      = $license->email;
		$email['subject'] = self::SUBJECT_PREFIX . $email['subject'];

		return $email;
	}

	/**
	 * Sends it.
	 *
	 * @param License $license The made-up license.
	 * @param int     $days    Days left, or minus the days since it expired.
	 * @return bool Whether WordPress accepted it for sending.
	 */
	public function send( License $license, int $days ): bool {
		$email = $this->build( $license, $days );

		return (bool) wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}
}
