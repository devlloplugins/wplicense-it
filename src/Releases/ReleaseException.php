<?php
/**
 * Release error.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Releases;

/**
 * Something a release action could not do. The message is written for the seller and is safe to show.
 */
final class ReleaseException extends \RuntimeException {
}
