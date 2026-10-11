<?php
/**
 * In-memory event log.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\DryRun;

use Devllo\WPLicenseIt\Licenses\EventLog;

/**
 * Remembers recorded events. Nothing is written to the database.
 */
final class MemoryEventLog implements EventLog {

	/**
	 * Recorded events.
	 *
	 * @var array<int, array{license_id:int,type:string,data:array<string,mixed>,created_at:\DateTimeImmutable}>
	 */
	public array $events = array();

	public function record( int $license_id, string $type, array $data = array() ): void {
		$this->events[] = array(
			'license_id' => $license_id,
			'type'       => $type,
			'data'       => $data,
			'created_at' => new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ),
		);
	}

	public function for_license( int $license_id, int $limit = 50 ): array {
		$found = array_filter( $this->events, static fn( array $event ): bool => $event['license_id'] === $license_id );

		return array_map(
			static fn( array $event ): array => array(
				'type'       => $event['type'],
				'data'       => $event['data'],
				'created_at' => $event['created_at'],
			),
			array_slice( array_reverse( array_values( $found ) ), 0, $limit )
		);
	}

	/**
	 * Event types in the order they were recorded.
	 *
	 * @return string[]
	 */
	public function types(): array {
		return array_column( $this->events, 'type' );
	}
}
