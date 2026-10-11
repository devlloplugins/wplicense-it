<?php
/**
 * The report of a test purchase.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

/**
 * Every check of one test purchase, in order.
 */
final class TestReport {

	/**
	 * @var CheckResult[]
	 */
	public $results;

	/**
	 * Constructor.
	 *
	 * @param CheckResult[] $results Results, in the order the checks ran.
	 */
	public function __construct( array $results ) {
		$this->results = $results;
	}

	/**
	 * Whether the plan may be published: nothing failed or was skipped. Warnings do not count against it.
	 */
	public function passed(): bool {
		foreach ( $this->results as $result ) {
			if ( CheckResult::FAIL === $result->status || CheckResult::SKIP === $result->status ) {
				return false;
			}
		}

		return array() !== $this->results;
	}

	/**
	 * Whether any check gave a warning.
	 */
	public function has_warnings(): bool {
		foreach ( $this->results as $result ) {
			if ( CheckResult::WARN === $result->status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One result by check ID.
	 *
	 * @param string $id Check ID.
	 */
	public function get( string $id ): ?CheckResult {
		foreach ( $this->results as $result ) {
			if ( $result->id === $id ) {
				return $result;
			}
		}

		return null;
	}
}
