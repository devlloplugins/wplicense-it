<?php
/**
 * Validation result.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * Outcome of checking a license key.
 */
final class ValidationResult {

	public const OK               = 'ok';
	public const NOT_FOUND        = 'not_found';
	public const PRODUCT_MISMATCH = 'product_mismatch';
	public const EMAIL_MISMATCH   = 'email_mismatch';
	public const EXPIRED          = 'expired';
	public const REVOKED          = 'revoked';
	public const REFUNDED         = 'refunded';

	/**
	 * Constructor.
	 *
	 * @param string       $code    One of the constants above.
	 * @param License|null $license The license, when one was found.
	 */
	public function __construct(
		public string $code,
		public ?License $license = null
	) {
	}

	/**
	 * Whether the license is valid.
	 */
	public function is_valid(): bool {
		return self::OK === $this->code;
	}
}
