<?php
/**
 * Result of one test purchase check.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

/**
 * One line of the test purchase report: what was checked, whether it passed, and what to do if not.
 */
final class CheckResult {

	public const PASS = 'pass';
	public const FAIL = 'fail';
	public const WARN = 'warn';
	public const SKIP = 'skip';

	/**
	 * Constructor.
	 *
	 * @param string $id      Short machine name of the check.
	 * @param string $label   What was checked, in plain words.
	 * @param string $status  One of the constants above.
	 * @param string $message What was found, and what to fix if it did not pass.
	 */
	public function __construct(
		public string $id,
		public string $label,
		public string $status,
		public string $message
	) {
	}
}
