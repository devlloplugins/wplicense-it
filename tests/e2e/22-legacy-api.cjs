/**
 * The old 1.x API addresses after the migration: calls are counted per product and shown on Licensing settings and the
 * Overview, and the site owner can switch the addresses off, after which they answer 410 Gone while the 2.0 API keeps working.
 * (The wrong-key limit before the migration is checked in 11-migration.cjs, where the site is still on 1.x data.)
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const SETTINGS = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings';
const OVERVIEW = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-overview';
const BOX = 'input[name="wplit_legacy_api_enabled"]';
const php = ( code ) => execFileSync( 'php', [ '-d', 'memory_limit=512M', '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const option = ( name ) => sql( "SELECT option_value FROM wp_options WHERE option_name='" + name + "'" );
const save = async ( page ) => Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit, input[type="submit"][value="Save Changes"]' ) ] );

( async () => {
	const logStart = logSize();
	// Every call here uses a wrong key, which counts against the client, so the limit is raised for the test.
	const saved = { enabled: option( 'wplit_legacy_api_enabled' ), usage: option( 'wplit_legacy_api_usage' ), failures: option( 'wplit_rate_limit_failures' ) };
	sql( "DELETE FROM wp_options WHERE option_name IN ('wplit_legacy_api_enabled','wplit_legacy_api_usage','wplit_rate_limit_failures')" );
	sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('wplit_rate_limit_failures', '100', 'yes')" );
	sql( "DELETE FROM wp_options WHERE option_name LIKE '\\_transient%wplit\\_rl\\_%'" );
	const product = Number( php( 'echo wp_insert_post( array( "post_type" => "wplit_product", "post_status" => "publish", "post_title" => "Legacy Usage Product" ) );' ) );

	const env = await start();
	const page = env.page;
	await login( page );
	const old = async ( action ) => {
		const response = await page.request.get( BASE + '/api/wplicense-it-api/v1/' + action + '?p=' + product + '&e=nobody%40example.com&l=NOT-A-KEY&k=nope' );
		return { status: response.status(), text: ( await response.text() ).trim() };
	};

	// 1. On by default, and calls are counted.
	let r = await old( 'status' );
	check( 200 === r.status && '"inactive"' === r.text, 'the old status address answers as before: ' + r.status + ' ' + r.text );
	await old( 'info' );
	const usage = JSON.parse( php( 'echo wp_json_encode( get_option( "wplit_legacy_api_usage" ) );' ) );
	const today = Object.keys( usage.days || {} );
	check( 1 === today.length && 2 === usage.days[ today[ 0 ] ][ product ], 'two calls are counted for the product: ' + JSON.stringify( usage.days ) );

	// A call can name any number; a post that is not a license product is not named by its title.
	const post = Number( php( 'echo wp_insert_post( array( "post_type" => "post", "post_status" => "publish", "post_title" => "Not A Product Post" ) );' ) );
	await page.request.get( BASE + '/api/wplicense-it-api/v1/status?p=' + post + '&e=a%40b.c&l=x&k=y' );
	await page.goto( SETTINGS );
	const named = await page.locator( 'tr:has(th:text("Old 1.x API addresses"))' ).last().innerText();
	check( ! /Not A Product Post/.test( named ) && named.includes( '#' + post ), 'a number that is not a license product is shown as a number, not by its title: ' + named.replace( /\s+/g, ' ' ) );
	php( 'wp_delete_post( ' + post + ', true );' );
	sql( "DELETE FROM wp_options WHERE option_name='wplit_legacy_api_usage'" );
	await old( 'status' );
	await old( 'info' );

	await page.goto( SETTINGS );
	check( true === await page.locator( BOX ).isChecked(), 'the setting to answer the old addresses is on by default' );
	const status = await page.locator( 'tr:has(th:text("Old 1.x API addresses"))' ).last().innerText();
	check( /2 calls in the last 30 days/.test( status ) && /Legacy Usage Product/.test( status ) && /^Old 1\.x API addresses\s+On\./.test( status ), 'the status says how many calls came and for which product: ' + status.replace( /\s+/g, ' ' ) );
	await shot( page, '41-legacy-api-settings' );

	await page.goto( OVERVIEW );
	const panel = await page.locator( '#wplit-legacy-title' ).locator( '..' ).innerText().catch( () => '' );
	check( /2 calls in the last 30 days/.test( panel ), 'the Overview shows it too: ' + panel.replace( /\s+/g, ' ' ).slice( 0, 160 ) );

	// 2. Switched off: the old addresses answer that they are gone, the 2.0 API is not affected.
	await page.goto( SETTINGS );
	await page.uncheck( BOX );
	await save( page );
	check( [ '', '0' ].includes( option( 'wplit_legacy_api_enabled' ) ), 'switching it off is stored' );
	for ( const action of [ 'status', 'info', 'get' ] ) {
		r = await old( action );
		check( 410 === r.status && /no longer available/.test( r.text ), 'switched off, the old ' + action + ' address answers 410 Gone: ' + r.status + ' ' + r.text.slice( 0, 90 ) );
	}
	const plain = await page.request.get( BASE + '/?__wp_license_api=status&p=' + product + '&e=a%40b.c&l=x&k=y' );
	check( 410 === plain.status(), 'so does the address without pretty links: ' + plain.status() );
	const rest = await page.request.post( BASE + '/wp-json/wplicense-it/v1/licenses/validate', { data: { license_key: 'NOT-A-KEY', product_id: product } } );
	check( 410 !== rest.status() && rest.status() < 500, 'the 2.0 API still answers: ' + rest.status() );
	await page.goto( SETTINGS );
	check( /Switched off/.test( await page.locator( 'tr:has(th:text("Old 1.x API addresses"))' ).last().innerText() ), 'the status says they are switched off' );

	// 3. Switched on again, they answer as before.
	await page.check( BOX );
	await save( page );
	r = await old( 'status' );
	check( 200 === r.status && '"inactive"' === r.text, 'switched on again, the old address answers as before: ' + r.status + ' ' + r.text );

	// Put everything back.
	php( 'wp_delete_post( ' + product + ', true );' );
	sql( "DELETE FROM wp_options WHERE option_name IN ('wplit_legacy_api_enabled','wplit_legacy_api_usage','wplit_rate_limit_failures')" );
	sql( "DELETE FROM wp_options WHERE option_name LIKE '\\_transient%wplit\\_rl\\_%'" );
	for ( const [ name, value ] of [ [ 'wplit_legacy_api_enabled', saved.enabled ], [ 'wplit_legacy_api_usage', saved.usage ], [ 'wplit_rate_limit_failures', saved.failures ] ] ) {
		if ( '' !== value ) { sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" + name + "', '" + value.replace( /'/g, "''" ) + "', 'no')" ); }
	}

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
