<?php
/**
 * Activation result.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * Outcome of activating or deactivating a site.
 */
final class ActivationResult {

	public const ACTIVATED      = 'activated';
	public const ALREADY_ACTIVE = 'already_active';
	public const DEACTIVATED    = 'deactivated';
	public const NOT_ACTIVE     = 'not_active';
	public const LIMIT_REACHED  = 'limit_reached';
	public const INVALID_SITE   = 'invalid_site';

	/**
	 * Constructor.
	 *
	 * @param string          $code       One of the constants above, or a ValidationResult code
	 *                                    when the license itself is not valid.
	 * @param License|null    $license    The license, when one was found.
	 * @param Activation|null $activation The activation, when one was created or changed.
	 */
	public function __construct(
		public string $code,
		public ?License $license = null,
		public ?Activation $activation = null
	) {
	}

	/**
	 * Whether the requested change is in effect.
	 */
	public function is_success(): bool {
		return in_array(
			$this->code,
			array( self::ACTIVATED, self::ALREADY_ACTIVE, self::DEACTIVATED ),
			true
		);
	}
}
