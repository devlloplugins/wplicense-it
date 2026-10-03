<?php
/**
 * Recording event log for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Licenses\EventLog;

/**
 * Remembers recorded events.
 */
final class RecordingEventLog implements EventLog {

	/**
	 * Recorded events.
	 *
	 * @var array<int, array{license_id:int,type:string,data:array<string,mixed>}>
	 */
	public array $events = array();

	public function record( int $license_id, string $type, array $data = array() ): void {
		$this->events[] = array(
			'license_id' => $license_id,
			'type'       => $type,
			'data'       => $data,
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
