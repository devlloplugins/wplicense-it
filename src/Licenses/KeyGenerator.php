<?php
/**
 * License key generator.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Licenses;

/**
 * Generates random, readable license keys such as K7QM2-9XDHB-...
 *
 * Six groups of five characters from a 32 character alphabet (no 0, O, 1, I)
 * give 150 bits of randomness.
 */
final class KeyGenerator {

	private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	private const GROUPS   = 6;
	private const GROUP_LENGTH = 5;

	/**
	 * Generates a key using a cryptographically secure source.
	 */
	public function generate(): string {
		$groups = array();
		$max    = strlen( self::ALPHABET ) - 1;

		for ( $group = 0; $group < self::GROUPS; $group++ ) {
			$chars = '';
			for ( $i = 0; $i < self::GROUP_LENGTH; $i++ ) {
				$chars .= self::ALPHABET[ random_int( 0, $max ) ];
			}
			$groups[] = $chars;
		}

		return implode( '-', $groups );
	}
}
