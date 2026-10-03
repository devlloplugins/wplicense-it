<?php
/**
 * Client configuration.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

use InvalidArgumentException;

/**
 * The settings a plugin or theme passes to wplicense_it_client().
 */
final class Config {

	/**
	 * License server URL, for example https://example.com.
	 *
	 * @var string
	 */
	public $server;

	/**
	 * Product ID in WPLicense It.
	 *
	 * @var int
	 */
	public $product_id;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Identifier: the plugin folder name, or the theme folder name.
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Either "plugin" or "theme".
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Plugin basename such as my-plugin/my-plugin.php, empty for themes.
	 *
	 * @var string
	 */
	public $basename;

	/**
	 * Main plugin file, empty for themes.
	 *
	 * @var string
	 */
	public $file;

	/**
	 * Admin menu parent for the license page, or empty for no page.
	 *
	 * @var string
	 */
	public $menu_parent;

	/**
	 * Admin menu title.
	 *
	 * @var string
	 */
	public $menu_title;

	/**
	 * Builds a configuration from the array a developer passes in.
	 *
	 * @param array<string, mixed> $args Settings: server, product_id, name, version and file (plugin) or slug and type (theme).
	 * @throws InvalidArgumentException If a required setting is missing or invalid.
	 */
	public static function from_array( array $args ): self {
		$config = new self();

		$config->server = rtrim( trim( (string) ( $args['server'] ?? '' ) ), '/' );
		if ( '' === $config->server || ! self::is_acceptable_server( $config->server ) ) {
			throw new InvalidArgumentException( 'WPLicense It client: "server" must be an https:// URL (http:// is only accepted for local development sites).' );
		}

		$config->product_id = (int) ( $args['product_id'] ?? 0 );
		if ( $config->product_id <= 0 ) {
			throw new InvalidArgumentException( 'WPLicense It client: "product_id" is required.' );
		}

		$config->version = trim( (string) ( $args['version'] ?? '' ) );
		if ( '' === $config->version ) {
			throw new InvalidArgumentException( 'WPLicense It client: "version" is required.' );
		}

		$config->file     = (string) ( $args['file'] ?? '' );
		$config->type     = isset( $args['type'] ) ? (string) $args['type'] : ( '' !== $config->file ? 'plugin' : '' );
		$config->basename = (string) ( $args['basename'] ?? '' );

		if ( ! in_array( $config->type, array( 'plugin', 'theme' ), true ) ) {
			throw new InvalidArgumentException( 'WPLicense It client: pass "file" (plugin) or "type" => "theme" with a "slug".' );
		}

		if ( 'plugin' === $config->type ) {
			if ( '' === $config->basename && '' !== $config->file && function_exists( 'plugin_basename' ) ) {
				$config->basename = plugin_basename( $config->file );
			}

			if ( '' === $config->basename ) {
				throw new InvalidArgumentException( 'WPLicense It client: "file" (the main plugin file, usually __FILE__) is required for plugins.' );
			}
		}

		$config->slug = self::clean_slug( (string) ( $args['slug'] ?? self::default_slug( $config ) ) );
		if ( '' === $config->slug ) {
			throw new InvalidArgumentException( 'WPLicense It client: "slug" is required for themes.' );
		}

		$config->name        = trim( (string) ( $args['name'] ?? '' ) );
		$config->name        = '' !== $config->name ? $config->name : $config->slug;
		$menu                = isset( $args['menu'] ) ? $args['menu'] : array();
		$config->menu_parent = false === $menu ? '' : (string) ( is_array( $menu ) && isset( $menu['parent'] ) ? $menu['parent'] : 'options-general.php' );
		$config->menu_title  = is_array( $menu ) && isset( $menu['title'] ) ? (string) $menu['title'] : $config->name . ' License';

		return $config;
	}

	/**
	 * The option that stores the license for this product.
	 */
	public function option_name(): string {
		return 'wplit_client_' . $this->slug;
	}

	/**
	 * The string that stands in for the package URL in WordPress's update data. The real, short-lived
	 * download link is fetched at the moment the update is installed.
	 */
	public function package_marker(): string {
		return 'wplicense-it://' . $this->slug;
	}

	/**
	 * The folder name WordPress expects after an update (the slug).
	 */
	public function folder(): string {
		return $this->slug;
	}

	/**
	 * Whether the server URL is safe to send license keys to.
	 *
	 * @param string $server Server URL without trailing slash.
	 */
	private static function is_acceptable_server( string $server ): bool {
		$parts = parse_url( $server ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Works without WordPress.

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		if ( 'https' === $parts['scheme'] ) {
			return true;
		}

		$host = strtolower( $parts['host'] );

		return 'http' === $parts['scheme'] && ( 'localhost' === $host || '127.0.0.1' === $host || self::ends_with( $host, '.test' ) || self::ends_with( $host, '.local' ) || self::ends_with( $host, '.localhost' ) );
	}

	/**
	 * Slug from the plugin basename.
	 *
	 * @param Config $config Configuration so far.
	 */
	private static function default_slug( self $config ): string {
		if ( 'plugin' !== $config->type ) {
			return '';
		}

		return false !== strpos( $config->basename, '/' ) ? dirname( $config->basename ) : basename( $config->basename, '.php' );
	}

	/**
	 * Lower-cases and keeps only characters that are safe in an option name.
	 *
	 * @param string $slug Slug.
	 */
	private static function clean_slug( string $slug ): string {
		return trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $slug ) ), '-' );
	}

	/**
	 * String suffix test (str_ends_with needs PHP 8).
	 *
	 * @param string $haystack Text.
	 * @param string $suffix   Ending.
	 */
	private static function ends_with( string $haystack, string $suffix ): bool {
		return substr( $haystack, -strlen( $suffix ) ) === $suffix;
	}
}
