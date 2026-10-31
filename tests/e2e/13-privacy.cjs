/**
 * WordPress's Tools > Export Personal Data and Erase Personal Data, used on a customer who has 61 licenses (more than one export
 * page), activations, history with her email in it, WooCommerce and 1.x orders with billing details, and rows in the 1.x tables.
 * A second customer is the control: nothing of hers may change.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const os = require( 'os' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const seed = JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'php/privacy.php' ), 'seed' ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } ).trim() );
const ANN = seed.ann.email;
const text = async ( page ) => ( await page.locator( 'body' ).innerText() );

// Everything stored about one person, as one string, to compare before and after.
function snapshot( ids, userId, email, legacyKey, legacyOrder ) {
	return [
		sql( 'SELECT GROUP_CONCAT(CONCAT_WS("|",id,email,IFNULL(user_id,"-"),license_key,status,IFNULL(expires_at,"-"),IFNULL(order_id,"-")) ORDER BY id SEPARATOR ";") FROM wp_wplit_licenses WHERE id IN (' + ids.join( ',' ) + ')' ),
		sql( 'SELECT GROUP_CONCAT(CONCAT_WS("|",license_id,site,status) ORDER BY id SEPARATOR ";") FROM wp_wplit_activations WHERE license_id IN (' + ids.join( ',' ) + ')' ),
		sql( 'SELECT GROUP_CONCAT(CONCAT_WS("|",order_number,total_minor,IFNULL(user_id,"-"),IFNULL(legacy_billing,"-")) ORDER BY id SEPARATOR ";") FROM wp_wplit_license_orders WHERE user_id = ' + userId + ' OR order_number = "' + legacyOrder + '"' ),
		sql( 'SELECT GROUP_CONCAT(CONCAT_WS("|",email,user_id,license_key) SEPARATOR ";") FROM wp_wplit_product_licenses WHERE license_key = "' + legacyKey + '"' ),
		sql( 'SELECT GROUP_CONCAT(CONCAT_WS("|",order_number,order_email,first_name,last_name,billing_company,billing_address,billing_city,billing_phone,postal_code) SEPARATOR ";") FROM wp_wplit_orders WHERE order_email = "' + email + '" OR user_id = ' + userId ),
		sql( 'SELECT GROUP_CONCAT(data SEPARATOR ";") FROM wp_wplit_license_events WHERE license_id IN (' + ids.join( ',' ) + ')' ),
	].join( '\n' );
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );
	const annIds = Object.values( seed.ann.ids ).concat( [ seed.work_id ] );
	const bobBefore = snapshot( Object.values( seed.bob.ids ), seed.bob.user, seed.bob.email, 'OLD1X-BOB-KEY-0001', 'WLI-BOB-1' );
	check( /Ann|ann\.private/.test( snapshot( annIds, seed.ann.user, ANN, 'OLD1X-ANN-KEY-0001', 'WLI-ANN-1' ) ), 'Ann has data before we start' );

	// The privacy policy text.
	await page.goto( BASE + '/wp-admin/options-privacy.php?tab=policyguide' ).catch( () => {} );
	await page.goto( BASE + '/wp-admin/privacy-policy-guide.php' );
	const guide = await page.content();
	check( /WPLicense It/.test( guide ) && /Software licenses/.test( guide ) && /On request we remove your name, email address and account/.test( guide ), 'the Privacy Policy Guide has the plugin\'s suggested text' );

	// EXPORT.
	await page.goto( BASE + '/wp-admin/export-personal-data.php' );
	await page.fill( '#username_or_email_for_privacy_request', ANN );
	await page.uncheck( '#send_confirmation_email' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit' ) ] );
	const row = page.locator( 'tr', { hasText: ANN } ).first();
	check( await row.count() > 0, 'an export request is listed for Ann' );
	await shot( page, '34-export-request' );
	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download', { timeout: 120000 } ),
		( async () => { await row.hover(); await row.locator( '.export-personal-data-idle .export-personal-data-handle' ).first().click(); } )(),
	] );
	const zipPath = path.join( os.tmpdir(), 'wplit-export-' + Date.now() + '.zip' );
	await download.saveAs( zipPath );
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'wplit-export-' ) );
	execFileSync( 'unzip', [ '-q', '-o', zipPath, '-d', dir ] );
	const files = fs.readdirSync( dir );
	check( files.includes( 'index.html' ), 'WordPress made the export file: ' + files.join( ', ' ) );
	const html = fs.readFileSync( path.join( dir, 'index.html' ), 'utf8' );
	const plain = html.replace( /<[^>]+>/g, ' ' ).replace( /&amp;/g, '&' ).replace( /\s+/g, ' ' );

	const missingKeys = Object.values( seed.ann.keys ).filter( ( key ) => ! plain.includes( key ) );
	check( 0 === missingKeys.length, 'all 60 of her licenses are in the export, across the pages of 25 (' + missingKeys.length + ' missing)' );
	check( plain.includes( seed.work_key ), 'and the license issued to her work address but linked to her account' );
	check( plain.includes( 'ann-shop.example.net' ) && plain.includes( 'ann-blog.example.net' ), 'the sites her license is active on' );
	check( /Edited|updated/i.test( plain ) && plain.includes( 'ann.renamed@example.com' ), 'the license history, including the edit' );
	check( plain.includes( 'WC-9001' ) && plain.includes( 'WLI-ANN-1' ), 'her order records' );
	check( plain.includes( '12 Secret Lane' ) && plain.includes( 'Ann Private Ltd' ) && plain.includes( '555-0123' ), 'the billing details of the order migrated from 1.x' );
	check( plain.includes( 'OLD1X-ANN-KEY-0001' ) && plain.includes( 'OLD-ANN-1' ), 'her records in the 1.x tables' );
	check( ! /bob\.control|OLD1X-BOB|7 Control Road|WLI-BOB/.test( plain ), 'and nothing of Bob\'s' );
	check( Object.values( seed.bob.keys ).every( ( key ) => ! plain.includes( key ) ), 'none of his keys either' );
	fs.writeFileSync( path.join( os.tmpdir(), 'wplit-export-index.html' ), html );

	// A person we hold nothing about.
	await page.goto( BASE + '/wp-admin/export-personal-data.php' );
	await page.fill( '#username_or_email_for_privacy_request', 'nobody.at.all@example.com' );
	await page.uncheck( '#send_confirmation_email' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit' ) ] );
	const emptyRow = page.locator( 'tr', { hasText: 'nobody.at.all@example.com' } ).first();
	const [ emptyDownload ] = await Promise.all( [ page.waitForEvent( 'download', { timeout: 120000 } ), ( async () => { await emptyRow.hover(); await emptyRow.locator( '.export-personal-data-idle .export-personal-data-handle' ).first().click(); } )() ] );
	const emptyZip = path.join( os.tmpdir(), 'wplit-export-empty-' + Date.now() + '.zip' );
	await emptyDownload.saveAs( emptyZip );
	const emptyDir = fs.mkdtempSync( path.join( os.tmpdir(), 'wplit-export-empty-' ) );
	execFileSync( 'unzip', [ '-q', '-o', emptyZip, '-d', emptyDir ] );
	const emptyPlain = fs.readFileSync( path.join( emptyDir, 'index.html' ), 'utf8' ).replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' );
	check( ! /WPLicense It licenses and orders/.test( emptyPlain ) && ! emptyPlain.includes( seed.work_key ) && ! plain.includes( 'nobody.at.all' ), 'a person we hold nothing about gets an export with none of our data and no error' );

	// ERASE.
	const before = snapshot( annIds, seed.ann.user, ANN, 'OLD1X-ANN-KEY-0001', 'WLI-ANN-1' );
	await page.goto( BASE + '/wp-admin/erase-personal-data.php' );
	await page.fill( '#username_or_email_for_privacy_request', ANN );
	await page.uncheck( '#send_confirmation_email' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit' ) ] );
	const eraseRow = page.locator( 'tr', { hasText: ANN } ).first();
	await eraseRow.hover();
	await eraseRow.locator( '.remove-personal-data-idle .remove-personal-data-handle, button.remove-personal-data-handle' ).first().click();
	await page.waitForSelector( 'tr:has-text("' + ANN + '") .erasers-progress-bar, tr:has-text("' + ANN + '") .remove-personal-data-success, tr:has-text("' + ANN + '") .notice', { timeout: 60000 } ).catch( () => {} );
	await page.waitForTimeout( 6000 );
	await shot( page, '35-erase-done' );
	const eraseText = await text( page );
	check( ( eraseText.match( /WPLicense It: the name, email address and account were removed/g ) || [] ).length === 1, 'the explanation appears once, although erasing 61 licenses takes three batches' );
	check( /kept|retained|not erased|license key/i.test( eraseText ), 'the tool tells the administrator what was kept: ' + ( eraseText.match( /.{0,60}(kept|retain|not erased).{0,120}/is ) || [ 'no message' ] )[ 0 ].replace( /\s+/g, ' ' ) );

	const after = snapshot( annIds, seed.ann.user, ANN, 'OLD1X-ANN-KEY-0001', 'WLI-ANN-1' );
	check( before !== after, 'something changed for Ann' );
	check( 0 === Number( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE id IN (' + annIds.join( ',' ) + ') AND email NOT LIKE "erased-%@erased.invalid"' ) ), 'every one of her 61 licenses has the placeholder email' );
	check( 0 === Number( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE id IN (' + annIds.join( ',' ) + ') AND user_id IS NOT NULL' ) ), 'and no account link' );
	check( 61 === Number( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE id IN (' + annIds.join( ',' ) + ') AND status = "active" AND license_key <> ""' ) ), 'the 61 keys and statuses are kept' );
	check( ! /ann\.|ann-?@|Ann/.test( sql( 'SELECT IFNULL(GROUP_CONCAT(data SEPARATOR " "),"") FROM wp_wplit_license_events WHERE license_id IN (' + annIds.join( ',' ) + ')' ) ), 'her email is gone from the history, including the edit' );
	check( 2 === Number( sql( 'SELECT COUNT(*) FROM wp_wplit_activations WHERE license_id = ' + seed.ann.ids[ 1 ] ) ), 'the activated sites are kept (the license needs them)' );
	check( 0 === Number( sql( 'SELECT COUNT(*) FROM wp_wplit_license_orders WHERE order_number IN ("WC-9001","WLI-ANN-1") AND (user_id IS NOT NULL OR legacy_billing IS NOT NULL)' ) ) && 2 === Number( sql( 'SELECT COUNT(*) FROM wp_wplit_license_orders WHERE order_number IN ("WC-9001","WLI-ANN-1") AND total_minor > 0' ) ), 'the orders lose the billing details and the account link, and keep number and total' );
	check( /erased/.test( sql( 'SELECT email FROM wp_wplit_product_licenses WHERE license_key = "OLD1X-ANN-KEY-0001"' ) ), 'her row in the 1.x licenses table is anonymised' );
	const oldOrder = sql( 'SELECT CONCAT_WS("|",order_email,first_name,last_name,billing_company,billing_address,billing_phone) FROM wp_wplit_orders WHERE order_number = "OLD-ANN-1"' );
	check( ! /Ann|Secret|555-0123|ann\.private/.test( oldOrder ), 'and her 1.x order has no name, address, phone or email left: ' + oldOrder );
	check( bobBefore === snapshot( Object.values( seed.bob.ids ), seed.bob.user, seed.bob.email, 'OLD1X-BOB-KEY-0001', 'WLI-BOB-1' ), 'Bob\'s licenses, sites, orders, billing details and 1.x rows are exactly as they were' );

	// What still works after erasing.
	const validate = await page.request.post( BASE + '/wp-json/wplicense-it/v1/licenses/validate', { data: { license_key: seed.ann.keys[ 5 ], product_id: seed.product } } );
	const validated = await validate.json().catch( () => ( {} ) );
	check( validate.status() === 200 && validated.license && validated.license.status === 'active', 'an erased license still validates by its key' );

	// The export of the same person afterwards.
	const exported = execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + ROOT + '/wordpress/wp-load.php"; $r = ( new Devllo\\WPLicenseIt\\Privacy\\PrivacyHooks() )->export( "' + ANN + '", 1 ); echo json_encode( array( "items" => count( $r["data"] ), "done" => $r["done"] ) );' ], { encoding: 'utf8' } ).trim();
	check( /"items":0/.test( exported ), 'exporting her address again finds nothing: ' + exported );

	// Erasing again is harmless.
	const again = execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + ROOT + '/wordpress/wp-load.php"; $r = ( new Devllo\\WPLicenseIt\\Privacy\\PrivacyHooks() )->erase( "' + ANN + '", 1 ); echo json_encode( $r );' ], { encoding: 'utf8' } ).trim();
	check( /"done":true/.test( again ) && ! /"items_removed":true/.test( again ), 'erasing her again changes nothing and reports done: ' + again.slice( 0, 160 ) );
	check( after === snapshot( annIds, seed.ann.user, ANN, 'OLD1X-ANN-KEY-0001', 'WLI-ANN-1' ), 'the data is identical after the second erase' );

	sql( 'DROP TABLE IF EXISTS wp_wplit_product_licenses, wp_wplit_orders' );
	await finish( env, logStart );
} )();
