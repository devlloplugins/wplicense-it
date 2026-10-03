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
	public const VERSION        = '2.1';

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
		self::harden_existing();
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
	 * Makes sure a directory shows nothing if a web server lists it.
	 *
	 * Package folders are named with random tokens, so a directory listing would give them away.
	 *
	 * @param string $directory Folder path.
	 */
	public static function seal( string $directory ): void {
		if ( is_dir( $directory ) && ! is_file( $directory . '/index.php' ) ) {
			@file_put_contents( $directory . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Creates a new folder for a package, named with a random token, and returns its path with a trailing slash.
	 *
	 * Packages are only ever served through the license API. The random folder means the file is still
	 * out of reach on servers that ignore .htaccess (nginx), because its address cannot be guessed.
	 *
	 * @param string $version_directory The product version's folder.
	 * @return string The new folder.
	 */
	public static function new_package_directory( string $version_directory ): string {
		$token = self::token();
		$dir   = rtrim( $version_directory, '/' ) . '/' . $token . '/';

		function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback without WordPress.

		self::seal( dirname( $dir, 2 ) );
		self::seal( dirname( $dir ) );
		self::seal( $dir );

		return $dir;
	}

	/**
	 * A random 32 character token (192 bits).
	 */
	public static function token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Whether a package path (relative to the uploads folder) already has a random folder:
	 * wplit-files/<slug>/v<version>/<token>/<file>.zip
	 *
	 * @param string $relative Path relative to the uploads folder.
	 */
	public static function is_tokenized( string $relative ): bool {
		return false === strpos( $relative, '..' ) && 1 === preg_match( '#^wplit-files/[^/]+/v[^/]+/[a-f0-9]{32}/[^/]+$#', $relative );
	}

	/**
	 * The path a 1.x style package (wplit-files/<slug>/v<version>/<file>) should move to.
	 *
	 * @param string $relative Path relative to the uploads folder.
	 * @param string $token    Random token.
	 * @return string|null The new relative path, or null if the path does not have the 1.x shape.
	 */
	public static function tokenized_path( string $relative, string $token ): ?string {
		if ( false !== strpos( $relative, '..' ) || 1 !== preg_match( '#^(wplit-files/[^/]+/v[^/]+)/([^/]+)$#', $relative, $parts ) ) {
			return null;
		}

		return $parts[1] . '/' . $token . '/' . $parts[2];
	}

	/**
	 * Moves packages uploaded by 1.x, which sit at predictable addresses, into random folders.
	 * Safe to repeat: packages that are already in a random folder are skipped, and a package that
	 * cannot be moved stays where it is.
	 *
	 * @return int Number of packages moved.
	 */
	public static function harden_existing(): int {
		$uploads = wp_upload_dir();
		$moved   = 0;

		$ids = get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $ids as $id ) {
			$relative = (string) get_post_meta( (int) $id, 'file_dir_path', true );
			$new      = '' !== $relative ? self::tokenized_path( $relative, self::token() ) : null;

			if ( null === $new ) {
				continue;
			}

			$from = $uploads['basedir'] . '/' . $relative;
			$to   = $uploads['basedir'] . '/' . $new;

			if ( ! is_file( $from ) ) {
				continue;
			}

			$new_dir = dirname( $to );
			function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $new_dir ) : mkdir( $new_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback without WordPress.
			self::seal( dirname( $new_dir ) );
			self::seal( $new_dir );

			if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same filesystem, one atomic move.
				continue;
			}

			update_post_meta( (int) $id, 'file_dir_path', $new );
			update_post_meta( (int) $id, 'file_dir_location', $uploads['baseurl'] . '/' . $new );
			++$moved;
		}

		return $moved;
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
