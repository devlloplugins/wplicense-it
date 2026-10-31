/**
 * The Overview screen: where it is in the menu, the setup steps ticking off as the site is set up (product, release, license,
 * SDK, reminders), the problems it warns about, the number of licenses in each state with links to the list, the licenses about
 * to expire (soonest first) and the newest ones, and who may open it. The test starts from a site with no products and no
 * licenses and builds it up.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const OVERVIEW = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-overview';
const php = ( code ) => execFileSync( 'php', [ '-d', 'memory_limit=512M', '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const option = ( name ) => sql( "SELECT option_value FROM wp_options WHERE option_name='" + name + "'" );
const OPTIONS = [ 'wplit_sdk_downloaded', 'wplit_last_maintenance', 'wplit_reminders_enabled', 'wplit_invoices_enabled' ];

( async () => {
	const logStart = logSize();
	const saved = Object.fromEntries( OPTIONS.map( ( name ) => [ name, option( name ) ] ) );
	sql( "DELETE FROM wp_options WHERE option_name IN ('" + OPTIONS.join( "','" ) + "')" );
	// A site with nothing set up: no products, no licenses.
	php( 'foreach ( get_posts( array( "post_type" => "wplit_product", "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) ) as $id ) { wp_delete_post( $id, true ); }' );
	for ( const table of [ 'licenses', 'activations', 'license_orders', 'license_events' ] ) { sql( 'TRUNCATE TABLE wp_wplit_' + table ); }

	const env = await start();
	const page = env.page;
	await login( page );
	const body = async () => ( await page.locator( 'body' ).innerText() ).replace( /\n+/g, ' | ' );
	const step = ( text ) => page.locator( '.wplit-steps li', { hasText: text } );

	// 1. In the menu, and the page opens.
	await page.goto( BASE + '/wp-admin/' );
	const submenu = ( await page.locator( '#menu-posts-wplit_product .wp-submenu' ).innerText() ).split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean );
	check( submenu.indexOf( 'Overview' ) === 1 && submenu[ 0 ] === 'All Products', 'Overview is the second entry of the menu, after All Products: ' + submenu.join( ', ' ) );
	check( 200 === ( await page.goto( OVERVIEW ) ).status(), 'the overview opens' );
	check( await page.locator( '.wrap h1' ).innerText() === 'Overview', 'its heading is Overview' );

	// 2. A site with nothing: every step still to do, and the numbers are empty.
	await shot( page, '18-empty' );
	check( /Getting started/.test( await body() ), 'a new site sees "Getting started"' );
	check( await step( 'Create your first product' ).locator( 'a' ).count() === 1, 'the first step is a link to make a product' );
	for ( const text of [ 'Create your first product', 'Upload a release', 'Issue a license' ] ) {
		check( await step( text ).filter( { hasText: 'To do:' } ).count() === 1, 'the step "' + text + '" is still to do' );
	}
	check( /No licenses yet/.test( await body() ) && /Nothing expires soon/.test( await body() ), 'the lists say there is nothing yet' );
	const numbers = ( await page.locator( '.wplit-numbers a' ).allInnerTexts() ).map( ( t ) => t.replace( /\s+/g, ' ' ) );
	check( numbers.join( ',' ) === '0 Active,0 Expired,0 Revoked,0 Refunded', 'all four numbers are zero: ' + numbers.join( ',' ) );
	check( /WP-Cron is switched off/.test( await body() ), 'the test site has WP-Cron switched off, and the screen says what that stops' );
	check( ! /no release uploaded/.test( await body() ), 'no warning about releases while there are no products' );

	// 3. A product, without a release: the step is done, and the missing release is a problem.
	const product = await createProduct( page, 'Overview Product' );
	await page.goto( OVERVIEW );
	check( await step( 'Create your first product' ).filter( { hasText: 'Done:' } ).count() === 1, 'after making a product its step is ticked' );
	check( await step( 'Upload a release' ).filter( { hasText: 'To do:' } ).count() === 1, 'the release step is still to do' );
	check( /1 published product has no release uploaded/.test( await body() ), 'a warning says the product has no release: ' + ( await body() ).slice( 0, 300 ) );
	check( await page.locator( '.notice-warning a', { hasText: 'Open the products' } ).count() === 1, 'the warning links to the products' );

	// 4. A release: the step is ticked and the warning goes.
	await page.goto( BASE + '/wp-admin/post.php?post=' + product + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
	await page.goto( OVERVIEW );
	check( await step( 'Upload a release' ).filter( { hasText: 'Done:' } ).count() === 1, 'after uploading a release its step is ticked' );
	check( ! /no release uploaded/.test( await body() ), 'and the warning is gone' );
	check( /Issue a license/.test( await body() ) && await step( 'Issue a license' ).filter( { hasText: 'To do:' } ).count() === 1, 'issuing a license is the step left' );

	// 5. Licenses in different states and with different expiry dates.
	const ids = JSON.parse( php( '$s = \\Devllo\\WPLicenseIt\\Plugin::instance()->licenses(); $utc = new DateTimeZone( "UTC" ); $ids = array(); foreach ( array( "+5 days", "+20 days", null, "+100 days", "+120 days", "+200 days", "+300 days", "+31 days", "+40 days", "+50 days", "+60 days", "+70 days" ) as $i => $when ) { $l = $s->issue_license( ' + product + ', "overview" . ( $i + 1 ) . "@example.com", null, null, 1, null === $when ? null : new DateTimeImmutable( $when, $utc ) ); $ids[] = $l->id; } $s->revoke_license( $ids[3] ); echo wp_json_encode( $ids );' ) );
	sql( "UPDATE wp_wplit_licenses SET status='expired', expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY) WHERE id=" + ids[ 4 ] );
	await page.goto( OVERVIEW );
	await shot( page, '18-overview' );
	check( await step( 'Issue a license' ).count() === 0, 'with a license the issue step is no longer listed' );
	check( /The basics are set up/.test( await body() ) && /Setup/.test( await page.locator( '#wplit-steps-title' ).innerText() ), 'the checklist says the basics are set up' );
	check( await step( 'Create your first product' ).count() === 0, 'and done steps are not repeated' );
	check( await step( 'client SDK' ).filter( { hasText: '(optional)' } ).count() === 1, 'the SDK step is offered as optional' );
	const counts = ( await page.locator( '.wplit-numbers a' ).allInnerTexts() ).map( ( t ) => t.replace( /\s+/g, ' ' ) );
	check( counts.join( ',' ) === '10 Active,1 Expired,1 Revoked,0 Refunded', 'the numbers are 10 active, 1 expired, 1 revoked: ' + counts.join( ',' ) );

	// The licenses that expire within 30 days, soonest first.
	const expiring = page.locator( '#wplit-expires-title' ).locator( 'xpath=..' ).locator( 'tbody tr' );
	check( await expiring.count() === 2, 'two licenses expire in the next 30 days, found ' + await expiring.count() );
	const firstExpiring = await expiring.nth( 0 ).innerText();
	const secondExpiring = await expiring.nth( 1 ).innerText();
	check( /overview1@example\.com/.test( firstExpiring ) && /5 days/.test( firstExpiring ), 'the soonest is first, in 5 days: ' + firstExpiring.replace( /\s+/g, ' ' ) );
	check( /overview2@example\.com/.test( secondExpiring ) && /20 days/.test( secondExpiring ), 'then the one in 20 days: ' + secondExpiring.replace( /\s+/g, ' ' ) );
	check( /Overview Product/.test( firstExpiring ), 'each row names the product' );

	// The newest licenses.
	const newest = page.locator( '#wplit-created-title' ).locator( 'xpath=..' ).locator( 'tbody tr' );
	check( await newest.count() === 8, 'eight newest licenses are listed, found ' + await newest.count() );
	check( /overview12@example\.com/.test( await newest.nth( 0 ).innerText() ) && /overview5@example\.com/.test( await newest.nth( 7 ).innerText() ), 'newest first: overview12 down to overview5' );
	const keyLink = newest.nth( 0 ).locator( 'a' ).first();
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), keyLink.click() ] );
	check( /overview12@example\.com/.test( await body() ) && /license=/.test( page.url() ), 'a license key opens that license' );

	// 6. A number is a link to the list in that state.
	await page.goto( OVERVIEW );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( '.wplit-numbers a', { hasText: 'Revoked' } ).click() ] );
	check( /status=revoked/.test( page.url() ) && /overview4@example\.com/.test( await body() ) && ! /overview1@example\.com/.test( await body() ), 'Revoked opens the list of revoked licenses: ' + page.url() );

	// 7. Downloading the SDK ticks its step, and turning on reminders ticks that one.
	await page.goto( OVERVIEW );
	const sdk = await step( 'client SDK' ).locator( 'a' ).getAttribute( 'href' );
	const download = await page.request.get( sdk );
	check( download.status() === 200 && ( await download.body() ).subarray( 0, 2 ).toString() === 'PK', 'the SDK link downloads a zip' );
	await page.goto( OVERVIEW );
	check( await step( 'client SDK' ).count() === 0, 'after downloading the SDK its step is gone' );
	check( await step( 'expiry reminder' ).count() === 1, 'turning on reminders is still suggested' );
	sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('wplit_reminders_enabled', '1', 'yes') ON DUPLICATE KEY UPDATE option_value='1'" );
	await page.goto( OVERVIEW );
	check( await step( 'expiry reminder' ).count() === 0, 'and once they are on it is gone' );

	// 8. With WP-Cron switched off the screen says so once, not also that the daily job is late.
	sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('wplit_last_maintenance', '" + ( Math.floor( Date.now() / 1000 ) - 5 * 86400 ) + "', 'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
	await page.goto( OVERVIEW );
	check( /WP-Cron is switched off/.test( await body() ) && ! /has not run for more than two days/.test( await body() ), 'only the WP-Cron warning, not the late daily job' );

	// 9. A person who cannot manage licenses cannot open it.
	php( 'if ( ! get_user_by( "login", "wplit_ovsub" ) ) { wp_insert_user( array( "user_login" => "wplit_ovsub", "user_pass" => "pw-wplit-1", "user_email" => "wplit_ovsub@example.test", "role" => "subscriber" ) ); }' );
	await page.context().clearCookies();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'wplit_ovsub' );
	await page.fill( '#user_pass', 'pw-wplit-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wp-submit' ) ] );
	const denied = await page.goto( OVERVIEW );
	check( denied.status() === 403 || /not allowed|permission/i.test( await body() ), 'a subscriber cannot open it (' + denied.status() + ')' );
	check( ! /overview1@example\.com/.test( await body() ), 'and sees no customer address' );

	// Put the options back.
	sql( "DELETE FROM wp_options WHERE option_name IN ('" + OPTIONS.join( "','" ) + "')" );
	for ( const [ name, value ] of Object.entries( saved ) ) {
		if ( '' !== value ) { sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" + name + "', '" + value + "', 'no')" ); }
	}
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
