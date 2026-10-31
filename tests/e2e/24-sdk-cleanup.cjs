/**
 * The client SDK cleans up after itself when a seller's plugin is deleted from a customer's site (card 76). Two small
 * plugins with the SDK are put on the customer site and deleted through the real Plugins screen, the way a customer does:
 * - one without an uninstall.php, and with a slug that is not its folder name: the SDK registered WordPress's uninstall
 *   hook, so its license, cached update data and daily check go away with no code from the seller;
 * - one with its own uninstall.php (WordPress then runs only that file), which makes the one call the README gives.
 * Another product's data on the same site is left alone.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, CLIENT, ROOT, check, finish, login, logSize, shot, start } = require( './lib.cjs' );

const CLIENT_ROOT = path.join( ROOT, 'client' );
const PLUGINS = path.join( CLIENT_ROOT, 'wp-content/plugins' );
const REPO = path.join( __dirname, '..', '..' );
const onClient = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; require_once ABSPATH . "wp-admin/includes/plugin.php"; ' + code ], { encoding: 'utf8' } ).trim();

// What the SDK stores for a slug: [ license option, cached update data, daily check scheduled ].
const stored = ( slug ) => JSON.parse( onClient( 'echo wp_json_encode( array( false !== get_option( "wplit_client_' + slug + '" ), false !== get_transient( "wplit_c_' + slug + '_update" ), (bool) wp_next_scheduled( "wplit_client_refresh_' + slug + '" ) ) );' ) );

function makePlugin( folder, slug, uninstall ) {
	const dir = path.join( PLUGINS, folder );
	fs.rmSync( dir, { recursive: true, force: true } );
	fs.mkdirSync( dir, { recursive: true } );
	fs.writeFileSync( path.join( dir, folder + '.php' ), `<?php
/*
Plugin Name: ${ folder }
Version: 1.0.0
*/
require_once __DIR__ . '/vendor/wplicense-it-client/wplicense-it-client.php';
wplicense_it_client( array( 'file' => __FILE__, 'server' => '${ BASE }', 'product_id' => 999999, 'name' => '${ folder }', 'version' => '1.0.0'${ slug ? ", 'slug' => '" + slug + "'" : '' } ) );
` );
	if ( uninstall ) {
		fs.writeFileSync( path.join( dir, 'uninstall.php' ), `<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require_once __DIR__ . '/vendor/wplicense-it-client/wplicense-it-client.php';
wplicense_it_client_forget( '${ folder }' );
` );
	}
	fs.cpSync( path.join( REPO, 'sdk' ), path.join( dir, 'vendor/wplicense-it-client' ), { recursive: true, filter: ( src ) => ! /phpcs\.xml\.dist$/.test( src ) } );
}

// Seeds what the SDK keeps once a license is activated: the license, the update cache and the daily check.
const seed = ( slug ) => onClient( 'update_option( "wplit_client_' + slug + '", array( "key" => "TEST-KEY", "status" => "active" ), false ); set_transient( "wplit_c_' + slug + '_update", array( "version" => "1.0.1" ), 3600 ); wp_clear_scheduled_hook( "wplit_client_refresh_' + slug + '" ); wp_schedule_event( time() + 3600, "daily", "wplit_client_refresh_' + slug + '" ); echo "ok";' );

async function remove( page, folder ) {
	await page.goto( CLIENT + '/wp-admin/plugins.php' );
	const row = 'tr[data-plugin="' + folder + '/' + folder + '.php"]';
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( row + ' a[href*="action=deactivate"]' ) ] );
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.click( row + ' a.delete' );
	// WordPress deletes the plugin in the background and then says so on the screen.
	for ( let i = 0; i < 60 && fs.existsSync( path.join( PLUGINS, folder ) ); i++ ) { await page.waitForTimeout( 500 ); }
	await shot( page, '44-sdk-cleanup-deleted-' + folder );
}

( async () => {
	const logStart = logSize();
	makePlugin( 'wplit-cleanup-a', 'cleanup-custom-slug', false );
	makePlugin( 'wplit-cleanup-b', '', true );
	onClient( 'activate_plugin( "wplit-cleanup-a/wplit-cleanup-a.php" ); activate_plugin( "wplit-cleanup-b/wplit-cleanup-b.php" ); echo "ok";' );
	seed( 'cleanup-custom-slug' );
	seed( 'wplit-cleanup-b' );
	seed( 'another-product' );

	const env = await start();
	const page = env.page;
	await login( page, CLIENT );

	// An admin page load lets the SDK register the uninstall hook (plugins are deleted from the admin).
	await page.goto( CLIENT + '/wp-admin/plugins.php' );
	await shot( page, '43-sdk-cleanup-plugins' );
	const hooks = JSON.parse( onClient( 'echo wp_json_encode( array( get_option( "uninstall_plugins" ), get_option( "wplit_cu_map" ) ) );' ) );
	check( 'wplicense_it_client_uninstall_hook' === ( hooks[ 0 ] || {} )[ 'wplit-cleanup-a/wplit-cleanup-a.php' ], 'the plugin without an uninstall.php has the SDK\'s uninstall hook registered with WordPress' );
	check( ! ( 'wplit-cleanup-b/wplit-cleanup-b.php' in ( hooks[ 0 ] || {} ) ), 'the plugin with its own uninstall.php does not (WordPress would ignore it)' );
	check( 'cleanup-custom-slug' === ( hooks[ 1 ] || {} )[ 'wplit-cleanup-a/wplit-cleanup-a.php' ], 'the SDK remembers which slug belongs to the plugin file' );
	check( JSON.stringify( stored( 'cleanup-custom-slug' ) ) === '[true,true,true]' && JSON.stringify( stored( 'wplit-cleanup-b' ) ) === '[true,true,true]', 'before deleting, both have a license, update data and a daily check' );

	// Deleting through the Plugins screen.
	await remove( page, 'wplit-cleanup-a' );
	check( ! fs.existsSync( path.join( PLUGINS, 'wplit-cleanup-a' ) ), 'the first plugin is deleted' );
	check( JSON.stringify( stored( 'cleanup-custom-slug' ) ) === '[false,false,false]', 'its license, update data and daily check are gone, with no code from the seller: ' + JSON.stringify( stored( 'cleanup-custom-slug' ) ) );
	check( ! ( 'wplit-cleanup-a/wplit-cleanup-a.php' in ( JSON.parse( onClient( 'echo wp_json_encode( get_option( "wplit_cu_map", new stdClass() ) );' ) ) || {} ) ), 'and the SDK forgot its slug' );

	await remove( page, 'wplit-cleanup-b' );
	check( JSON.stringify( stored( 'wplit-cleanup-b' ) ) === '[false,false,false]', 'the plugin with its own uninstall.php cleans up with the one call: ' + JSON.stringify( stored( 'wplit-cleanup-b' ) ) );
	check( JSON.stringify( stored( 'another-product' ) ) === '[true,true,true]', 'another product\'s license on the same site is left alone' );

	// Put the site back.
	onClient( 'delete_option( "wplit_client_another-product" ); delete_transient( "wplit_c_another-product_update" ); wp_clear_scheduled_hook( "wplit_client_refresh_another-product" ); echo "ok";' );

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
