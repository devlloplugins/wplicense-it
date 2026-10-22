<?php
/**
 * Migration progress.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

/**
 * Where the migration is, so it can stop and resume.
 */
final class MigrationState {

	public const PENDING         = 'pending';
	public const RUNNING         = 'running';
	public const NEEDS_ATTENTION = 'needs_attention';
	public const DONE            = 'done';

	public const PHASE_LICENSES = 'licenses';
	public const PHASE_ORDERS   = 'orders';
	public const PHASE_PRODUCTS = 'products';
	public const PHASE_VERIFY   = 'verify';

	private const MAX_PROBLEMS = 50;
	private const MAX_NOTES    = 10;

	public string $status         = self::PENDING;
	public string $phase          = self::PHASE_LICENSES;
	public int $license_cursor    = 0;
	public int $order_cursor      = 0;
	public int $licenses_migrated = 0;
	public int $licenses_skipped  = 0;
	public int $orders_migrated   = 0;
	public int $orders_skipped    = 0;
	public int $products_updated  = 0;
	public int $warnings          = 0;

	/**
	 * True once the 2.0 tables became the source of truth. A last pass then picks up
	 * anything 1.x wrote in the meantime.
	 *
	 * @var bool
	 */
	public bool $finalized = false;

	/**
	 * Reasons rows were skipped or verification failed.
	 *
	 * @var string[]
	 */
	public array $problems = array();

	/**
	 * Start of the lines that say what the verification found.
	 */
	public const VERIFY_PREFIX = 'Verify: ';

	/**
	 * Adds a problem (the list is capped, and notes have a smaller cap of their own).
	 *
	 * @param string $message Problem description.
	 */
	public function add_problem( string $message ): void {
		if ( 0 === strpos( $message, 'Note: ' ) ) {
			// Notes are only information. Keep a few, so that many of them cannot use up the room that rows left behind need.
			$notes = count( array_filter( $this->problems, static fn( string $problem ): bool => 0 === strpos( $problem, 'Note: ' ) ) );

			if ( $notes >= self::MAX_NOTES ) {
				return;
			}
		}

		if ( count( $this->problems ) < self::MAX_PROBLEMS ) {
			$this->problems[] = $message;
		}
	}

	/**
	 * Adds a line from the verification. These few lines say how much did not arrive, so they are kept even when the
	 * list of single problems is full.
	 *
	 * @param string $message What verification found, without the prefix.
	 */
	public function add_summary( string $message ): void {
		$this->problems[] = self::VERIFY_PREFIX . $message;
	}

	/**
	 * The problems to show: what verification found first (at most three lines, because the sample checks all say the
	 * same thing), then rows that could not be moved, then notes (which are only information), up to a limit.
	 *
	 * @param int $limit Maximum number of lines.
	 * @return string[]
	 */
	public function problems_to_show( int $limit ): array {
		$summary = array();
		$errors  = array();
		$notes   = array();

		foreach ( $this->problems as $problem ) {
			if ( 0 === strpos( $problem, self::VERIFY_PREFIX ) ) {
				$summary[] = $problem;
			} elseif ( 0 === strpos( $problem, 'Note: ' ) ) {
				$notes[] = $problem;
			} else {
				$errors[] = $problem;
			}
		}

		return array_slice( array_merge( array_slice( $summary, 0, 3 ), $errors, $notes ), 0, $limit );
	}

	/**
	 * Array form for storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Rebuilds a state from stored data, ignoring unknown keys.
	 *
	 * @param mixed $data Stored array.
	 */
	public static function from_array( $data ): self {
		$state = new self();

		if ( ! is_array( $data ) ) {
			return $state;
		}

		foreach ( get_object_vars( $state ) as $name => $default ) {
			if ( ! array_key_exists( $name, $data ) ) {
				continue;
			}

			$value = $data[ $name ];

			if ( is_int( $default ) ) {
				$state->$name = (int) $value;
			} elseif ( is_bool( $default ) ) {
				$state->$name = (bool) $value;
			} elseif ( is_array( $default ) ) {
				$state->$name = is_array( $value ) ? array_map( 'strval', $value ) : array();
			} else {
				$state->$name = (string) $value;
			}
		}

		return $state;
	}
}
