<?php
/**
 * Contract for sending an expiry reminder.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Reminders;

use Devllo\WPLicenseIt\Licenses\License;

/**
 * Sends one reminder email.
 */
interface ReminderMailer {

	/**
	 * Sends the reminder.
	 *
	 * @param License $license   The license that is about to expire, or that expired.
	 * @param int     $days_left Whole days until it expires (at least 1), or minus the days since it expired (the email after expiry).
	 * @return bool Whether the email was handed to the mail system. A false result is tried again on the next run.
	 */
	public function send( License $license, int $days_left ): bool;
}
