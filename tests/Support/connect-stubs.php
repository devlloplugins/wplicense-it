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

// What the Plans box calls when it draws itself.
require_once __DIR__ . '/wp-transient-stubs.php';

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="NONCE">'; }
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=NONCE'; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://shop.example.com/wp-admin/' . $path; }
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id, $context = 'display' ) { return 'https://shop.example.com/wp-admin/post.php?post=' . $id . '&action=edit'; }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $value, $current, $echo = true ) { return (string) $value === (string) $current ? ' selected="selected"' : ''; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
}

// Records the boxes the product screen adds, so a test can check every tab's boxes exist.
if ( ! function_exists( 'add_meta_box' ) ) {
	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
		$GLOBALS['wplit_test_meta_boxes'][ $id ] = array( $title, $callback, $context );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
}

// What the product save hook calls.
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) { return 'good-nonce' === $nonce ? 1 : false; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) { return $GLOBALS['wplit_test_can'] ?? true; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	// Like WordPress, this removes slashes from what it is given.
	function update_post_meta( $id, $key, $value ) { $GLOBALS['wplit_test_post_meta'][ $id ][ $key ] = wp_unslash( $value ); return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) { unset( $GLOBALS['wplit_test_post_meta'][ $id ][ $key ] ); return true; }
}

// Records the hooks a class registers, so a test can check which hook it uses.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['wplit_test_actions'][] = array( $hook, $callback ); return true; }
}
