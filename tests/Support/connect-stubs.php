<?php
/**
 * Stand-ins for the two WordPress functions the generated snippet calls, for unit tests.
 *
 * @package Devllo\WPLicenseIt
 */

// phpcs:disable

if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( $file, $headers ) { return array( 'Version' => '9.9.9' ); }
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	class WPLit_Test_Theme {
		public function get( $key ) { return 'Version' === $key ? '9.9.9' : ''; }
	}
	function wp_get_theme( $slug = '' ) { return new WPLit_Test_Theme(); }
}

// What the Connect box calls when it draws itself. untrailingslashit() and esc_html() come from the SDK stubs.
require_once __DIR__ . '/sdk-wp-stubs.php';

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_status = 'publish';
		public $post_title = '';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return $GLOBALS['wplit_test_home'] ?? 'https://shop.example.com'; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) { return json_encode( $value ); }
}
