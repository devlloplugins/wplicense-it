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
