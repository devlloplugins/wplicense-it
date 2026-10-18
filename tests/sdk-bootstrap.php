<?php
/**
 * PHPUnit bootstrap for the client SDK tests alone, so they can run on PHP 7.4 without installing the plugin's own
 * development dependencies (which need a newer PHP). It loads only the SDK and the test helpers.
 *
 * @package Devllo\WPLicenseIt
 */

spl_autoload_register(
	static function ( $class ) {
		$maps = array(
			'WPLicenseIt\\Client\\'        => dirname( __DIR__ ) . '/sdk/src/',
			'Devllo\\WPLicenseIt\\Tests\\' => __DIR__ . '/',
		);

		foreach ( $maps as $prefix => $dir ) {
			if ( 0 === strpos( $class, $prefix ) ) {
				$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		}
	}
);
