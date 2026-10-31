/**
 * Card 113 (the review of making a plugin license-aware):
 * - the Connect tab has a third step, the code that runs licensed features only with a license, with the folder name;
 * - a theme product offers an example theme; installed and switched to on the customer site, it registers with the SDK:
 *   its license page is there, and its licensed feature is off until a key is entered;
 * - the SDK's texts are translatable: a German translation in the site's languages folder translates the license page's
 *   status, and one in the plugin's own languages folder translates the page title.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, CLIENT, ROOT, check, createProduct, finish, login, logSize, shot, start } = require( './lib.cjs' );

const CLIENT_ROOT = path.join( ROOT, 'client' );
const PLUGINS = path.join( CLIENT_ROOT, 'wp-content/plugins' );
const THEMES = path.join( CLIENT_ROOT, 'wp-content/themes' );
const SAMPLE = path.join( PLUGINS, 'wplit-sample' );
const SITE_LANG = path.join( CLIENT_ROOT, 'wp-content/languages/plugins' );
const WPCLI = path.join( ROOT, 'wp-cli-2.12.0.phar' );
const onClient = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; require_once ABSPATH . "wp-admin/includes/plugin.php"; ' + code ], { encoding: 'utf8' } ).trim();
const clientSql = ( query ) => execFileSync( 'mariadb', [ 'wp_client', '-N', '-e', query ], { encoding: 'utf8' } ).trim();

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

/** Writes a German .mo for the SDK's text domain into a folder. */
function germanMo( folder, words ) {
	fs.mkdirSync( folder, { recursive: true } );
	const po = path.join( folder, 'wplicense-it-client-de_DE.po' );
	const header = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Language: de_DE\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n\n';
	fs.writeFileSync( po, header + Object.entries( words ).map( ( [ en, de ] ) => 'msgid "' + en + '"\nmsgstr "' + de + '"\n' ).join( '\n' ) );
	execFileSync( 'php', [ WPCLI, '--allow-root', 'i18n', 'make-mo', po, folder ] );
	fs.rmSync( po );
}

( async () => {
	fs.rmSync( SAMPLE, { recursive: true, force: true } );
	onClient( 'delete_option( "wplit_client_wplit-sample" ); update_option( "active_plugins", array_values( array_diff( (array) get_option( "active_plugins" ), array( "wplit-sample/wplit-sample.php" ) ) ) ); echo "ok";' );
	const previousTheme = onClient( 'echo get_stylesheet();' );

	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );
	await login( page, CLIENT );

	// 1. The Connect tab's third step.
	const productId = await createProduct( page, 'License Aware Product' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	await shot( page, '65-connect-gate' );
	const gate = await page.inputValue( '#wplit-connect-gate' );
	check( /wplicense_it_license_active\( 'wplit-sample' \)/.test( gate ) && /'expired' => 'stop'/.test( gate ), 'the Connect tab shows the code that runs features only with a license, for this folder' );
	check( 1 === await page.locator( '#wplit-connect-gate-copy' ).count(), 'with its own Copy button' );
	const snippet = await page.inputValue( '#wplit-connect-code' );

	// 2. SDK texts in German: the site's translation and the plugin's own.
	execFileSync( 'unzip', [ '-q', '-o', fixtures.samplePlugin( '1.0.0' ), '-d', PLUGINS ] );
	const main = path.join( SAMPLE, 'wplit-sample.php' );
	fs.writeFileSync( main, fs.readFileSync( main, 'utf8' ).replace( /^\/\/ STEP 1:.*$/m, snippet ) );
	onClient( 'activate_plugin( "wplit-sample/wplit-sample.php" ); echo "ok";' );
	germanMo( SITE_LANG, { 'Not activated. Enter your license key to receive updates.': 'Nicht aktiviert. Geben Sie Ihren Lizenzschlüssel ein, um Updates zu erhalten.' } );
	germanMo( path.join( SAMPLE, 'languages' ), { '%s License': 'Lizenz für %s' } );
	clientSql( "DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='locale'; INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'locale', 'de_DE')" );
	await page.goto( CLIENT + '/wp-admin/options-general.php?page=wplit-sample-license' );
	await shot( page, '66-sdk-german' );
	const german = await page.locator( '.wrap' ).innerText();
	check( /Nicht aktiviert\. Geben Sie Ihren Lizenzschlüssel ein/.test( german ), 'the status is German, from the site\'s languages folder' );
	check( /Lizenz für WPLit Sample/.test( await page.locator( '.wrap h1' ).innerText() ), 'the title is German, from the plugin\'s own languages folder' );
	check( /License key/.test( german ), 'texts without a translation stay English' );
	clientSql( "DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='locale'" );
	fs.rmSync( path.join( SITE_LANG, 'wplicense-it-client-de_DE.mo' ), { force: true } );
	onClient( 'deactivate_plugins( "wplit-sample/wplit-sample.php" ); echo "ok";' );
	fs.rmSync( SAMPLE, { recursive: true, force: true } );

	// 3. A theme product: the example theme works on the customer site.
	execFileSync( 'php', [ '-r', 'require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; update_post_meta( ' + productId + ', "wplit_package_type", "theme" ); update_post_meta( ' + productId + ', "wplit_package_folder", "wplit-example-theme" ); update_post_meta( ' + productId + ', "wplit_package_name", "Example Theme" );' ] );
	await page.goto( BASE + '/wp-admin/post.php?post=' + productId + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const link = page.locator( '#wplit-example-download' );
	check( 'Download an example theme' === ( await link.innerText() ).trim(), 'a theme product offers an example theme' );
	const response = await page.request.get( await link.getAttribute( 'href' ) );
	const zip = path.join( fixtures.DIR, 'example-theme.zip' );
	fs.writeFileSync( zip, await response.body() );
	const listing = execFileSync( 'unzip', [ '-l', zip ], { encoding: 'utf8' } );
	check( /wplit-example-theme\/style\.css/.test( listing ) && /wplit-example-theme\/vendor\/wplicense-it-client\/wplicense-it-client\.php/.test( listing ), 'its zip holds the theme and the SDK' );
	fs.rmSync( path.join( THEMES, 'wplit-example-theme' ), { recursive: true, force: true } );
	execFileSync( 'unzip', [ '-q', '-o', zip, '-d', THEMES ] );
	onClient( 'switch_theme( "wplit-example-theme" ); echo "ok";' );
	await page.goto( CLIENT + '/wp-admin/' );
	check( /Licensed theme feature is off/.test( await page.locator( 'body' ).innerText() ), 'switched to, its licensed feature is off until a key is entered' );
	await page.goto( CLIENT + '/wp-admin/options-general.php?page=wplit-example-theme-license' );
	await shot( page, '67-example-theme-license' );
	check( /Example Theme License/.test( await page.locator( '.wrap h1' ).innerText() ) && 1 === await page.locator( '#wplit-client-key' ).count(), 'and its license page is under Settings' );
	const front = await page.request.get( CLIENT + '/' );
	check( 200 === front.status() && /wplit-example-theme\/style\.css/.test( await front.text() ), 'the front end works and loads the theme\'s stylesheet' );

	onClient( 'switch_theme( "' + previousTheme + '" ); delete_option( "wplit_client_wplit-example-theme" ); echo "ok";' );
	fs.rmSync( path.join( THEMES, 'wplit-example-theme' ), { recursive: true, force: true } );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
