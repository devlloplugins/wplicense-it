/**
 * A theme that uses the client SDK (card 75): it is given the Connect code, activates its license on its own page, is
 * offered an update in the Updates screen and on the Themes screen, and its "View version details" link opens a page
 * with the version, requirements, what is new and the description (before, it opened the seller's home page, which may
 * refuse to be framed). Then the update installs and the theme keeps its folder.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, CLIENT, ROOT, check, createProduct, finish, login, logSize, meta, shot, sql, start } = require( './lib.cjs' );

const CLIENT_ROOT = path.join( ROOT, 'client' );
const CLIENT_LOG = path.join( CLIENT_ROOT, 'wp-content/debug.log' );
const THEMES = path.join( CLIENT_ROOT, 'wp-content/themes' );
const SLUG = 'wplit-sample-theme';
const THEME = path.join( THEMES, SLUG );
const onClient = ( code ) => execFileSync( 'php', [ '-r', 'require "' + path.join( CLIENT_ROOT, 'wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const installedVersion = () => ( fs.readFileSync( path.join( THEME, 'style.css' ), 'utf8' ).match( /^Version:\s*(.+)$/m ) || [] )[ 1 ];

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

( async () => {
	// Start clean, on the default theme.
	const before = onClient( 'echo get_stylesheet();' );
	const fallback = SLUG === before ? 'twentytwentyfive' : before;
	onClient( 'switch_theme( "' + fallback + '" );' );
	fs.rmSync( THEME, { recursive: true, force: true } );
	sql( "DELETE FROM wp_options WHERE option_name LIKE '%wplit_c_" + SLUG + "%' OR option_name='wplit_client_" + SLUG + "'", 'wp_client' );

	const logStart = logSize();
	const clientLogStart = ( () => { try { return fs.statSync( CLIENT_LOG ).size; } catch ( e ) { return 0; } } )();
	const env = await start();
	const page = env.page;
	await login( page );
	await login( page, CLIENT );

	// The theme product, its first release, and the code from its Connect tab.
	const productId = await createProduct( page, 'Sample Theme Product' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.sampleTheme( '1.0.0' ) );
	await save( page );
	check( 'theme' === meta( productId, 'wplit_package_type' ), 'the release is read as a theme' );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const code = ( await page.inputValue( '#wplit-connect-code' ) ).replace( /^<\?php\s*/, '' );
	check( /'type'\s*=>\s*'theme'/.test( code ) && code.includes( "'slug'" ) && code.includes( SLUG ), 'the Connect code is the theme version, for this folder' );

	execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->issue_license( ' + productId + ', "theme-buyer@example.com", null, null, 1, null );' ] );
	const key = sql( 'SELECT license_key FROM wp_wplit_licenses WHERE product_id=' + productId + ' ORDER BY id DESC LIMIT 1' );
	check( '' !== key, 'a license was issued for the theme' );

	// Install 1.0.0 with the code, activate it and its license.
	execFileSync( 'unzip', [ '-q', '-o', fixtures.sampleTheme( '1.0.0', '', code ), '-d', THEMES ] );
	onClient( 'switch_theme( "' + SLUG + '" );' );
	check( SLUG === onClient( 'echo get_stylesheet();' ) && '1.0.0' === installedVersion(), 'the theme 1.0.0 is installed and active on the customer site' );
	await page.goto( CLIENT + '/wp-admin/options-general.php?page=' + SLUG + '-license' );
	await page.locator( 'input[name="license_key"], input[type="text"]' ).first().fill( key );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'form [type="submit"]' ).filter( { hasText: /activate/i } ).first().click() ] );
	await shot( page, '52-theme-license' );
	check( '1' === sql( "SELECT COUNT(*) FROM wp_wplit_activations a JOIN wp_wplit_licenses l ON l.id=a.license_id WHERE l.license_key='" + key + "' AND a.status='active'" ), 'the theme\'s license page activates the license' );

	// Release 1.1.0 with a changelog.
	await page.goto( BASE + '/wp-admin/post.php?post=' + productId + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.sampleTheme( '1.1.0', '= 1.1.0 =\n* A new header.\n* <b>Bold</b> claims.\n\n= 1.0.0 =\n* First.\n', code ) );
	await save( page );
	check( '1.1.0' === meta( productId, 'wplit_product_version' ), 'release 1.1.0 is current' );

	// The update is offered.
	let updates = '';
	for ( let attempt = 0; attempt < 4; attempt++ ) {
		onClient( 'delete_site_transient( "update_themes" );' );
		await page.goto( CLIENT + '/wp-admin/update-core.php?force-check=1' );
		updates = await page.locator( '#wpbody-content' ).innerText();
		if ( /WPLit Sample Theme/.test( updates ) && /1\.1\.0/.test( updates ) ) { break; }
		await page.waitForTimeout( 3000 );
	}
	await shot( page, '53-theme-update-offered' );
	check( /WPLit Sample Theme/.test( updates ) && /1\.1\.0/.test( updates ), 'the Updates screen offers WPLit Sample Theme 1.1.0' );

	// View version details, from the Themes screen.
	await page.goto( CLIENT + '/wp-admin/themes.php?theme=' + SLUG );
	const link = page.locator( '.theme-overlay a.open-plugin-details-modal' ).first();
	await link.waitFor( { timeout: 15000 } );
	const href = await link.getAttribute( 'href' );
	check( /admin-ajax\.php\?action=wplicense_it_theme_details&(amp;)?theme=wplit-sample-theme/.test( href ) && ! href.startsWith( BASE ), 'the details link goes to a page on the customer\'s own site: ' + href );
	await link.click();
	const frame = page.frameLocator( '#TB_iframeContent' );
	await frame.locator( 'h1' ).waitFor( { timeout: 15000 } );
	await shot( page, '54-theme-details' );
	const details = await frame.locator( 'body' ).innerText();
	check( /Sample Theme Product/.test( details ) && /Version:\s*1\.1\.0/.test( details ), 'the popup shows the product name and the new version' );
	check( /Requires WordPress:\s*6\.2/.test( details ) && /Tested up to:/.test( details ), 'and the requirements' );
	check( /What is new in 1\.1\.0/.test( details ) && /A new header\./.test( details ), 'and what is new' );
	check( /Bold claims\./.test( details ) && 0 === await frame.locator( 'body b, body script' ).count(), 'the notes are plain text: no markup from the release reaches the page' );

	// Someone who may not see theme updates gets nothing.
	onClient( 'if ( ! get_user_by( "login", "wplit_theme_author" ) ) { wp_insert_user( array( "user_login" => "wplit_theme_author", "user_pass" => "pw-theme-author-1", "user_email" => "wplit_theme_author@example.test", "role" => "author" ) ); }' );
	const detailsUrl = new URL( href.replace( /&amp;/g, '&' ), CLIENT ).href;
	const author = await env.browser.newContext();
	const authorPage = await author.newPage();
	await authorPage.goto( CLIENT + '/wp-login.php' );
	await authorPage.fill( '#user_login', 'wplit_theme_author' );
	await authorPage.fill( '#user_pass', 'pw-theme-author-1' );
	await Promise.all( [ authorPage.waitForNavigation( { waitUntil: 'load' } ), authorPage.click( '#wp-submit' ) ] );
	const refused = await authorPage.request.get( detailsUrl );
	check( 403 === refused.status() && ! /1\.1\.0/.test( await refused.text() ), 'an author is refused the details page: ' + refused.status() );
	await author.close();

	// Install the update.
	await page.goto( CLIENT + '/wp-admin/update-core.php' );
	await page.check( 'input[type="checkbox"][value="' + SLUG + '"]' );
	await Promise.all( [ page.waitForURL( /update\.php|update-core\.php/ ), page.click( '#upgrade-themes' ) ] );
	await page.waitForLoadState( 'networkidle' );
	await shot( page, '55-theme-updated' );
	check( '1.1.0' === installedVersion(), 'the theme on disk is now 1.1.0, got ' + installedVersion() );
	check( SLUG === onClient( 'echo get_stylesheet();' ) && fs.existsSync( path.join( THEME, 'vendor/wplicense-it-client/wplicense-it-client.php' ) ), 'it is still the active theme, in its own folder, with the client code' );

	// Leave the customer site as it was.
	onClient( 'switch_theme( "' + fallback + '" );' );
	const clientLines = ( () => { try { return fs.readFileSync( CLIENT_LOG, 'utf8' ).slice( clientLogStart ).split( '\n' ).filter( ( l ) => l.trim() && ! /PHP Deprecated:.*(wp-includes|wp-admin)\/|(wp_version_check|wp_update_plugins|wp_update_themes|get_core_checksums)\(\): An unexpected error/.test( l ) ); } catch ( e ) { return []; } } )();
	check( 0 === clientLines.length, 'the customer site logged nothing new: ' + clientLines.slice( 0, 6 ).join( ' | ' ) );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
