<?php
/**
 * Stand-ins for the WordPress functions the client SDK's updater uses, for unit tests.
 *
 * @package Devllo\WPLicenseIt
 */

// phpcs:disable

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
	function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
	function wpautop( $text ) { return '<p>' . $text . '</p>'; }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	function download_url( $url ) { $GLOBALS['wplit_test_downloaded'][] = $url; return '/tmp/downloaded.zip'; }
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { return $GLOBALS['wplit_test_can'] ?? true; }
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://customer.example.com/wp-admin/' . $path; }
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $name ) { return $GLOBALS['wplit_test_site_transients'][ $name ] ?? false; }
}
