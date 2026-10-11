<?php
/**
 * Asks whether this site can reach its own license API.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

/**
 * Makes a request from the server to its own license API.
 */
interface ReachabilityProbe {

	/**
	 * Tries the request.
	 *
	 * @return array{ok:bool,message:string} Whether the API answered as expected, and what was found.
	 */
	public function probe(): array;
}
