<?php
/**
 * Invalid 1.x row.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use RuntimeException;

/**
 * Thrown when a 1.x row cannot be converted. The row is skipped and reported.
 */
final class InvalidLegacyRow extends RuntimeException {
}
