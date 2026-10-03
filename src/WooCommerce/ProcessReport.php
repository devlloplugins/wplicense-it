<?php
/**
 * Result of processing an order.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * What happened, for the order note.
 */
final class ProcessReport {

	/**
	 * Licenses created.
	 *
	 * @var int
	 */
	public int $issued = 0;

	/**
	 * Licenses renewed.
	 *
	 * @var int
	 */
	public int $renewed = 0;

	/**
	 * Licenses revoked or refunded.
	 *
	 * @var int
	 */
	public int $revoked = 0;

	/**
	 * Problems and notable decisions, as order note lines.
	 *
	 * @var string[]
	 */
	public array $notes = array();

	/**
	 * Whether anything changed.
	 */
	public function changed(): bool {
		return $this->issued + $this->renewed + $this->revoked > 0 || array() !== $this->notes;
	}
}
