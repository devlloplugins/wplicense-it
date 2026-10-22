<?php
/**
 * Error for a package that cannot be read.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Packages;

/**
 * Thrown when an uploaded zip is not a usable plugin or theme. The message is written for the seller and is safe to show.
 */
final class PackageException extends \RuntimeException {
}
