/**
 * Card 111 (the review of expiry):
 * - on the customer site, an expired license keeps the plugin's features on by default (only updates stop), and the
 *   license page says so; a plugin registered with 'expired' => 'stop' switches them off; wplicense_it_license_status()
 *   tells 'active', 'expired' and 'inactive' apart; an expired key still cannot be activated on another site;
 * - on the seller's Overview, licenses that expired in the last 30 days and were not renewed are listed, with the
 *   renewal rate of the last 90 days and a link to every expired license.
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
const MAIN = path.join( SAMPLE, 'wplit-sample.php' );
const onClient = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; require_once ABSPATH . "wp-admin/includes/plugin.php"; ' + code ], { encoding: 'utf8' } ).trim();
const onServer = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const LICENSE_PAGE = CLIENT + '/wp-admin/options-general.php?page=' + SLUG + '-license';
const answers = () => onClient( 'echo wp_json_encode( array( wplicense_it_license_active( "' + SLUG + '" ), wplicense_it_license_status( "' + SLUG + '" ) ) );' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

async function checkNow( page ) {
	await page.goto( LICENSE_PAGE );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'button', { hasText: 'Check now' } ).click() ] );
}

( async () => {
	fs.rmSync( SAMPLE, { recursive: true, force: true } );
	onClient( 'delete_option( "wplit_client_' + SLUG + '" ); update_option( "active_plugins", array_values( array_diff( (array) get_option( "active_plugins" ), array( "' + SLUG + '/' + SLUG + '.php" ) ) ) ); echo "ok";' );

	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );
	await login( page, CLIENT );

	const productId = await createProduct( page, 'Expiry Product' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const snippet = await page.inputValue( '#wplit-connect-code' );
	onServer( 'Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->issue_license( ' + productId + ', "expiry@example.com", null, null, 2, new DateTimeImmutable( "+1 year" ) );' );
	const [ licenseId, key ] = sql( 'SELECT id, license_key FROM wp_wplit_licenses WHERE product_id=' + productId + ' ORDER BY id DESC LIMIT 1' ).split( '\t' );
	execFileSync( 'unzip', [ '-q', '-o', fixtures.samplePlugin( '1.0.0' ), '-d', PLUGINS ] );
	fs.writeFileSync( MAIN, fs.readFileSync( MAIN, 'utf8' ).replace( /^\/\/ STEP 1:.*$/m, snippet ) );
	onClient( 'activate_plugin( "' + SLUG + '/' + SLUG + '.php" ); echo "ok";' );
	await page.goto( LICENSE_PAGE );
	await page.fill( '#wplit-client-key', key );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'form button', { hasText: 'Activate' } ).first().click() ] );
	check( '[true,"active"]' === answers(), 'activated: active, features on, got ' + answers() );

	// 1. Expired (the server says so at the next check): features stay on by default, updates stop.
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 DAY) WHERE id=" + licenseId );
	onServer( 'Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->expire_due_licenses();' );
	check( 'expired' === sql( 'SELECT status FROM wp_wplit_licenses WHERE id=' + licenseId ), 'the daily job marked it expired' );
	await checkNow( page );
	check( '[true,"expired"]' === answers(), 'expired: wplicense_it_license_active() stays true and the status says expired, got ' + answers() );
	await shot( page, '62-expired-keeps-working' );
	check( /Expired\. WPLit Sample keeps working, but you no longer receive updates\. Renew your license to get them again\./.test( await page.locator( '.form-table' ).innerText() ), 'the license page says it keeps working without updates' );
	await page.goto( CLIENT + '/wp-admin/' );
	check( /the license is\s+active/.test( await page.locator( 'body' ).innerText() ), 'and the plugin\'s own check still runs its licensed part' );

	// 2. The same plugin registered with 'expired' => 'stop': features off once expired.
	fs.writeFileSync( MAIN, fs.readFileSync( MAIN, 'utf8' ).replace( /'product_id'\s*=>/, "'expired' => 'stop',\n\t'product_id' =>" ) );
	check( '[false,"expired"]' === answers(), "with 'expired' => 'stop' the features are off, got " + answers() );
	await page.waitForTimeout( 3000 ); // The web server's PHP code cache looks for changed files every 2 seconds.
	await page.goto( LICENSE_PAGE );
	check( /Expired\. Renew your license to use WPLit Sample again and to receive updates\./.test( await page.locator( '.form-table' ).innerText() ), 'and the license page says renewing turns it back on' );
	await page.goto( CLIENT + '/wp-admin/' );
	check( /the license is\s+not active/.test( await page.locator( 'body' ).innerText() ), 'and the plugin\'s own check turns its licensed part off' );

	// An expired key still cannot be activated on another site.
	const other = JSON.parse( execFileSync( 'curl', [ '-s', '-X', 'POST', BASE + '/wp-json/wplicense-it/v1/licenses/activate', '-d', 'license_key=' + key + '&product_id=' + productId + '&site_url=https://another-customer-site.example.com' ], { encoding: 'utf8' } ) );
	check( 'expired' === other.code, 'activating the expired key on another site is refused as expired, got ' + JSON.stringify( other ).slice( 0, 200 ) );

	// Revoked: never on, whatever the option.
	fs.writeFileSync( MAIN, fs.readFileSync( MAIN, 'utf8' ).replace( "'expired' => 'stop',\n\t", '' ) );
	onClient( '$s = get_option( "wplit_client_' + SLUG + '" ); $s["status"] = "revoked"; update_option( "wplit_client_' + SLUG + '", $s, false ); echo "ok";' );
	check( '[false,"inactive"]' === answers(), 'a revoked license is inactive and off, got ' + answers() );

	// 3. The seller's Overview: the expired license, the renewal rate and the link to all expired licenses.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-overview' );
	await shot( page, '63-overview-expired' );
	const panel = page.locator( 'section', { has: page.locator( 'h2', { hasText: 'Expired in the last 30 days, not renewed' } ) } );
	check( 1 === await panel.count(), 'the Overview has the recently expired panel' );
	const text = await panel.innerText();
	check( text.includes( key ) && /5 days ago/.test( text ), 'it lists the license that expired 5 days ago: ' + text.slice( 0, 300 ).replace( /\n/g, ' | ' ) );
	const rate = JSON.parse( onServer( 'echo wp_json_encode( Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->renewal_rate( 90 ) );' ) );
	check( rate.due >= 1 && new RegExp( 'Renewal rate: ' + rate.renewed + ' of ' + rate.due + ' licenses? that came due in the last 90 days w(as|ere) renewed \\(' + Math.round( 100 * rate.renewed / rate.due ) + '%\\)' ).test( text ), 'it shows the renewal rate (' + rate.renewed + ' of ' + rate.due + '): ' + ( text.match( /Renewal rate[^\n]*/ ) || [ '' ] )[ 0 ] );
	const all = panel.locator( 'a', { hasText: 'All expired licenses' } );
	check( 1 === await all.count() && /status=expired/.test( await all.getAttribute( 'href' ) ), 'with a link to every expired license' );

	// Renewed: it leaves the list and counts as renewed.
	onServer( 'Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->renew_license( ' + licenseId + ', new DateInterval( "P1Y" ) );' );
	const after = JSON.parse( onServer( 'echo wp_json_encode( Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->renewal_rate( 90 ) );' ) );
	check( after.due === rate.due && after.renewed === rate.renewed + 1, 'after renewing it counts as renewed: ' + JSON.stringify( rate ) + ' -> ' + JSON.stringify( after ) );
	await page.reload();
	check( ! ( await panel.innerText() ).includes( key ), 'and it is no longer in the recently expired list' );

	onClient( 'deactivate_plugins( "' + SLUG + '/' + SLUG + '.php" ); delete_option( "wplit_client_' + SLUG + '" ); echo "ok";' );
	fs.rmSync( SAMPLE, { recursive: true, force: true } );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
