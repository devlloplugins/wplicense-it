/**
 * Card 101 (the review of checking a license), on the customer site with the sample plugin and the real client SDK:
 * - a site whose address changed (moved, or a copied database) is told at once and is not active until "Activate here";
 * - a week without an answer from the license server is reported (the license keeps working), dismissible for a week;
 * - a full license answers with the sites that use it and where to free one;
 * - deleting the plugin from the Plugins screen frees the site on the license server.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, CLIENT, ROOT, check, createProduct, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const CLIENT_ROOT = path.join( ROOT, 'client' );
const PLUGINS = path.join( CLIENT_ROOT, 'wp-content/plugins' );
const SAMPLE = path.join( PLUGINS, 'wplit-sample' );
const SLUG = 'wplit-sample';
const onClient = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; require_once ABSPATH . "wp-admin/includes/plugin.php"; ' + code ], { encoding: 'utf8' } ).trim();
const state = () => JSON.parse( onClient( 'echo wp_json_encode( get_option( "wplit_client_' + SLUG + '" ) );' ) );
const setState = ( changes ) => onClient( '$s = get_option( "wplit_client_' + SLUG + '" ); foreach ( json_decode( \'' + JSON.stringify( changes ) + '\', true ) as $k => $v ) { $s[ $k ] = $v; } update_option( "wplit_client_' + SLUG + '", $s, false ); echo "ok";' );
const LICENSE_PAGE = CLIENT + '/wp-admin/options-general.php?page=' + SLUG + '-license';
// While this file exists, the customer site cannot reach the license server (as when it is down or blocked).
const OFFLINE = path.join( CLIENT_ROOT, 'wp-content/mu-plugins/wplit-test-offline.php' );
const offline = ( on ) => on
	? ( fs.mkdirSync( path.dirname( OFFLINE ), { recursive: true } ), fs.writeFileSync( OFFLINE, "<?php\nadd_filter( 'pre_http_request', function ( $pre, $args, $url ) { return false !== strpos( $url, '127.0.0.1:8080' ) ? new WP_Error( 'http_request_failed', 'Test: the license server is down.' ) : $pre; }, 10, 3 );\n" ) )
	: fs.rmSync( OFFLINE, { force: true } );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

( async () => {
	fs.rmSync( SAMPLE, { recursive: true, force: true } );
	fs.rmSync( OFFLINE, { force: true } );
	// A dismissed notice from an earlier run would hide the ones checked here.
	execFileSync( 'mariadb', [ 'wp_client', '-e', "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_%wplit\\_cd\\_%'" ] );
	onClient( 'delete_option( "wplit_client_' + SLUG + '" ); update_option( "active_plugins", array_values( array_diff( (array) get_option( "active_plugins" ), array( "' + SLUG + '/' + SLUG + '.php" ) ) ) ); echo "ok";' );

	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );
	await login( page, CLIENT );

	// The product, a 1-site license, and the sample plugin with the Connect code, activated on the customer site.
	const productId = await createProduct( page, 'License Checking Product' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const snippet = await page.inputValue( '#wplit-connect-code' );
	execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->issue_license( ' + productId + ', "checker@example.com", null, null, 1, null );' ] );
	const key = sql( 'SELECT license_key FROM wp_wplit_licenses WHERE product_id=' + productId + ' ORDER BY id DESC LIMIT 1' );
	execFileSync( 'unzip', [ '-q', '-o', fixtures.samplePlugin( '1.0.0' ), '-d', PLUGINS ] );
	const file = path.join( SAMPLE, 'wplit-sample.php' );
	fs.writeFileSync( file, fs.readFileSync( file, 'utf8' ).replace( /^\/\/ STEP 1:.*$/m, snippet ) );
	onClient( 'activate_plugin( "' + SLUG + '/' + SLUG + '.php" ); echo "ok";' );
	await page.goto( LICENSE_PAGE );
	await page.fill( '#wplit-client-key', key );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'form button', { hasText: 'Activate' } ).first().click() ] );
	const activated = state();
	check( 'active' === activated.status && CLIENT === String( activated.site ).replace( /\/$/, '' ) && BASE === String( activated.server ).replace( /\/$/, '' ), 'activating remembers the address and the server: ' + activated.site + ' / ' + activated.server );

	// 1. Moved or copied: told at once, not active until Activate here.
	setState( { site: 'https://old-home.example.com' } );
	await page.goto( CLIENT + '/wp-admin/' );
	const notice = page.locator( '.wplit-client-notice' );
	check( 1 === await notice.count() && /activated for https:\/\/old-home\.example\.com, but this site is http:\/\/127\.0\.0\.1:8081/.test( await notice.innerText() ), 'the dashboard says the license was activated for another address' );
	check( /the license is\s+not active/.test( await page.locator( 'body' ).innerText() ), 'and the plugin\'s own check says the license is not active here' );
	await page.goto( LICENSE_PAGE );
	await shot( page, '56-license-moved' );
	check( /activated for another address/.test( await page.locator( '.form-table' ).innerText() ) && 1 === await page.locator( 'button', { hasText: 'Activate here' } ).count(), 'the license page explains it and offers Activate here' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'button', { hasText: 'Activate here' } ).click() ] );
	check( /License activated on this site/.test( await page.locator( 'body' ).innerText() ) && CLIENT === String( state().site ).replace( /\/$/, '' ), 'Activate here activates this address' );
	await page.goto( CLIENT + '/wp-admin/' );
	check( 0 === await page.locator( '.wplit-client-notice' ).count() && /the license is\s+active/.test( await page.locator( 'body' ).innerText() ), 'and the license works again' );

	// 2. A week without an answer (the server cannot be reached): reported, still working, dismissible.
	offline( true );
	setState( { last_checked: Math.floor( Date.now() / 1000 ) - 8 * 86400 } );
	await page.goto( CLIENT + '/wp-admin/' );
	const stale = page.locator( '.wplit-client-notice' );
	check( 1 === await stale.count() && /could not be checked with the license server for 8 days/.test( await stale.innerText() ) && /the license is\s+active/.test( await page.locator( 'body' ).innerText() ), 'after 8 days without an answer the admin is told, and the license keeps working' );
	await shot( page, '57-license-stale' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), stale.locator( 'button', { hasText: 'Dismiss for a week' } ).click() ] );
	check( 0 === await page.locator( '.wplit-client-notice' ).count(), 'Dismiss for a week hides it' );
	await page.goto( LICENSE_PAGE );
	check( /could not be checked with the license server for 8 days/.test( await page.locator( '.wplit-client-stale' ).innerText() ), 'the license page still says it' );
	offline( false );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'button', { hasText: 'Check now' } ).click() ] );
	check( 0 === await page.locator( '.wplit-client-stale' ).count(), 'a successful Check now clears it' );

	// 3. A full license answers with the sites that use it.
	const api = ( route, body ) => page.request.post( BASE + '/wp-json/wplicense-it/v1/licenses/' + route, { form: body } );
	const live = await api( 'activate', { license_key: key, product_id: String( productId ), site_url: 'https://live-one.example.org' } );
	const full = await api( 'activate', { license_key: key, product_id: String( productId ), site_url: 'https://live-two.example.org' } );
	const body = await full.json();
	check( 200 === live.status() && 403 === full.status() && 'limit_reached' === body.code, 'a second live site is refused: ' + full.status() + ' ' + body.code );
	check( JSON.stringify( body.sites ) === JSON.stringify( [ 'live-one.example.org' ] ) && BASE + '/my-account/licenses/' === body.account_url, 'the answer lists the site that uses the slot (not the local one) and the My Account page: ' + JSON.stringify( body.sites ) + ' ' + body.account_url );
	await api( 'deactivate', { license_key: key, site_url: 'https://live-one.example.org' } );

	// 4. Deleting the plugin frees this site on the license server.
	const clientSite = sql( "SELECT CONCAT(a.site,'|',a.status) FROM wp_wplit_activations a JOIN wp_wplit_licenses l ON l.id=a.license_id WHERE l.license_key='" + key + "' AND a.site LIKE '127.0.0.1:8081%'" );
	check( /\|active$/.test( clientSite ), 'before deleting, the customer site is active on the server: ' + clientSite );
	await page.goto( CLIENT + '/wp-admin/plugins.php' );
	const row = 'tr[data-plugin="' + SLUG + '/' + SLUG + '.php"]';
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( row + ' a[href*="action=deactivate"]' ) ] );
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.click( row + ' a.delete' );
	for ( let i = 0; i < 60 && fs.existsSync( SAMPLE ); i++ ) { await page.waitForTimeout( 500 ); }
	check( ! fs.existsSync( SAMPLE ) && false === state(), 'the plugin and its stored license are gone' );
	const freed = sql( "SELECT a.status FROM wp_wplit_activations a JOIN wp_wplit_licenses l ON l.id=a.license_id WHERE l.license_key='" + key + "' AND a.site LIKE '127.0.0.1:8081%'" );
	check( 'active' !== freed, 'and the license server freed the site: ' + freed );

	offline( false );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
