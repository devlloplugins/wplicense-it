<?php
/**
 * Translations of the client SDK's texts.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * Loads the SDK's translations. Its texts use the text domain 'wplicense-it-client' (the same for every plugin that
 * includes the SDK), from these files, the first one found for a text winning:
 * 1. the site's own: wp-content/languages/plugins/wplicense-it-client-{locale}.mo;
 * 2. the seller's: the plugin's (or theme's) languages folder, wplicense-it-client-{locale}.mo;
 * 3. the SDK's own languages folder, if a translation ships there.
 * Without any, the texts are English. sdk/languages/wplicense-it-client.pot is the template.
 */
final class I18n {

	public const DOMAIN = 'wplicense-it-client';

	/**
	 * Files already loaded.
	 *
	 * @var array<string, bool>
	 */
	private static $loaded = array();

	/**
	 * Loads what exists for the current locale, once per file. Call it on init or later.
	 *
	 * @param Config $config The registering plugin or theme.
	 */
	public static function load( Config $config ): void {
		if ( ! function_exists( 'load_textdomain' ) ) {
			return;
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$file   = self::DOMAIN . '-' . $locale . '.mo';

		foreach ( self::folders( $config ) as $folder ) {
			$path = rtrim( $folder, '/\\' ) . '/' . $file;

			if ( isset( self::$loaded[ $path ] ) || ! is_readable( $path ) ) {
				continue;
			}

			self::$loaded[ $path ] = true;
			load_textdomain( self::DOMAIN, $path );
		}
	}

	/**
	 * Where translations are looked for, in order.
	 *
	 * @param Config $config Configuration.
	 * @return string[]
	 */
	private static function folders( Config $config ): array {
		$folders = array();

		if ( defined( 'WP_LANG_DIR' ) ) {
			$folders[] = WP_LANG_DIR . '/plugins';
		}

		if ( 'plugin' === $config->type && '' !== $config->file ) {
			$folders[] = dirname( $config->file ) . '/languages';
		} elseif ( 'theme' === $config->type && function_exists( 'get_theme_root' ) ) {
			$folders[] = get_theme_root( $config->slug ) . '/' . $config->slug . '/languages';
		}

		$folders[] = dirname( __DIR__ ) . '/languages';

		return $folders;
	}
}
