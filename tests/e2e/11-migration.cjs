/**
 * The 1.x to 2.0 migration on a real WordPress database. A 1.x database is built with the exact tables 1.0.2 made and the
 * awkward rows a real shop has (see php/legacy.php), then the migration is run the way a site owner meets it: the cron job
 * does the work, the admin notice reports what it could not move, "Switch to 2.0 anyway" finishes it, and the old API URLs
 * keep answering 1.x clients.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, sql, start } = require( './lib.cjs' );

const SERVER_ROOT = path.join( ROOT, 'wordpress' );

function onServer( code ) {
	return execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( SERVER_ROOT, 'wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
}

function state() {
	return JSON.parse( onServer( 'echo wp_json_encode( get_option( "wplit_migration_state" ) );' ) );
}

function checksums() {
	return sql( 'CHECKSUM TABLE wp_wplit_product_licenses, wp_wplit_orders' );
}

( async () => {
	const logStart = logSize();
	const seed = JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'php/legacy.php' ), 'seed' ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } ).trim() );
	const { ids } = seed;
	const env = await start();
	const page = env.page;
	await login( page );

	check( sql( 'SELECT COUNT(*) FROM wp_wplit_product_licenses' ) === '448', 'the 1.x table has 448 licenses' );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) === '0', 'the 2.0 table starts empty' );
	const before = checksums();

	// The old URLs keep working before anything is migrated, from the 1.x tables.
	const oldApi = async ( action, params, pretty = false ) => {
		const query = new URLSearchParams( params ).toString();
		const url = pretty ? BASE + '/api/wplicense-it-api/v1/' + action + '?' + query : BASE + '/?__wp_license_api=' + action + '&' + query;
		const response = await page.request.get( url );
		return { status: response.status(), text: ( await response.text() ).trim() };
	};
	const good = { p: seed.yearly, e: 'Customer@Example.com', l: 'LEG-GOOD-0001-AAAA-BBBB', k: seed.yearly_key };
	let r = await oldApi( 'status', good );
	check( r.text === '"active"', 'before the migration the old status URL says active, got ' + r.text );

	// Calls to the old URLs are counted, so the owner can see when nobody uses them any more.
	const usage = () => JSON.parse( onServer( 'echo wp_json_encode( get_option( "wplit_legacy_api_usage" ) );' ) || 'null' );
	const calls = ( u ) => u && u.days ? Object.values( u.days ).reduce( ( sum, day ) => sum + ( day[ seed.yearly ] || 0 ), 0 ) : 0;
	const counted = calls( usage() );
	await oldApi( 'info', good );
	check( calls( usage() ) === counted + 1, 'a call to an old URL is counted for its product (' + counted + ' then ' + calls( usage() ) + ')' );

	// Before the migration the 1.x code answers, and a client that sends too many wrong keys is stopped too.
	const limits = { failures: sql( "SELECT option_value FROM wp_options WHERE option_name='wplit_rate_limit_failures'" ) };
	sql( "DELETE FROM wp_options WHERE option_name='wplit_rate_limit_failures'" );
	sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('wplit_rate_limit_failures', '3', 'yes')" );
	onServer( 'delete_transient( "wplit_rl_" . md5( "127.0.0.1" ) );' );
	for ( let i = 0; i < 3; i++ ) {
		await oldApi( 'status', { ...good, l: 'LEG-WRONG-KEY-' + i } );
	}
	r = await oldApi( 'status', good );
	check( 429 === r.status, 'before the migration, 3 wrong keys stop the client: the right key now gets ' + r.status + ' ' + r.text.slice( 0, 80 ) );
	onServer( 'delete_transient( "wplit_rl_" . md5( "127.0.0.1" ) );' );
	r = await oldApi( 'status', good );
	check( 200 === r.status && r.text === '"active"', 'and it works again once the block is lifted' );
	sql( "DELETE FROM wp_options WHERE option_name='wplit_rate_limit_failures'" );
	if ( '' !== limits.failures ) { sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('wplit_rate_limit_failures', '" + limits.failures + "', 'yes')" ); }

	// The admin sees the migration notice.
	await page.goto( BASE + '/wp-admin/' );
	check( /moving your licenses to the new 2\.0 format/i.test( await page.locator( 'body' ).innerText() ), 'the admin notice says the licenses are being moved' );

	// WordPress cron does the work.
	for ( let attempt = 0; attempt < 10; attempt++ ) {
		onServer( 'wp_clear_scheduled_hook( "wplit_migration_tick" ); wp_schedule_single_event( time() - 5, "wplit_migration_tick" );' );
		await page.request.get( BASE + '/wp-cron.php' );
		if ( [ 'done', 'needs_attention' ].includes( state().status ) ) { break; }
		await page.waitForTimeout( 2000 );
	}
	let s = state();
	check( s.status === 'needs_attention', 'cron ran the migration and it stopped for a decision, status ' + s.status );
	check( s.licenses_migrated === 445 && s.licenses_skipped === 3, 'licenses: 445 moved and 3 skipped (a duplicate key, an empty key, no product), got ' + s.licenses_migrated + '/' + s.licenses_skipped );
	check( s.orders_migrated === 11 && s.orders_skipped === 1, 'orders: 11 moved and 1 skipped (no product), got ' + s.orders_migrated + '/' + s.orders_skipped );
	check( s.finalized === false, 'it has not switched over yet' );

	// The notice lists the problems and offers both buttons.
	await page.goto( BASE + '/wp-admin/' );
	const notice = await page.locator( 'body' ).innerText();
	check( /License 5: its key is already used/.test( notice ) && /License 6: the license key is empty/.test( notice ) && /License 7: it has no product/.test( notice ), 'the notice lists the licenses it could not move' );
	check( /License 8 has an old product API key/.test( notice ), 'the notice warns about the license with an old product key' );
	check( /Migrated so far: 445 licenses, 11 orders/.test( notice ), 'the notice shows how many were moved' );
	check( /Left behind so far: 3 licenses, 1 orders/.test( notice ), 'the notice says how many licenses and orders were left behind' );
	check( /^Verify: 1\.x has 448 licenses but 445 were migrated/.test( await page.locator( '.notice-error li' ).first().innerText() ), 'the first line of the list is what verification found, not an early note' );
	check( await page.locator( 'button:has-text("Run the migration now")' ).count() > 0 && await page.locator( 'button:has-text("Switch to 2.0 anyway")' ).count() > 0, 'both buttons are offered' );

	// Until the switch, customers' licenses still work through the old URLs.
	r = await oldApi( 'status', good );
	check( r.text === '"active"', 'while it waits for the decision the old status URL still says active, got ' + r.text );

	// The site owner accepts: switch to 2.0.
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	try {
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button:has-text("Switch to 2.0 anyway")' ) ] );
	} catch ( e ) {
		// Say what the site looked like, so a failure on another machine can be understood from its log.
		console.log( 'The switch did not finish. Migration state: ' + onServer( 'echo wp_json_encode( get_option( "wplit_migration_state" ) );' ) + '\nLock: ' + onServer( 'echo wp_json_encode( get_transient( "wplit_migration_lock" ) );' ) + '\nPage: ' + ( await page.locator( 'body' ).innerText().catch( () => '?' ) ).slice( 0, 600 ).replace( /\n/g, ' | ' ) );
		throw e;
	}
	s = state();
	check( s.status === 'done' && s.finalized === true, 'after "Switch to 2.0 anyway" the migration is done, status ' + s.status );
	check( onServer( 'echo get_option( "wplit_db_version" );' ) === '2.0.0', 'the database version is 2.0.0' );
	await page.goto( BASE + '/wp-admin/' );
	check( ! /moving your licenses to the new 2\.0 format/i.test( await page.locator( 'body' ).innerText() ), 'the notice is gone' );

	// Nothing in the 1.x tables changed.
	check( checksums() === before, 'the 1.x tables are exactly as they were (checksums match)' );

	// The data.
	const lic = ( id ) => sql( 'SELECT CONCAT_WS("|",email,status,activation_limit,IFNULL(expires_at,"NULL"),product_id,IFNULL(legacy_id,"")) FROM wp_wplit_licenses WHERE legacy_id=' + id );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) === '445', '445 licenses in 2.0' );
	check( lic( ids.good ) === 'customer@example.com|active|0|' + seed.good_utc + '|' + seed.yearly + '|' + ids.good, 'the good license keeps its key, lower-cases the email, gets unlimited sites, and its expiry is converted from New York time to UTC (' + seed.good_local + ' became ' + seed.good_utc + '): ' + lic( ids.good ) );
	check( /^old@example\.com\|expired\|0\|/.test( lic( ids.expired ) ), 'a license past its date is expired: ' + lic( ids.expired ) );
	check( /^inactive@example\.com\|revoked\|0\|/.test( lic( ids.inactive ) ), 'a 1.x status other than active becomes revoked: ' + lic( ids.inactive ) );
	check( lic( ids.lifetime ) === 'forever@example.com|active|0|NULL|' + seed.lifetime + '|' + ids.lifetime, 'a license with no end date has no expiry: ' + lic( ids.lifetime ) );
	check( lic( ids.oldkey ) !== '', 'the license with an old product key was still moved' );
	for ( const bad of [ 'duplicate', 'nokey', 'noproduct' ] ) {
		check( lic( ids[ bad ] ) === '', 'the ' + bad + ' license was left behind' );
	}
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE license_key LIKE "LEG-BULK-%"' ) === '440', 'all 440 bulk licenses crossed the batch boundary' );
	check( sql( 'SELECT COUNT(*) FROM (SELECT license_key FROM wp_wplit_licenses GROUP BY license_key HAVING COUNT(*) > 1) d' ) === '0', 'no license key was copied twice' );

	const meta = ( id, key ) => sql( 'SELECT meta_value FROM wp_postmeta WHERE post_id=' + id + ' AND meta_key="' + key + '"' );
	check( meta( seed.yearly, 'wplit_price_minor' ) === '4999' && meta( seed.yearly, 'wplit_period' ) === 'P1Y' && meta( seed.yearly, 'wplit_currency' ) === 'USD', 'the yearly product: $49.99 became 4999 cents, 1-year became P1Y, currency USD' );
	check( meta( seed.lifetime, 'wplit_price_minor' ) === '0' && meta( seed.lifetime, 'wplit_period' ) === 'lifetime', 'the free product is lifetime and costs 0' );

	const orderRow = ( id ) => sql( 'SELECT CONCAT_WS("|",order_number,total_minor,currency,status,source,IFNULL(legacy_id,"")) FROM wp_wplit_license_orders WHERE legacy_id=' + id );
	check( orderRow( ids.order_good ) === 'WLI-1001|4999|USD|completed|legacy_stripe|' + ids.order_good, 'the order keeps its number and becomes 4999 cents USD from legacy_stripe: ' + orderRow( ids.order_good ) );
	check( orderRow( ids.order_collision ) === 'WLI-1001-2|1950|USD|completed|legacy_stripe|' + ids.order_collision, 'an order with a taken number gets a suffix, and $19.5 becomes 1950 cents: ' + orderRow( ids.order_collision ) );
	check( orderRow( ids.order_noproduct ) === '', 'the order with no product was left behind' );
	check( /Example Ltd/.test( sql( 'SELECT legacy_billing FROM wp_wplit_license_orders WHERE legacy_id=' + ids.order_good ) ), 'the billing details are kept on the migrated order' );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE l.legacy_id=' + ids.good + ' AND o.legacy_id=' + ids.order_good ) === '1', 'the license is linked to the order of the same customer' );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE l.legacy_id=' + ids.expired + ' AND o.legacy_id=' + ids.order_collision ) === '1', 'the other customer\'s license is linked to their own order' );

	// The old URLs now answer from the 2.0 tables.
	r = await oldApi( 'status', good );
	check( r.text === '"active"', 'after the switch the old status URL still says active, got ' + r.text );
	r = await oldApi( 'status', good, true );
	check( r.text === '"active"', 'the pretty /api/wplicense-it-api/v1/status address works too, got ' + r.text.slice( 0, 120 ) );
	r = await oldApi( 'status', { p: seed.yearly, e: 'old@example.com', l: 'LEG-OLD0-0002-AAAA-BBBB', k: seed.yearly_key } );
	check( r.text === '"inactive"', 'an expired license is inactive through the old URL, got ' + r.text );
	r = await oldApi( 'status', { p: seed.yearly, e: 'inactive@example.com', l: 'LEG-INAC-0003-AAAA-BBBB', k: seed.yearly_key } );
	check( r.text === '"inactive"', 'a revoked license is inactive through the old URL, got ' + r.text );
	r = await oldApi( 'status', { p: seed.lifetime, e: 'forever@example.com', l: 'LEG-LIFE-0004-AAAA-BBBB', k: seed.lifetime_key } );
	check( r.text === '"active"', 'a lifetime license is active through the old URL, got ' + r.text );
	r = await oldApi( 'status', { p: seed.yearly, e: 'oldkey@example.com', l: 'LEG-OKEY-0008-AAAA-BBBB', k: 'a-key-the-product-no-longer-has' } );
	check( r.text === '"inactive"', 'the old product key no longer validates, as the warning said, got ' + r.text );
	r = await oldApi( 'info', good );
	let info = {};
	try { info = JSON.parse( r.text ); } catch ( e ) { /* reported below */ }
	check( info.name === 'Legacy Pro' && info.version === '2.3.0' && /api\/wplicense-it-api\/v1\/get/.test( info.package_url || '' ), 'the old info URL still gives the name, version and download address: ' + r.text.slice( 0, 200 ) );

	// And the 2.0 API accepts the migrated key.
	const response = await page.request.post( BASE + '/wp-json/wplicense-it/v1/licenses/validate', { data: { license_key: 'LEG-GOOD-0001-AAAA-BBBB', product_id: seed.yearly } } );
	const body = await response.json().catch( () => ( {} ) );
	check( response.status() === 200 && body.license && body.license.status === 'active', 'the 2.0 API validates a migrated key: ' + response.status() + ' ' + JSON.stringify( body ).slice( 0, 160 ) );

	// Running it again changes nothing.
	onServer( 'do_action( "wplit_migration_tick" );' );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) === '445' && sql( 'SELECT COUNT(*) FROM wp_wplit_license_orders' ) === '11', 'a later run copies nothing twice' );

	execFileSync( 'php', [ path.join( __dirname, 'php/legacy.php' ), 'restore' ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } );
	await finish( env, logStart );
} )();
