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

	public string $status           = self::PENDING;
	public string $phase            = self::PHASE_LICENSES;
	public int $license_cursor      = 0;
	public int $order_cursor        = 0;
	public int $licenses_migrated   = 0;
	public int $licenses_skipped    = 0;
	public int $orders_migrated     = 0;
	public int $orders_skipped      = 0;
	public int $products_updated    = 0;
	public int $warnings            = 0;

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
	 * Adds a problem (the list is capped).
	 *
	 * @param string $message Problem description.
	 */
	public function add_problem( string $message ): void {
		if ( count( $this->problems ) < self::MAX_PROBLEMS ) {
			$this->problems[] = $message;
		}
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
