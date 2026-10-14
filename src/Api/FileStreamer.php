<?php
/**
 * Sends a package file to the client.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Api;

/**
 * Streams a zip file with download headers, then ends the request.
 */
final class FileStreamer {

	/**
	 * Streams a file and exits.
	 *
	 * @param string $path Absolute path of an existing file.
	 */
	public static function send( string $path ): void {
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a large file.
		exit;
	}
}
