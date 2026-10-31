<?php
/**
 * Plan error.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * Something a plan action could not do. The message is written for the seller and is safe to show.
 */
final class PlanException extends \RuntimeException {
}
