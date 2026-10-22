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
	 * Licenses this order had ended and brought back.
	 *
	 * @var int
	 */
	public int $reinstated = 0;

	/**
	 * Problems and notable decisions, as order note lines.
	 *
	 * @var string[]
	 */
	public array $notes = array();

	/**
	 * What went wrong and left the customer without something they are owed. These are also in the notes.
	 *
	 * @var string[]
	 */
	public array $problems = array();

	/**
	 * Records something that went wrong, in the order note and in the problems.
	 *
	 * @param string $text What went wrong.
	 */
	public function problem( string $text ): void {
		$this->notes[]    = $text;
		$this->problems[] = $text;
	}

	/**
	 * Whether anything changed.
	 */
	public function changed(): bool {
		return $this->issued + $this->renewed + $this->revoked + $this->reinstated > 0 || array() !== $this->notes;
	}
}
