/**
 * Card 102 (the review of delivering updates), with the sample plugin and the real client SDK on the customer site:
 * - an up-to-date licensed plugin can have WordPress's automatic updates turned on, and the background update installs
 *   a new release (with a fresh download link, as the Update button does);
 * - a release that needs a newer PHP than the site has is not offered for installing: WordPress says it does not work
 *   with this PHP;
 * - an expired license still sees that a new version exists, cannot install it, and is told to renew, with the link;
 *   automatic updates are off for it meanwhile (they would fail and email the administrator) and back after renewing.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, CLIENT, ROOT, check, createProduct, finish, login, logSize, meta, shot, sql, start } = require( './lib.cjs' );

const CLIENT_ROOT = path.join( ROOT, 'client' );
const PLUGINS = path.join( CLIENT_ROOT, 'wp-content/plugins' );
const SAMPLE = path.join( PLUGINS, 'wplit-sample' );
const SLUG = 'wplit-sample';
const onClient = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; require_once ABSPATH . "wp-admin/includes/plugin.php"; ' + code ], { encoding: 'utf8' } ).trim();
const installed = () => ( fs.readFileSync( path.join( SAMPLE, 'wplit-sample.php' ), 'utf8' ).match( /^Version:\s*(.+)$/m ) || [] )[ 1 ];
// What WordPress would show next: forget its remembered update data and the SDK's cache, then check again.
// The test site's WordPress is a git checkout, which makes WordPress skip all automatic updates; a normal site is not.
// Background updates run from WordPress's cron, which keeps the plugin active while it is replaced; so run them as cron.
const autoUpdate = () => onClient( 'add_filter( "automatic_updates_is_vcs_checkout", "__return_false" ); add_filter( "wp_doing_cron", "__return_true" ); delete_option( "auto_updater.lock" ); wp_maybe_auto_update(); echo "ok";' );
const recheck = () => onClient( 'delete_site_transient( "update_plugins" ); delete_transient( "wplit_c_' + SLUG + '_update" ); wp_update_plugins(); echo "ok";' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

async function release( page, productId, version, snippet, requiresPhp ) {
	const zip = fixtures.samplePlugin( version, '= ' + version + ' =\n* Release ' + version + '.\n', requiresPhp );
	// The release carries the Connect code, as a seller's real releases do.
	const dir = fs.mkdtempSync( path.join( fixtures.DIR, 'rel-' ) );
	execFileSync( 'unzip', [ '-q', zip, '-d', dir ] );
	const main = path.join( dir, SLUG, SLUG + '.php' );
	fs.writeFileSync( main, fs.readFileSync( main, 'utf8' ).replace( /^\/\/ STEP 1:.*$/m, snippet ) );
	const out = path.join( dir, SLUG + '-' + version + '.zip' );
	execFileSync( 'zip', [ '-qr', out, SLUG ], { cwd: dir } );
	await page.goto( BASE + '/wp-admin/post.php?post=' + productId + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', out );
	await save( page );
	return out;
}

( async () => {
	fs.rmSync( SAMPLE, { recursive: true, force: true } );
	onClient( 'delete_option( "wplit_client_' + SLUG + '" ); update_option( "active_plugins", array_values( array_diff( (array) get_option( "active_plugins" ), array( "' + SLUG + '/' + SLUG + '.php" ) ) ) ); update_site_option( "auto_update_plugins", array() ); echo "ok";' );

	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );
	await login( page, CLIENT );

	// The product, its 1.0.0 release with the Connect code, a license, installed and activated on the customer site.
	const productId = await createProduct( page, 'Update Delivery Product' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const snippet = await page.inputValue( '#wplit-connect-code' );
	const first = await release( page, productId, '1.0.0', snippet );
	execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->issue_license( ' + productId + ', "updates@example.com", null, null, 2, new DateTimeImmutable( "+1 year" ) );' ] );
	const key = sql( 'SELECT license_key FROM wp_wplit_licenses WHERE product_id=' + productId + ' ORDER BY id DESC LIMIT 1' );
	execFileSync( 'unzip', [ '-q', '-o', first, '-d', PLUGINS ] );
	onClient( 'activate_plugin( "' + SLUG + '/' + SLUG + '.php" ); echo "ok";' );
	await page.goto( CLIENT + '/wp-admin/options-general.php?page=' + SLUG + '-license' );
	await page.fill( '#wplit-client-key', key );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'form button', { hasText: 'Activate' } ).first().click() ] );
	check( '1.0.0' === installed(), 'the sample plugin 1.0.0 is installed and its license activated' );

	// 1. Up to date: WordPress offers automatic updates for it.
	recheck();
	await page.goto( CLIENT + '/wp-admin/plugins.php' );
	const row = page.locator( 'tr[data-plugin="' + SLUG + '/' + SLUG + '.php"]' );
	await shot( page, '58-auto-update-link' );
	const enable = row.locator( 'a', { hasText: 'Enable auto-updates' } );
	check( 1 === await enable.count(), 'an up-to-date licensed plugin has the Enable auto-updates link' );
	// WordPress's own address for the link (what it does without JavaScript).
	const href = await enable.getAttribute( 'href' );
	await page.goto( new URL( href, CLIENT + '/wp-admin/' ).href );
	check( '1' === onClient( 'echo (int) in_array( "' + SLUG + '/' + SLUG + '.php", (array) get_site_option( "auto_update_plugins", array() ), true );' ), 'turning it on is saved' );

	// 2. A release that needs a newer PHP than the site has is not offered for installing.
	await release( page, productId, '1.1.0', snippet, '99.0' );
	check( '99.0' === meta( productId, 'wplit_required_php_version' ), 'the release needs PHP 99.0' );
	recheck();
	await page.goto( CLIENT + '/wp-admin/plugins.php' );
	await shot( page, '59-needs-php' );
	check( /does not work with your version of PHP/.test( await page.locator( 'tr#' + SLUG + '-update, tr.plugin-update-tr[data-slug="' + SLUG + '"]' ).first().innerText().catch( () => '' ) ) || /does not work with your version of PHP/.test( await page.locator( 'body' ).innerText() ), 'WordPress says the new version does not work with this PHP' );
	autoUpdate();
	check( '1.0.0' === installed(), 'and the automatic update does not install it' );

	// 3. A release that fits: the background automatic update installs it.
	await release( page, productId, '1.2.0', snippet );
	recheck();
	autoUpdate();
	check( '1.2.0' === installed(), 'the automatic update installed 1.2.0 in the background, got ' + installed() );
	check( '1' === onClient( 'echo (int) is_plugin_active( "' + SLUG + '/' + SLUG + '.php" );' ), 'and the plugin is still active' );
	check( fs.existsSync( path.join( SAMPLE, 'vendor/wplicense-it-client/wplicense-it-client.php' ) ), 'in the same folder, with the client code' );

	// 4. Expired: the update is shown, cannot be installed, and the customer is told to renew.
	await release( page, productId, '1.3.0', snippet );
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE license_key='" + key + "'" );
	await page.goto( CLIENT + '/wp-admin/options-general.php?page=' + SLUG + '-license' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'button', { hasText: 'Check now' } ).click() ] );
	recheck();
	await page.goto( CLIENT + '/wp-admin/plugins.php' );
	await shot( page, '60-expired-update' );
	const body = await page.locator( 'body' ).innerText();
	check( /There is a new version of WPLit Sample available/.test( body ) && /Automatic update is unavailable for this plugin/.test( body ), 'the expired license sees version 1.3.0 exists, and that it cannot be updated automatically' );
	const renew = page.locator( '.wplit-client-row' );
	check( /license has expired\. Renew it to get version 1\.3\.0/.test( await renew.innerText() ) && BASE + '/my-account/licenses/' === await renew.locator( 'a', { hasText: 'Renew' } ).getAttribute( 'href' ), 'its row says to renew to get 1.3.0, with the link' );
	const autoColumn = await page.locator( 'tr[data-plugin="' + SLUG + '/' + SLUG + '.php"] td.column-auto-updates' ).innerText();
	check( /Auto-updates disabled/.test( autoColumn ) && ! /scheduled/.test( autoColumn ), 'automatic updates show as off while it is expired, not as scheduled, got: ' + autoColumn.replace( /\n/g, ' ' ) );
	const logBefore = fs.statSync( path.join( CLIENT_ROOT, 'wp-content/debug.log' ) ).size;
	autoUpdate();
	check( '1.2.0' === installed(), 'nothing is installed while the license is expired' );
	const log = fs.readFileSync( path.join( CLIENT_ROOT, 'wp-content/debug.log' ), 'utf8' ).slice( logBefore );
	check( ! /Upgrading plugin 'wplit-sample'/.test( log ), 'and the background update does not even try (it would fail and email the administrator)' );
	check( '1' === onClient( 'echo (int) in_array( "' + SLUG + '/' + SLUG + '.php", (array) get_site_option( "auto_update_plugins", array() ), true );' ), 'the administrator\'s own setting is kept for after renewing' );

	// 5. Renewed: the next background update installs the version that waited.
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 YEAR), status = 'active' WHERE license_key='" + key + "'" );
	await page.goto( CLIENT + '/wp-admin/options-general.php?page=' + SLUG + '-license' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'button', { hasText: 'Check now' } ).click() ] );
	recheck();
	autoUpdate();
	check( '1.3.0' === installed(), 'after renewing, the automatic update installs 1.3.0, got ' + installed() );

	onClient( 'deactivate_plugins( "' + SLUG + '/' + SLUG + '.php" ); update_site_option( "auto_update_plugins", array() ); delete_option( "wplit_client_' + SLUG + '" ); echo "ok";' );
	fs.rmSync( SAMPLE, { recursive: true, force: true } );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
