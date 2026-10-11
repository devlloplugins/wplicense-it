<?php
/**
 * Plain text cleaning.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

/**
 * Cleans text typed into the product form.
 */
final class Text {

	/**
	 * One line of plain text: no tags, no control characters, single spaces, cut to a length.
	 *
	 * @param mixed $value  Submitted value. Anything that is not a string or number gives ''.
	 * @param int   $length Maximum length in characters.
	 */
	public static function line( $value, int $length ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = strip_tags( (string) $value );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}

	/**
	 * A WordPress version such as 6.2 or 6.7.1. Anything else gives ''.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function wp_version( $value ): string {
		$version = self::line( $value, 20 );

		return 1 === preg_match( '/^[0-9]+(?:\.[0-9]+){0,2}(?:-[A-Za-z0-9.]+)?$/', $version ) ? $version : '';
	}
}
