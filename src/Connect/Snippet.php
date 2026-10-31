<?php
/**
 * The code a seller pastes into their plugin or theme.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Connect;

/**
 * Writes the wplicense_it_client() call with the server address and product ID already filled in.
 *
 * Every value is written as a quoted PHP string with quotes and backslashes escaped, so a product name such as
 * Bob's "Pro" Plugin still gives code that runs.
 */
final class Snippet {

	public const FOLDER = 'vendor/wplicense-it-client';

	/**
	 * Builds the snippet.
	 *
	 * @param string $type       'plugin' or 'theme'.
	 * @param string $name       Name shown to customers.
	 * @param string $slug       Plugin or theme folder name.
	 * @param string $server     Address of this site, such as https://shop.example.com.
	 * @param int    $product_id License product ID.
	 */
	public static function generate( string $type, string $name, string $slug, string $server, int $product_id ): string {
		$theme = 'theme' === $type;
		$slug  = self::clean_slug( $slug, $name );
		$name  = self::clean_text( $name );

		$lines   = array();
		$lines[] = $theme ? '// Add this to your theme\'s functions.php.' : '// Add this to the main file of your plugin.';
		$lines[] = 'require_once ' . ( $theme ? 'get_template_directory() . ' : '__DIR__ . ' ) . self::quote( '/' . self::FOLDER . '/wplicense-it-client.php' ) . ';';
		$lines[] = '';
		$lines[] = 'wplicense_it_client(';
		$lines[] = "\tarray(";

		if ( $theme ) {
			$lines[] = "\t\t'type'       => 'theme',";
			$lines[] = "\t\t'slug'       => " . self::quote( $slug ) . ',';
		} else {
			$lines[] = "\t\t'file'       => __FILE__,";
		}

		$lines[] = "\t\t'server'     => " . self::quote( rtrim( self::clean_text( $server ), '/' ) ) . ',';
		$lines[] = "\t\t'product_id' => " . $product_id . ',';
		$lines[] = "\t\t'name'       => " . self::quote( $name ) . ',';
		$lines[] = "\t\t'version'    => " . ( $theme
			? 'wp_get_theme( ' . self::quote( $slug ) . ' )->get( \'Version\' )'
			: 'get_file_data( __FILE__, array( \'Version\' => \'Version\' ) )[\'Version\']' ) . ',';
		$lines[] = "\t)";
		$lines[] = ');';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The code that runs a licensed feature only with a license, for the product's folder name.
	 *
	 * @param string $name Name shown to customers.
	 * @param string $slug Plugin or theme folder name.
	 */
	public static function gate( string $name, string $slug ): string {
		$lines   = array();
		$lines[] = '// Run your licensed features only when the license allows it. An expired license keeps them on and only stops';
		$lines[] = "// updates; add 'expired' => 'stop' to the wplicense_it_client() call to switch them off when it expires.";
		$lines[] = 'if ( function_exists( \'wplicense_it_license_active\' ) && wplicense_it_license_active( ' . self::quote( self::clean_slug( $slug, $name ) ) . ' ) ) {';
		$lines[] = "\t// Your licensed features: load your pro code, register its hooks, show its settings.";
		$lines[] = '}';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * A safe folder name: lower-case letters, digits, dashes and underscores. Falls back to the name.
	 *
	 * @param string $slug Folder name from the package, may be empty.
	 * @param string $name Product name.
	 */
	public static function clean_slug( string $slug, string $name ): string {
		foreach ( array( $slug, $name ) as $candidate ) {
			$candidate = strtolower( trim( (string) preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $candidate ), '-' ) );
			if ( '' !== $candidate ) {
				return substr( $candidate, 0, 100 );
			}
		}

		return 'my-product';
	}

	/**
	 * A single-quoted PHP string.
	 *
	 * @param string $value Text.
	 */
	private static function quote( string $value ): string {
		return "'" . strtr(
			$value,
			array(
				'\\' => '\\\\',
				"'"  => "\\'",
			)
		) . "'";
	}

	/**
	 * Text on one line, without control characters.
	 *
	 * @param string $value Text.
	 */
	private static function clean_text( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}
}
