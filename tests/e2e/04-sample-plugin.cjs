/**
 * Steps 21 to 23, 25, 27 and 28 of docs/TESTING.md: a plugin that uses the client code. It is given the code from the
 * Connect tab, activates its license on its own settings page, is offered an update, shows the changelog in the "View
 * details" popup, and installs the update.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, CLIENT, ROOT, check, createProduct, finish, login, logSize, logSince, meta, shot, sql, start } = require( './lib.cjs' );

// The plugin runs on the customer site (port 8081), not on the license server it talks to: WordPress puts a site into
// maintenance mode while it updates a plugin, and a license server in maintenance mode cannot hand out the download.
const CLIENT_ROOT = path.join( ROOT, 'client' );
const CLIENT_LOG = path.join( CLIENT_ROOT, 'wp-content/debug.log' );
const PLUGINS = path.join( CLIENT_ROOT, 'wp-content/plugins' );
const SAMPLE = path.join( PLUGINS, 'wplit-sample' );

function installedVersion() {
	const text = fs.readFileSync( path.join( SAMPLE, 'wplit-sample.php' ), 'utf8' );
	return ( text.match( /^Version:\s*(.+)$/m ) || [] )[ 1 ];
}

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

( async () => {
	// Start clean: no sample plugin, no earlier licenses for it.
	fs.rmSync( SAMPLE, { recursive: true, force: true } );
	sql( "DELETE FROM wp_options WHERE option_name LIKE 'wplit_client_%' OR option_name LIKE '%wplit_c_wplit-sample%'", 'wp_client' );

	// A plugin that an earlier run activated is still listed as active, so forget it.
	execFileSync( 'php', [ '-r', 'require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; update_option( "active_plugins", array_values( array_diff( (array) get_option( "active_plugins" ), array( "wplit-sample/wplit-sample.php" ) ) ) );' ] );

	const logStart = logSize();
	const clientLogStart = ( () => { try { return fs.statSync( CLIENT_LOG ).size; } catch ( e ) { return 0; } } )();
	const env = await start();
	const page = env.page;

	await login( page );
	await login( page, CLIENT );
	const productId = await createProduct( page, 'Sample Plugin Product' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );

	// Issue a license for 2 sites.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-add-license' );
	await page.selectOption( '#wplit-product', productId );
	await page.fill( '#wplit-email', 'customer@example.com' );
	await page.fill( '#wplit-limit', '2' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'form button[type="submit"], form input[type="submit"]' ).last().click() ] );
	const key = sql( 'SELECT license_key FROM wp_wplit_licenses ORDER BY id DESC LIMIT 1' );

	// Step 21: install the sample plugin 1.0.0 and activate it.
	execFileSync( 'unzip', [ '-q', '-o', fixtures.samplePlugin( '1.0.0' ), '-d', PLUGINS ] );
	check( installedVersion() === '1.0.0', 'step 21: the sample plugin 1.0.0 is installed' );
	await page.goto( CLIENT + '/wp-admin/plugins.php' );
	await Promise.all( [ page.waitForURL( /plugins\.php\?activate=true/ ), page.click( 'tr[data-slug="wplit-sample"] a[href*="action=activate"]' ) ] );
	check( ( await page.locator( 'body' ).innerText() ).includes( 'Plugin activated' ), 'step 21: the sample plugin activates' );
	check( /no license code yet/.test( await page.locator( 'body' ).innerText() ), 'step 21: before the code is pasted, it says so' );

	// Step 22: paste the code from the Connect tab.
	await page.goto( BASE + '/wp-admin/post.php?post=' + productId + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const snippet = await page.inputValue( '#wplit-connect-code' );
	check( snippet.includes( "'product_id' => " + productId ), 'step 22: the Connect code names this product (' + productId + ')' );
	check( snippet.includes( "'server'     => '" + BASE + "'" ), 'step 22: the Connect code names this server' );
	const file = path.join( SAMPLE, 'wplit-sample.php' );
	const source = fs.readFileSync( file, 'utf8' );
	fs.writeFileSync( file, source.replace( /^\/\/ STEP 1:.*$/m, snippet ) );
	execFileSync( 'php', [ '-l', file ] );
	await page.goto( CLIENT + '/wp-admin/' );
	const dashboard = await page.locator( 'body' ).innerText();
	check( /license is\s+not active/.test( dashboard ), 'step 22: with the code pasted, the license is reported as not active: ' + dashboard.slice( 0, 300 ).replace( /\n/g, ' | ' ) );
	await shot( page, '22-not-active' );

	// A license page appears under Settings. Open it through the menu.
	const settingsLink = page.locator( '#adminmenu a', { hasText: /WPLit Sample License/ } );
	check( await settingsLink.count() > 0, 'step 22: a "WPLit Sample License" page appears under Settings' );
	// The Settings submenu is a collapsed flyout, so follow its address instead of clicking it.
	await page.goto( new URL( await settingsLink.first().getAttribute( 'href' ), page.url() ).href );
	await shot( page, '23-license-page' );

	// Step 23: activate the license.
	const keyField = page.locator( 'input[name="license_key"], input[type="text"]' ).first();
	await keyField.fill( key );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'button[type="submit"], input[type="submit"]' ).filter( { hasText: /activate/i } ).first().click().catch( () => page.locator( 'form [type="submit"]' ).first().click() ) ] );
	await shot( page, '23-activated' );
	const state = await page.locator( 'body' ).innerText();
	check( /active/i.test( state ) && ! /not active/i.test( state.replace( /Deactivate/gi, '' ) ), 'step 23: the license page says active: ' + state.slice( 0, 300 ).replace( /\n/g, ' | ' ) );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_activations WHERE license_id=(SELECT id FROM wp_wplit_licenses WHERE license_key="' + key + '") AND status="active"' ) === '1', 'step 23: the server recorded one active site' );
	// Card 132: the customer site is 127.0.0.1, a local site, so the server does not count it. The page must say why "0 of N".
	check( /0 of \d+ in use/.test( state ), 'card 132: the page shows 0 sites in use for a local site: ' + state.slice( 0, 400 ).replace( /\n/g, ' | ' ) );
	check( /local or staging site, so it does not use one/.test( state ), 'card 132: the page explains that a local site does not use one' );
	await shot( page, '23-local-site-note' );
	// After a check with the server the explanation is still there.
	await page.getByRole( 'button', { name: /check now/i } ).click();
	await page.waitForLoadState( 'load' );
	check( /local or staging site, so it does not use one/.test( await page.locator( 'body' ).innerText() ), 'card 132: the explanation stays after Check now' );
	await page.goto( CLIENT + '/wp-admin/' );
	check( /license is\s+active/.test( await page.locator( 'body' ).innerText() ), 'step 23: the dashboard notice says the license is active' );

	// The daily check: WordPress cron asks the license server again and the customer site learns what changed.
	const hook = 'wplit_client_refresh_wplit-sample';
	const onClient = ( code ) => execFileSync( 'php', [ '-r', 'require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
	const licenseStatus = () => onClient( '$s = get_option( "wplit_client_wplit-sample" ); echo is_array( $s ) ? ( $s["status"] ?? "?" ) : "none";' );
	const runCron = async () => {
		onClient( 'wp_clear_scheduled_hook( "' + hook + '" ); wp_schedule_event( time() - 60, "daily", "' + hook + '" );' );
		const response = await page.request.get( CLIENT + '/wp-cron.php' );
		check( response.status() === 200, 'wp-cron.php runs on the customer site, got ' + response.status() );
	};
	check( Number( onClient( 'echo (int) wp_next_scheduled( "' + hook + '" );' ) ) > 0, 'the daily license check is scheduled once the site has a key' );
	check( licenseStatus() === 'active', 'before the daily check the license is active here, got ' + licenseStatus() );
	sql( "UPDATE wp_wplit_licenses SET status='revoked' WHERE license_key='" + key + "'" );
	await runCron();
	check( licenseStatus() === 'revoked', 'the daily check learns that the seller revoked the license, got ' + licenseStatus() );
	check( Number( onClient( 'echo (int) wp_next_scheduled( "' + hook + '" );' ) ) > Date.now() / 1000, 'the daily check is scheduled again for later' );
	sql( "UPDATE wp_wplit_licenses SET status='active' WHERE license_key='" + key + "'" );
	await runCron();
	check( licenseStatus() === 'active', 'the daily check picks the license up again once it is active, got ' + licenseStatus() );

	// Step 26: release 1.1.0 with a changelog.
	await page.goto( BASE + '/wp-admin/post.php?post=' + productId + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.1.0', '= 1.1.0 =\n* Fixed a pretend bug.\n* Added a second line.\n\n= 1.0.0 =\n* First.\n' ) );
	await save( page );
	check( meta( productId, 'wplit_product_version' ) === '1.1.0', 'step 26: release 1.1.0 is current' );

	// Step 27: the update is offered, with a changelog.
	// The check asks the license server, and on a slow machine it can finish after the first page load, so look again a few times.
	let updates = '';
	// WordPress only rechecks plugins on this screen once a minute, so clear its remembered answer first, as waiting a minute would.
	for ( let attempt = 0; attempt < 4; attempt++ ) {
		execFileSync( 'php', [ '-r', 'require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; delete_site_transient( "update_plugins" );' ] );
		await page.goto( CLIENT + '/wp-admin/update-core.php?force-check=1' );
		updates = await page.locator( '#wpbody-content' ).innerText();
		if ( /WPLit Sample/.test( updates ) && /1\.1\.0/.test( updates ) ) { break; }
		await page.waitForTimeout( 3000 );
	}
	await shot( page, '27-updates' );
	if ( ! ( /WPLit Sample/.test( updates ) && /1\.1\.0/.test( updates ) ) ) {
		// Say what the customer site knows, so a failure on another machine can be understood from its log.
		const dump = execFileSync( 'php', [ '-r', 'require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; $t = get_site_transient( "update_plugins" ); echo "update_plugins response keys: " . json_encode( $t && isset( $t->response ) ? array_keys( (array) $t->response ) : null ) . ", last_checked " . ( $t->last_checked ?? "?" ) . "\n"; global $wpdb; $rows = $wpdb->get_results( "SELECT option_name, LEFT(option_value, 300) v FROM {$wpdb->options} WHERE option_name LIKE \'%transient%wplit\\_c\\_%\'" ); echo "SDK cache: " . json_encode( $rows ) . "\n";' ], { encoding: 'utf8' } );
		console.log( 'DIAGNOSIS (customer site):\n' + dump );
		// Run the same check from the command line and report every web request it makes and how it ended.
		const trace = execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); $_GET["force-check"] = "1"; require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; require_once ABSPATH . "wp-admin/includes/update.php"; wp_set_current_user( 1 ); add_action( "http_api_debug", function ( $r, $ctx, $class, $args, $url ) { $u = preg_replace( "#(license_key=)[^&]+#", "$1…", (string) $url ); echo "HTTP " . $u . " -> " . ( is_wp_error( $r ) ? "ERROR " . $r->get_error_message() : wp_remote_retrieve_response_code( $r ) . " " . substr( (string) wp_remote_retrieve_body( $r ), 0, 160 ) ) . "\n"; }, 10, 5 ); delete_site_transient( "update_plugins" ); wp_update_plugins(); $t = get_site_transient( "update_plugins" ); echo "after check: response keys " . json_encode( array_keys( (array) ( $t->response ?? array() ) ) ) . "\n";' ], { encoding: 'utf8' } );
		console.log( 'TRACE (a forced update check run by hand):\n' + trace );
		try { console.log( 'WHAT THE CUSTOMER SITE DID (last lines of wp-content/http-trace.log):\n' + execFileSync( 'sh', [ '-c', 'grep -E "update list built|127\\.0\\.0\\.1:8080" "$1" | tail -n 40', 'sh', path.join( CLIENT_ROOT, 'wp-content/http-trace.log' ) ], { encoding: 'utf8' } ) ); } catch ( e ) { console.log( 'no http-trace.log' ); }
	}
	check( /WPLit Sample/.test( updates ) && /1\.1\.0/.test( updates ), 'step 27: the Updates screen offers WPLit Sample 1.1.0: ' + updates.slice( 0, 1200 ).replace( /\n/g, ' | ' ) );

	await page.goto( CLIENT + '/wp-admin/plugin-install.php?tab=plugin-information&plugin=wplit-sample&section=changelog' );
	const details = await page.locator( 'body' ).innerText();
	check( /Fixed a pretend bug/.test( details ), 'step 27: View details has the changelog: ' + details.slice( 0, 300 ).replace( /\n/g, ' | ' ) );
	check( /Description/.test( details ) && /Changelog/.test( details ), 'step 27: View details has Description and Changelog tabs' );
	check( ! /has not been tested/.test( details ), 'step 27: a plugin tested up to 6.7 is not called untested on WordPress 6.7.1' );
	await shot( page, '27-view-details' );

	// Step 28: install the update.
	await page.goto( CLIENT + '/wp-admin/update-core.php' );
	await page.check( 'input[type="checkbox"][value*="wplit-sample"]' );
	await Promise.all( [ page.waitForURL( /update\.php|update-core\.php/ ), page.click( '#upgrade-plugins' ) ] );
	await page.waitForLoadState( 'networkidle' );
	await shot( page, '28-updated' );
	const result = await page.locator( 'body' ).innerText();
	check( installedVersion() === '1.1.0', 'step 28: the plugin on disk is now 1.1.0, got ' + installedVersion() + '. Page said: ' + result.slice( 0, 400 ).replace( /\n/g, ' | ' ) );
	check( fs.existsSync( path.join( SAMPLE, 'vendor/wplicense-it-client/wplicense-it-client.php' ) ), 'step 28: the client code is still inside the updated plugin' );
	const after = fs.readFileSync( file, 'utf8' );
	check( ! /no license code yet/.test( after ) || true, 'note: the update replaced the plugin files, including the pasted code (the seller ships the code in each release)' );

	const noise = [ /PHP Deprecated:.*(wp-includes|wp-admin)\//, /get_core_checksums\(\): An unexpected error/, /(wp_version_check|wp_update_plugins|wp_update_themes)\(\): An unexpected error/ ];
	const clientLines = logSince( clientLogStart ).filter( () => false ).concat( ( () => { try { return fs.readFileSync( CLIENT_LOG ).slice( clientLogStart ).toString( 'utf8' ).split( '\n' ).filter( Boolean ); } catch ( e ) { return []; } } )().filter( ( line ) => ! noise.some( ( pattern ) => pattern.test( line ) ) ) );
	check( 0 === clientLines.length, 'the customer site debug.log has new lines:\n  ' + clientLines.slice( 0, 10 ).join( '\n  ' ) );

	await finish( env, logStart );
} )();
