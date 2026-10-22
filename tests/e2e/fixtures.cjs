/**
 * Files the browser tests upload: a tiny sample plugin in any version, a zip that is not a plugin, a big file and a PNG.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const REPO = path.resolve( __dirname, '../..' );
const DIR = fs.mkdtempSync( path.join( os.tmpdir(), 'wplit-fixtures-' ) );

function write( file, text ) {
	fs.mkdirSync( path.dirname( file ), { recursive: true } );
	fs.writeFileSync( file, text );
}

function zip( cwd, out, ...items ) {
	if ( fs.existsSync( out ) ) { fs.unlinkSync( out ); }
	execFileSync( 'zip', [ '-qr', out, ...items ], { cwd } );
	return out;
}

/** The sample plugin (folder wplit-sample) as a zip. changelog is the readme text under "== Changelog ==". */
function samplePlugin( version, changelog, requiresPhp ) {
	const root = path.join( DIR, 'build-' + version );
	fs.rmSync( root, { recursive: true, force: true } );
	const plugin = path.join( root, 'wplit-sample' );
	write( path.join( plugin, 'wplit-sample.php' ), `<?php
/*
Plugin Name: WPLit Sample
Description: A tiny plugin for trying WPLicense It.
Version: ${ version }
Requires at least: 6.2
Requires PHP: ${ requiresPhp || '7.4' }
Text Domain: wplit-sample
*/
defined( 'ABSPATH' ) || exit;
// STEP 1: paste the code from the product's Connect tab below this line.
add_action( 'admin_notices', function () {
	if ( ! function_exists( 'wplicense_it_license_active' ) ) { echo '<div class="notice notice-warning"><p>WPLit Sample ${ version }: no license code yet.</p></div>'; return; }
	$active = wplicense_it_license_active( 'wplit-sample' );
	printf( '<div class="notice notice-%1$s"><p>WPLit Sample ${ version }: the license is <strong>%2$s</strong>.</p></div>', $active ? 'success' : 'warning', $active ? 'active' : 'not active' );
} );
` );
	write( path.join( plugin, 'readme.txt' ), `=== WPLit Sample ===
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: ${ requiresPhp || '7.4' }
Stable tag: ${ version }

== Description ==

Sample.

== Changelog ==

${ changelog || '= ' + version + ' =\n* Release ' + version + '.\n' }`
	);
	fs.cpSync( path.join( REPO, 'sdk' ), path.join( plugin, 'vendor/wplicense-it-client' ), { recursive: true, filter: ( src ) => ! /phpcs\.xml\.dist$/.test( src ) } );
	return zip( root, path.join( DIR, 'wplit-sample-' + version + ( requiresPhp ? '-php' + requiresPhp : '' ) + '.zip' ), 'wplit-sample' );
}

/**
 * A tiny classic theme (folder wplit-sample-theme) with the client SDK, as a zip. code is the Connect tab's code,
 * written into functions.php; changelog is the readme text under "== Changelog ==".
 */
function sampleTheme( version, changelog, code ) {
	const root = path.join( DIR, 'theme-build-' + version );
	fs.rmSync( root, { recursive: true, force: true } );
	const theme = path.join( root, 'wplit-sample-theme' );
	write( path.join( theme, 'style.css' ), `/*
Theme Name: WPLit Sample Theme
Description: A tiny theme for trying WPLicense It.
Version: ${ version }
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
*/
` );
	write( path.join( theme, 'index.php' ), '<?php get_header(); while ( have_posts() ) { the_post(); the_content(); } get_footer();\n' );
	write( path.join( theme, 'functions.php' ), '<?php\n' + ( code || '// The Connect code goes here.' ) + '\n' );
	write( path.join( theme, 'readme.txt' ), `=== WPLit Sample Theme ===
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: ${ version }

== Description ==

A sample theme.

== Changelog ==

${ changelog || '= ' + version + ' =\n* Release ' + version + '.\n' }`
	);
	fs.cpSync( path.join( REPO, 'sdk' ), path.join( theme, 'vendor/wplicense-it-client' ), { recursive: true, filter: ( src ) => ! /phpcs\.xml\.dist$/.test( src ) } );
	return zip( root, path.join( DIR, 'wplit-sample-theme-' + version + '.zip' ), 'wplit-sample-theme' );
}

/** A zip with no plugin header. */
function notAPlugin() {
	const root = path.join( DIR, 'not-a-plugin' );
	write( path.join( root, 'stuff', 'hello.txt' ), 'hello' );
	return zip( root, path.join( DIR, 'not-a-plugin.zip' ), 'stuff' );
}

/** A real zip of the given size in bytes (random content does not compress). */
function bigZip( bytes ) {
	const root = path.join( DIR, 'big-' + bytes );
	fs.mkdirSync( root, { recursive: true } );
	fs.writeFileSync( path.join( root, 'data.bin' ), require( 'crypto' ).randomBytes( bytes ) );
	return zip( root, path.join( DIR, 'big-' + bytes + '.zip' ), 'data.bin' );
}

/** A 1x1 PNG. */
function png( name ) {
	const file = path.join( DIR, name );
	fs.writeFileSync( file, Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64' ) );
	return file;
}

/** A text file pretending to be a PNG. */
function fakePng( name ) {
	const file = path.join( DIR, name );
	fs.writeFileSync( file, 'this is not an image' );
	return file;
}

module.exports = { DIR, samplePlugin, sampleTheme, notAPlugin, bigZip, png, fakePng };
