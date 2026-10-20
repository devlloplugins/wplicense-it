/**
 * Which client a wrong license key is counted against, behind a proxy or Cloudflare (Licensing settings > API protection >
 * Visitors reach this site). The test site is reached directly from 127.0.0.1, so it plays the proxy: with "another proxy"
 * and 127.0.0.1 listed, each X-Forwarded-For client gets its own counter; with "Cloudflare" the CF-Connecting-IP header is
 * ignored because 127.0.0.1 is not one of Cloudflare's addresses; and "Directly" ignores the headers too, so forged headers
 * cannot be used to dodge the limit.
 */
const { BASE, check, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const SETTINGS = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings';
const API = BASE + '/wp-json/wplicense-it/v1/licenses/validate';
const option = ( name ) => sql( "SELECT option_value FROM wp_options WHERE option_name='" + name + "'" );
const setOption = ( name, value ) => {
	sql( "DELETE FROM wp_options WHERE option_name='" + name + "'" );
	if ( null !== value && '' !== value ) { sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" + name + "', '" + value + "', 'yes')" ); }
};
const clearLimits = () => sql( "DELETE FROM wp_options WHERE option_name LIKE '\\_transient%wplit\\_rl\\_%'" );
const NAMES = [ 'wplit_client_ip_source', 'wplit_trusted_proxies', 'wplit_rate_limit_failures', 'wplit_rate_limit_minutes' ];

( async () => {
	const logStart = logSize();
	const saved = Object.fromEntries( NAMES.map( ( name ) => [ name, option( name ) ] ) );
	setOption( 'wplit_rate_limit_failures', '3' );
	setOption( 'wplit_rate_limit_minutes', '15' );
	clearLimits();

	const env = await start();
	const page = env.page;
	await login( page );
	const wrong = async ( headers, n ) => {
		const r = await page.request.post( API, { headers, data: { license_key: 'WRONG-KEY-' + Date.now() + '-' + n, product_id: 1 } } );
		return r.status();
	};
	const statuses = async ( headers, count ) => {
		const out = [];
		for ( let i = 0; i < count; i++ ) { out.push( await wrong( headers, i ) ); }
		return out;
	};

	// 1. The setting, chosen in the admin.
	await page.goto( SETTINGS );
	check( true === await page.locator( 'input[name="wplit_client_ip_source"][value="direct"]' ).isChecked(), '"Directly" is the default' );
	await page.check( 'input[name="wplit_client_ip_source"][value="proxy"]' );
	await page.fill( '#wplit_trusted_proxies', '127.0.0.1\nnot an address\n10.0.0.0/8' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit' ) ] );
	check( 'proxy' === option( 'wplit_client_ip_source' ), 'the choice is stored' );
	check( '127.0.0.1\n10.0.0.0/8' === option( 'wplit_trusted_proxies' ), 'the proxies are stored without the line that is not an address: ' + JSON.stringify( option( 'wplit_trusted_proxies' ) ) );
	await page.goto( SETTINGS );
	check( /requested from 127\.0\.0\.1, and the plugin counts it as 127\.0\.0\.1/.test( await page.locator( 'body' ).innerText() ), 'the screen says which address this request is counted as' );
	await shot( page, '42-client-ip' );

	// 2. Behind a listed proxy, each forwarded client has its own counter.
	clearLimits();
	const first = await statuses( { 'X-Forwarded-For': '198.51.100.10' }, 4 );
	check( JSON.stringify( first.slice( 0, 3 ) ) !== JSON.stringify( [ 429, 429, 429 ] ) && 429 === first[ 3 ], 'one forwarded client is blocked after 3 wrong keys: ' + first.join( ' ' ) );
	check( 429 !== await wrong( { 'X-Forwarded-For': '198.51.100.11' }, 9 ), 'another client behind the same proxy is not blocked' );
	check( 429 !== await wrong( { 'X-Forwarded-For': '198.51.100.10, 198.51.100.12' }, 10 ), 'what a client writes on the left does not matter: the rightmost address is the client' );
	check( 429 === await wrong( { 'X-Forwarded-For': '1.2.3.4, 198.51.100.10' }, 11 ), 'and the blocked client cannot get out by adding a forged address on the left' );

	// 3. Cloudflare chosen, but the request did not come from Cloudflare: the header is ignored.
	setOption( 'wplit_client_ip_source', 'cloudflare' );
	clearLimits();
	const spoofed = [];
	for ( let i = 0; i < 4; i++ ) { spoofed.push( await wrong( { 'CF-Connecting-IP': '198.51.100.' + ( 50 + i ) }, 20 + i ) ); }
	check( 429 === spoofed[ 3 ], 'with Cloudflare chosen, a request not from Cloudflare cannot pick its address with CF-Connecting-IP: ' + spoofed.join( ' ' ) );

	// 4. Directly: forwarding headers are ignored.
	setOption( 'wplit_client_ip_source', 'direct' );
	clearLimits();
	const direct = [];
	for ( let i = 0; i < 4; i++ ) { direct.push( await wrong( { 'X-Forwarded-For': '198.51.100.' + ( 60 + i ) }, 30 + i ) ); }
	check( 429 === direct[ 3 ], 'with "Directly", X-Forwarded-For is ignored: ' + direct.join( ' ' ) );

	// Put everything back.
	for ( const name of NAMES ) { setOption( name, saved[ name ] ); }
	clearLimits();

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
