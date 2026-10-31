/**
 * Export and import licenses as CSV (card 97), through the real screens. A few licenses are exported from the Licenses
 * list (with its filter) and from Import and export; a value that a spreadsheet would run is written as text; the export is
 * edited the way a move from another system would be (new keys, a revoked one, a date, a line with mistakes) and imported:
 * the check shows what would happen and writes nothing, the import writes what was shown, keeps existing licenses as they
 * are, and records it in each license's history. Someone who cannot manage licenses can do neither.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const SCREEN = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-import-export';
const LIST = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses';
const DIR = '/tmp/wplit-shots';
const php = ( code ) => execFileSync( 'php', [ '-d', 'memory_limit=512M', '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const count = ( where ) => Number( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE ' + where ) );

( async () => {
	const logStart = logSize();
	const stamp = Date.now();
	const product = Number( php( 'echo wp_insert_post( array( "post_type" => "wplit_product", "post_status" => "publish", "post_title" => "Transfer Test ' + stamp + '" ) );' ) );
	const seed = JSON.parse( php( '$s = \\Devllo\\WPLicenseIt\\Plugin::instance()->licenses(); $a = $s->issue_license( ' + product + ', "first-' + stamp + '@example.com", null, null, 2 ); $b = $s->issue_license( ' + product + ', "=HYPERLINK(1)-' + stamp + '@example.com", null, null, 1 ); $s->revoke_license( $b->id, "revoked" ); echo wp_json_encode( array( $a->license_key, $b->license_key ) );' ) );

	const env = await start();
	const page = env.page;
	await login( page );

	// 1. Export from the Licenses list, filtered to the product.
	await page.goto( LIST + '&product_id=' + product );
	const [ listDownload ] = await Promise.all( [ page.waitForEvent( 'download' ), page.click( '#wplit-export-shown' ) ] );
	const listFile = path.join( DIR, 'export-list-' + stamp + '.csv' );
	await listDownload.saveAs( listFile );
	const exported = fs.readFileSync( listFile, 'utf8' );
	const lines = exported.replace( /^﻿/, '' ).trim().split( '\r\n' );
	check( lines[ 0 ] === 'license_key,product_id,product,email,status,activation_limit,sites_in_use,expires_at,created_at,order,user_id', 'the export starts with the column names' );
	check( 3 === lines.length && seed.every( ( key ) => exported.includes( key ) ), 'the list export holds the two licenses of the filtered product only: ' + ( lines.length - 1 ) + ' rows' );
	check( exported.toLowerCase().includes( "'=hyperlink(1)-" + stamp ), 'an email that a spreadsheet would run as a formula is written as text' );
	check( /,revoked,/.test( exported ) && /,active,2,0,/.test( exported ), 'with statuses, sites allowed and in use' );

	// 2. Export from Import and export, by status.
	await page.goto( SCREEN );
	await shot( page, '47-import-export' );
	await page.selectOption( '#wplit-export-status', 'revoked' );
	await page.selectOption( '#wplit-export-product', String( product ) );
	const [ statusDownload ] = await Promise.all( [ page.waitForEvent( 'download' ), page.click( '#wplit-export' ) ] );
	const statusFile = path.join( DIR, 'export-status-' + stamp + '.csv' );
	await statusDownload.saveAs( statusFile );
	const revokedOnly = fs.readFileSync( statusFile, 'utf8' );
	check( revokedOnly.includes( seed[ 1 ] ) && ! revokedOnly.includes( seed[ 0 ] ), 'the export can be limited to one status' );

	// 3. A file as it comes from another system: the export plus new licenses and mistakes.
	const importFile = path.join( DIR, 'import-' + stamp + '.csv' );
	fs.writeFileSync( importFile, exported + [
		'MOVED-' + stamp + '-A,' + product + ',,moved-a-' + stamp + '@example.com,active,3,0,2030-01-31,2024-02-01 10:00:00,,',
		'MOVED-' + stamp + '-B,,Transfer Test ' + stamp + ',moved-b-' + stamp + '@example.com,revoked,,,,,,',
		'MOVED-' + stamp + '-C,' + product + ',,not-an-email,active,1,0,,,,',
		'MOVED-' + stamp + '-D,' + product + ',,moved-d-' + stamp + '@example.com,paused,1,0,,,,',
	].join( '\r\n' ) + '\r\n' );

	const before = count( '1=1' );
	await page.goto( SCREEN );
	await page.setInputFiles( '#wplit-csv', importFile );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wplit-import-check' ) ] );
	await shot( page, '48-import-plan' );
	const plan = await page.locator( '#wplit-import-plan' ).innerText();
	check( /2 licenses can be imported, 2 are skipped and 2 have a mistake/.test( plan ), 'the check says what would happen: ' + plan.split( '\n' )[ 0 ] );
	check( /Line 2: A license with this key already exists/.test( plan ) && /Line 3:/.test( plan ), 'existing keys are skipped, with their lines' );
	check( /Line 6: The email address is not valid/.test( plan ) && /Line 7: The status must be/.test( plan ), 'mistakes are listed with their lines and why' );
	check( before === count( '1=1' ), 'checking writes nothing' );

	// 4. Import. The form's fields are kept to send them again in 5.
	const runForm = await page.locator( '#wplit-import-plan form' ).evaluate( ( form ) => Object.fromEntries( new FormData( form ) ) );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wplit-import-run' ) ] );
	const result = await page.locator( '#wplit-import-result' ).innerText();
	check( /2 licenses imported, 2 skipped, 2 not imported/.test( result ), 'the import says what it did: ' + result.split( '\n' )[ 0 ] );
	check( before + 2 === count( '1=1' ), 'two licenses were added' );
	const a = sql( "SELECT CONCAT_WS('|', product_id, email, status, activation_limit, expires_at, created_at, IFNULL(order_id,'-')) FROM wp_wplit_licenses WHERE license_key='MOVED-" + stamp + "-A'" );
	check( a === product + '|moved-a-' + stamp + '@example.com|active|3|2030-01-31 23:59:59|2024-02-01 10:00:00|-', 'the first keeps its key, sites, expiry and creation date: ' + a );
	check( 'revoked' === sql( "SELECT status FROM wp_wplit_licenses WHERE license_key='MOVED-" + stamp + "-B'" ), 'the second found its product by name and kept its status' );
	check( 0 === count( "license_key IN ('MOVED-" + stamp + "-C','MOVED-" + stamp + "-D')" ), 'the lines with mistakes were not imported' );
	check( 'active' === sql( "SELECT status FROM wp_wplit_licenses WHERE license_key='" + seed[ 0 ] + "'" ) && 1 === count( "license_key='" + seed[ 0 ] + "'" ), 'an existing license is left as it was, not duplicated' );
	check( 'imported' === sql( "SELECT e.type FROM wp_wplit_license_events e JOIN wp_wplit_licenses l ON l.id=e.license_id WHERE l.license_key='MOVED-" + stamp + "-A'" ), 'the history says the license was imported' );

	// 5. The import cannot be run twice from the same check.
	const replay = await page.request.post( BASE + '/wp-admin/admin-post.php', { form: runForm, maxRedirects: 0 } );
	check( /import_error=expired/.test( replay.headers().location || '' ), 'sending the same check again is refused: ' + ( replay.headers().location || replay.status() ) );
	check( before + 2 === count( '1=1' ), 'and adds nothing' );

	// 6. Someone who cannot manage licenses.
	php( 'if ( ! get_user_by( "login", "wplit_transfer_ed" ) ) { wp_insert_user( array( "user_login" => "wplit_transfer_ed", "user_pass" => "pw-wplit-1", "user_email" => "wplit_transfer_ed@example.test", "role" => "editor" ) ); }' );
	const exportUrl = await ( async () => { await page.goto( LIST ); return page.locator( '#wplit-export-shown' ).getAttribute( 'href' ); } )();
	await page.context().clearCookies();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'wplit_transfer_ed' );
	await page.fill( '#user_pass', 'pw-wplit-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wp-submit' ) ] );
	const denied = await page.request.get( new URL( exportUrl, BASE ).href );
	check( 403 === denied.status() || ! ( await denied.text() ).includes( seed[ 0 ] ), 'an editor cannot download the export, even with the link: ' + denied.status() );
	const screen = await page.goto( SCREEN );
	check( 403 === screen.status() || /not allowed/i.test( await page.locator( 'body' ).innerText() ), 'nor open the screen: ' + screen.status() );

	php( 'foreach ( get_posts( array( "post_type" => "wplit_product", "title" => "Transfer Test ' + stamp + '", "post_status" => "any" ) ) as $p ) { wp_delete_post( $p->ID, true ); } echo "ok";' );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
