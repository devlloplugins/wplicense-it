<?php
/**
 * WordPress's translation functions for unit tests without WordPress: they return the English text.
 *
 * @package Devllo\WPLicenseIt
 */

// phpcs:disable -- Stand-ins for WordPress core functions.

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === $number ? $single : $plural; }
}
