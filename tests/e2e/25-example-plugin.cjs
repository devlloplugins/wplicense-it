/**
 * The example plugin from the Connect box (card 105): a seller downloads it for a product, installs it on a customer site
 * as it comes, and it works without editing: the licensed feature is off and points to the license page, a key turns it on,
 * and deleting the plugin removes what the SDK stored.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, CLIENT, ROOT, check, createProduct, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const PLUGINS = path.join( ROOT, 'client/wp-content/plugins' );
const onClient = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'client/wp-load.php' ) + '"; require_once ABSPATH . "wp-admin/includes/admin.php"; ' + code ], { encoding: 'utf8' } ).trim();

( async () => {
	const logStart = logSize();
	const title = 'Example Test ' + Date.now();
	const slug = title.toLowerCase().replace( /[^a-z0-9_-]+/g, '-' );

	const env = await start();
	const page = env.page;
	await login( page );
	await login( page, CLIENT );
	const productId = await createProduct( page, title );

	// 1. The Connect box offers the example.
	await page.goto( BASE + '/wp-admin/post.php?post=' + productId + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	check( 1 === await page.locator( '#wplit-example-download' ).count(), 'the Connect box offers an example plugin' );
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.click( '#wplit-example-download' ) ] );
	const zip = path.join( '/tmp/wplit-shots', 'example-' + productId + '.zip' );
	await download.saveAs( zip );
	check( download.suggestedFilename() === slug + '.zip', 'it downloads as ' + slug + '.zip, got ' + download.suggestedFilename() );
	const listing = execFileSync( 'unzip', [ '-Z1', zip ], { encoding: 'utf8' } ).split( '\n' ).filter( Boolean );
	check( listing.includes( slug + '/' + slug + '.php' ) && listing.includes( slug + '/vendor/wplicense-it-client/wplicense-it-client.php' ) && listing.includes( slug + '/readme.txt' ), 'the zip has the plugin, its readme and the SDK in vendor' );

	// 2. Installed as it comes on the customer site.
	fs.rmSync( path.join( PLUGINS, slug ), { recursive: true, force: true } );
	execFileSync( 'unzip', [ '-q', '-o', zip, '-d', PLUGINS ] );
	const main = fs.readFileSync( path.join( PLUGINS, slug, slug + '.php' ), 'utf8' );
	check( main.includes( "'product_id' => " + productId + ',' ) && main.includes( "'server'     => '" + BASE + "'" ), 'the plugin names this product and this server' );
	execFileSync( 'php', [ '-l', path.join( PLUGINS, slug, slug + '.php' ) ] );
	onClient( 'activate_plugin( "' + slug + '/' + slug + '.php" ); echo "ok";' );

	await page.goto( CLIENT + '/wp-admin/' );
	check( /Licensed feature is off/.test( await page.locator( 'body' ).innerText() ), 'without a license the licensed feature is off' );
	const link = page.locator( 'a', { hasText: 'Enter the license key' } );
	check( 1 === await link.count(), 'and the notice links to the license page' );
	await Promise.all( [ page.waitForURL( /-license/ ), link.click() ] );
	check( /page=.*-license/.test( page.url() ) && 1 === await page.locator( 'input[name="license_key"]' ).count(), 'the link opens the license page with its key field: ' + page.url() );

	// 3. A license turns the feature on.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-add-license' );
	await page.selectOption( '#wplit-product', String( productId ) );
	await page.fill( '#wplit-email', 'example-customer@example.com' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'form button[type="submit"], form input[type="submit"]' ).last().click() ] );
	const key = sql( 'SELECT license_key FROM wp_wplit_licenses WHERE product_id=' + productId + ' ORDER BY id DESC LIMIT 1' );
	check( '' !== key, 'a license was issued for the product' );
	await page.goto( CLIENT + '/wp-admin/options-general.php?page=' + slug + '-license' );
	await page.fill( 'input[name="license_key"]', key );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( '#wplit-client-key' ).press( 'Enter' ) ] );
	await page.goto( CLIENT + '/wp-admin/' );
	await shot( page, '45-example-licensed' );
	check( /Licensed feature is on/.test( await page.locator( 'body' ).innerText() ), 'with the license activated the licensed feature is on' );
	check( '1' === sql( 'SELECT COUNT(*) FROM wp_wplit_activations a JOIN wp_wplit_licenses l ON l.id=a.license_id WHERE l.license_key="' + key + '" AND a.status="active"' ), 'the server recorded the site' );

	// 4. Deleting it (as WordPress does) removes what the SDK stored.
	onClient( 'deactivate_plugins( "' + slug + '/' + slug + '.php" ); delete_plugins( array( "' + slug + '/' + slug + '.php" ) ); echo "ok";' );
	check( ! fs.existsSync( path.join( PLUGINS, slug ) ), 'the plugin is deleted' );
	check( '0' === onClient( 'echo (int) ( false !== get_option( "wplit_client_' + slug + '" ) );' ), 'and its stored license went with it' );

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
