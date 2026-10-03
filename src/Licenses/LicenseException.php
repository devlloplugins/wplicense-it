<?php
/**
 * License exception.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

use RuntimeException;

/**
 * Thrown when an operation is not allowed, such as renewing a revoked license.
 */
final class LicenseException extends RuntimeException {

	public const NOT_FOUND      = 'not_found';
	public const INVALID_INPUT  = 'invalid_input';
	public const NOT_RENEWABLE  = 'not_renewable';

	/**
	 * Machine-readable reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Creates the exception.
	 *
	 * @param string $reason  One of the constants above.
	 * @param string $message Human-readable message.
	 */
	public function __construct( string $reason, string $message ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}

	/**
	 * Machine-readable reason.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
