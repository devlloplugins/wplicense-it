<?php
/**
 * Prints an HTML page with the product screen's tab bar and stand-in boxes, for the browser test.
 *
 * Usage: php tests/js/render-tabs.php > page.html
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Devllo\WPLicenseIt\Autoloader::register( dirname( __DIR__, 2 ) . '/src/' );
require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Admin\ProductScreen;

$post            = new WP_Post();
$post->ID        = 7;
$post->post_type = 'wplit_product';

echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Product</title></head><body>\n<h1>Edit product</h1>\n";
( new ProductScreen() )->render_tabs( $post );

foreach ( array_merge( array_merge( ...array_values( ProductScreen::TABS ) ), array( 'postdivrich', 'submitdiv' ) ) as $id ) {
	echo '<div id="' . htmlspecialchars( $id ) . '" class="postbox"><h2>' . htmlspecialchars( $id ) . "</h2></div>\n";
}

echo "</body></html>\n";
