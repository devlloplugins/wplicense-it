<?php
/**
 * Stand-in for WordPress's wpdb class, so repositories can be constructed in unit tests.
 *
 * @package Devllo\WPLicenseIt
 */

// phpcs:disable

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $insert_id = 0;
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
