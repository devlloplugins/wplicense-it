<?php
/**
 * Protected storage for product packages.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Files;

/**
 * The uploads/wplit-files folder, where product zips are kept. The zips are only ever handed out
 * through the license API (after the license is checked), so the web server must not serve them directly.
 */
final class ProtectedStorage {

	public const VERSION_OPTION = 'wplit_storage_version';
	public const VERSION        = '2.0';

	/**
	 * The folder's path.
	 */
	public static function directory(): string {
		$uploads = wp_upload_dir();

		return $uploads['basedir'] . '/wplit-files';
	}

	/**
	 * Creates the folder and its protection files if they are missing or out of date, once per version.
	 */
	public static function maybe_create(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::create( self::directory() );
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Creates a folder and writes its protection files.
	 *
	 * @param string $directory Folder path.
	 */
	public static function create( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $directory ) : mkdir( $directory, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback without WordPress.
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small static files in our own folder.
		@file_put_contents( $directory . '/.htaccess', self::htaccess() );
		@file_put_contents( $directory . '/index.php', "<?php\n// Silence is golden.\n" );
		// phpcs:enable
	}

	/**
	 * Apache rules that deny direct access to zip files (Apache 2.4 and 2.2).
	 *
	 * nginx ignores .htaccess, so there the folder must be denied in the server configuration.
	 */
	public static function htaccess(): string {
		return "<FilesMatch \"\\.zip$\">\n"
			. "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
			. "</FilesMatch>\n";
	}
}
