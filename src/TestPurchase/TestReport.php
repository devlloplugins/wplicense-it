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
	 * Plain form, for keeping the report between page loads.
	 *
	 * @return array<int, array{id:string,label:string,status:string,message:string}>
	 */
	public function to_array(): array {
		return array_map(
			static function ( CheckResult $result ): array {
				return array(
					'id'      => $result->id,
					'label'   => $result->label,
					'status'  => $result->status,
					'message' => $result->message,
				);
			},
			$this->results
		);
	}

	/**
	 * Rebuilds a report from its plain form. Rows that are not valid are dropped.
	 *
	 * @param mixed $rows Value from to_array().
	 */
	public static function from_array( $rows ): self {
		$results = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['label'], $row['status'], $row['message'] ) && in_array( $row['status'], array( CheckResult::PASS, CheckResult::FAIL, CheckResult::WARN, CheckResult::SKIP ), true ) ) {
				$results[] = new CheckResult( (string) $row['id'], (string) $row['label'], (string) $row['status'], (string) $row['message'] );
			}
		}

		return new self( $results );
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
