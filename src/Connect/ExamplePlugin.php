<?php
/**
 * A small working plugin that uses the client SDK, made for one product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Connect;

use RuntimeException;
use ZipArchive;

/**
 * Builds a plugin a seller can install on a test site to try licensing and updates end to end, or copy from: the SDK in
 * vendor/wplicense-it-client, the wplicense_it_client() call with this server and product filled in (the same code the
 * Connect box shows), a feature that is only on while the license is active, a notice that points to the license page,
 * and a readme with a changelog for the "View details" popup. It needs no uninstall.php: the SDK cleans up.
 *
 * The folder name is the product's plugin folder, so that updates of the real plugin find it.
 */
final class ExamplePlugin {

	public const VERSION = '1.0.0';

	/**
	 * The files of the example, without the SDK, keyed by their path inside the zip.
	 *
	 * @param string $name       Product name.
	 * @param string $slug       Plugin folder name (may be empty: made from the name).
	 * @param string $server     Address of this site.
	 * @param int    $product_id License product ID.
	 * @return array<string, string>
	 */
	public static function files( string $name, string $slug, string $server, int $product_id ): array {
		$slug = Snippet::clean_slug( $slug, $name );
		// The name goes into a comment block and a readme: no line breaks, and nothing that could end the comment.
		$name    = trim( (string) preg_replace( '/[\s\x00-\x1F\x7F*\/<>]+/', ' ', $name ) );
		$name    = '' !== $name ? $name : $slug;
		$snippet = substr( strstr( Snippet::generate( 'plugin', $name, $slug, $server, $product_id ), "\n" ), 1 );
		// Without its first comment line.
		$quoted = var_export( $slug, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes a PHP string literal.
		$page   = var_export( 'options-general.php?page=' . $slug . '-license', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes a PHP string literal.

		$main = <<<PHP
<?php
/**
 * Plugin Name: {$name}
 * Description: An example plugin licensed with WPLicense It. Use it to try activation and updates, then copy what you need.
 * Version: {VERSION}
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

// 1. Connect to your license server. This is the code from the product's Connect box.
{$snippet}
// 2. Check the license where it matters. wplicense_it_license_active() is what the site last heard from your server.
//    A network problem never turns a license off; only your server does (revoked, refunded, site deactivated).
//    An expired license keeps your features on and only stops updates; add 'expired' => 'stop' to the call above to
//    switch them off instead. wplicense_it_license_status() answers 'active', 'expired' or 'inactive'.
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'wplicense_it_license_active' ) ) {
			return;
		}

		if ( wplicense_it_license_active( {$quoted} ) ) {
			// A licensed feature. Put yours here, or test the license before you load your pro code.
			echo '<div class="notice notice-success"><p>' . esc_html( 'Licensed feature is on.' ) . '</p></div>';
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( 'Licensed feature is off: enter your license key to turn it on and to get updates.' ),
			esc_url( admin_url( {$page} ) ),
			esc_html( 'Enter the license key' )
		);
	}
);

// 3. Nothing to do when the plugin is deleted: the SDK removes what it stored. If you add your own uninstall.php,
//    call wplicense_it_client_forget( {$quoted} ) in it (see vendor/wplicense-it-client/README.md).

PHP;

		$readme = <<<TXT
=== {$name} ===
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: {VERSION}

An example plugin licensed with WPLicense It.

== Description ==

Install it on a test site, enter a license key under Settings > {$name} License, and upload a newer version of it as a
release of the product to see the update arrive.

== Changelog ==

= {VERSION} =
* First version.

TXT;

		return array(
			$slug . '/' . $slug . '.php' => str_replace( '{VERSION}', self::VERSION, $main ),
			$slug . '/readme.txt'        => str_replace( '{VERSION}', self::VERSION, $readme ),
		);
	}

	/**
	 * Builds the zip in a temp file: the example's files and the SDK under vendor/wplicense-it-client. The caller deletes it.
	 *
	 * @param array<string, string> $files   The example's files (see files()).
	 * @param string                $sdk_dir The sdk folder.
	 * @return string Path of the zip.
	 * @throws RuntimeException If the zip cannot be made.
	 */
	public static function build( array $files, string $sdk_dir ): string {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'The PHP zip extension is not available.' );
		}

		$sdk_dir = rtrim( $sdk_dir, '/\\' );
		$folder  = strtok( (string) array_key_first( $files ), '/' );
		$path    = tempnam( sys_get_temp_dir(), 'wplit-example' );

		if ( false === $path || false === $folder ) {
			throw new RuntimeException( 'Could not create a temporary file.' );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best effort cleanup.
			throw new RuntimeException( 'Could not create the zip.' );
		}

		foreach ( $files as $name => $content ) {
			$zip->addFromString( $name, $content );
		}

		foreach ( SdkZip::files( $sdk_dir ) as $relative ) {
			$zip->addFile( $sdk_dir . '/' . $relative, $folder . '/' . Snippet::FOLDER . '/' . $relative );
		}

		if ( ! $zip->close() ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best effort cleanup.
			throw new RuntimeException( 'Could not write the zip.' );
		}

		return $path;
	}
}
