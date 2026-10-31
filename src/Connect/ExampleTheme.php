<?php
/**
 * A small working theme that uses the client SDK, made for one product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Connect;

/**
 * The theme counterpart of ExamplePlugin: a minimal standalone theme (no parent theme needed) with the SDK in
 * vendor/wplicense-it-client, the wplicense_it_client() call for themes in functions.php (the same code the Connect box
 * shows), a feature that is only on while the license allows it, and a readme with a changelog. ExamplePlugin::build()
 * zips it with the SDK.
 *
 * The folder name is the product's theme folder, so that updates of the real theme find it.
 */
final class ExampleTheme {

	public const VERSION = '1.0.0';

	/**
	 * The files of the example, without the SDK, keyed by their path inside the zip.
	 *
	 * @param string $name       Product name.
	 * @param string $slug       Theme folder name (may be empty: made from the name).
	 * @param string $server     Address of this site.
	 * @param int    $product_id License product ID.
	 * @return array<string, string>
	 */
	public static function files( string $name, string $slug, string $server, int $product_id ): array {
		$slug = Snippet::clean_slug( $slug, $name );
		// The name goes into a comment block, a stylesheet header and a readme: one line, nothing that ends a comment.
		$name    = trim( (string) preg_replace( '/[\s\x00-\x1F\x7F*\/<>]+/', ' ', $name ) );
		$name    = '' !== $name ? $name : $slug;
		$snippet = substr( strstr( Snippet::generate( 'theme', $name, $slug, $server, $product_id ), "\n" ), 1 );
		$quoted  = var_export( $slug, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes a PHP string literal.
		$page    = var_export( 'options-general.php?page=' . $slug . '-license', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes a PHP string literal.

		$style = <<<CSS
/*
Theme Name: {$name}
Description: An example theme licensed with WPLicense It. Use it to try activation and updates, then copy what you need.
Version: {VERSION}
Requires at least: 5.8
Requires PHP: 7.4
*/

body { max-width: 720px; margin: 2em auto; padding: 0 16px; font-family: sans-serif; line-height: 1.6; }

CSS;

		$functions = <<<PHP
<?php
/**
 * {$name}: an example theme licensed with WPLicense It.
 */

defined( 'ABSPATH' ) || exit;

// 1. Connect to your license server. This is the code from the product's Connect box.
{$snippet}
// 2. Check the license where it matters. wplicense_it_license_active() is what the site last heard from your server.
//    An expired license keeps your features on and only stops updates; add 'expired' => 'stop' to the call above to
//    switch them off instead. wplicense_it_license_status() answers 'active', 'expired' or 'inactive'.
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'wplicense_it_license_active' ) ) {
			return;
		}

		if ( wplicense_it_license_active( {$quoted} ) ) {
			// A licensed feature. Put yours here, or test the license before you load your premium code.
			echo '<div class="notice notice-success"><p>' . esc_html( 'Licensed theme feature is on.' ) . '</p></div>';
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( 'Licensed theme feature is off: enter your license key to turn it on and to get updates.' ),
			esc_url( admin_url( {$page} ) ),
			esc_html( 'Enter the license key' )
		);
	}
);

// The theme's stylesheet.
add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style( {$quoted}, get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
	}
);

// 3. Themes have no uninstall step in WordPress, so nothing runs when the theme is deleted.

PHP;

		$index = <<<'PHP'
<?php
/**
 * The only template: the site title and the posts.
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<h1><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></h1>
<?php
while ( have_posts() ) {
	the_post();
	echo '<article><h2><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h2>';
	the_content();
	echo '</article>';
}
wp_footer();
?>
</body>
</html>

PHP;

		$readme = <<<TXT
=== {$name} ===
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: {VERSION}

An example theme licensed with WPLicense It.

== Description ==

Install it on a test site, enter a license key under Settings > {$name} License, and upload a newer version of it as a
release of the product to see the update arrive.

== Changelog ==

= {VERSION} =
* First version.

TXT;

		return array(
			$slug . '/style.css'     => str_replace( '{VERSION}', self::VERSION, $style ),
			$slug . '/functions.php' => str_replace( '{VERSION}', self::VERSION, $functions ),
			$slug . '/index.php'     => $index,
			$slug . '/readme.txt'    => str_replace( '{VERSION}', self::VERSION, $readme ),
		);
	}
}
