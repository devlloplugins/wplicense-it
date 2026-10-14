<?php
/**
 * Loads the product edit screen as the admin user and prints whatever goes wrong, with errors shown.
 * Used by CI when the browser tests fail, to turn a bare HTTP 500 into a message.
 *
 *   php tests/env/diagnose.php
 */

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';

ini_set( 'display_errors', '1' );
error_reporting( E_ALL ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- A diagnostic that has to show every error.

$_SERVER['HTTP_HOST']      = '127.0.0.1:8080';
$_SERVER['REQUEST_URI']    = '/wp-admin/post-new.php?post_type=wplit_product';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['post_type']         = 'wplit_product';
$_REQUEST['post_type']     = 'wplit_product';

define( 'WP_ADMIN', true );
require $root . '/wordpress/wp-load.php';

$info  = 'PHP ' . PHP_VERSION . ', memory_limit ' . ini_get( 'memory_limit' ) . ', xdebug ' . ( extension_loaded( 'xdebug' ) ? 'on' : 'off' ) . "\n";
$info .= 'Plugin active: ' . ( is_plugin_active( 'wplicense-it/wplicense-it.php' ) ? 'yes' : 'no' ) . "\n";

wp_set_current_user( 1 );

// The screen ends the request itself, so the summary is produced when the output buffer is flushed.
ob_start(
	static function ( $html ) use ( $info ) {
		$error = error_get_last();

		return $info . 'Rendered ' . strlen( $html ) . ' bytes, has #title: ' . ( false !== strpos( $html, 'id="title"' ) ? 'yes' : 'no' ) . "\n"
			. ( $error ? 'LAST ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . "\n" : '' )
			. ( false === strpos( $html, 'id="title"' ) ? "Start of the output:\n" . substr( wp_strip_all_tags( $html ), 0, 1500 ) . "\n" : '' );
	}
);

try {
	require ABSPATH . 'wp-admin/post-new.php';
} catch ( Throwable $t ) {
	ob_end_clean();
	echo $info . "\nEXCEPTION: " . get_class( $t ) . ': ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine() . "\n" . $t->getTraceAsString() . "\n";
	exit( 1 );
}
