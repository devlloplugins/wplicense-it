<?php
/**
 * A page of search results.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * One page of licenses and the total number of matches.
 */
final class LicensePage {

	/**
	 * Constructor.
	 *
	 * @param License[] $items Licenses on this page.
	 * @param int       $total Matches across all pages.
	 */
	public function __construct( public array $items, public int $total ) {
	}
}
