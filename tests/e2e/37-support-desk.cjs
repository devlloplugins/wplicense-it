/**
 * Card 104 (the review of manual management, the support desk):
 * - the Licenses search finds a license by a site it is activated on (with or without www and https) and by its order
 *   number (1234 or #1234);
 * - changing a license's email moves it to the account with that address (and its My Account); a license added by hand
 *   without a user goes to the account with its email;
 * - "Email the key to the customer" sends the key, sites and how to activate;
 * - "Replace the key" gives a new key, the old one stops working, the site keeps its place, and the new key is emailed;
 * - a private note shows in the history, and erasing the customer's personal data removes it.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const php = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const api = ( route, body ) => JSON.parse( execFileSync( 'curl', [ '-s', '-X', 'POST', BASE + '/wp-json/wplicense-it/v1/' + route, '-d', body ], { encoding: 'utf8' } ) );
const listUrl = ( search ) => BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses&s=' + encodeURIComponent( search );

async function listed( page, search, key ) {
	await page.goto( listUrl( search ) );
	return ( await page.locator( '#the-list' ).innerText() ).includes( key );
}

async function submit( page, button ) {
	const target = page.locator( 'button:has-text("' + button + '"), input[type="submit"][value="' + button + '"]' ).first();
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), target.click() ] );
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	const stamp = Date.now();
	const productId = await createProduct( page, 'Support Desk Product' );
	const wooId = Number( woo( { op: 'create_simple_product', name: 'Support Desk Plan', price: '30' } ).product_id );
	woo( { op: 'set_product_meta', product_id: wooId, key: '_wplit_product_id', value: String( productId ) } );
	woo( { op: 'set_product_meta', product_id: wooId, key: '_wplit_activation_limit', value: '2' } );
	const a = woo( { op: 'create_user', login: 'desk-a-' + stamp, email: 'desk-a-' + stamp + '@example.com', password: 'pw-desk-a-1' } );
	const b = woo( { op: 'create_user', login: 'desk-b-' + stamp, email: 'desk-b-' + stamp + '@example.com', password: 'pw-desk-b-1' } );
	const order = woo( { op: 'create_order', product_id: wooId, qty: 1, email: 'desk-a-' + stamp + '@example.com', user_id: a.user_id, status: 'completed' } );
	const [ licenseId, key ] = sql( "SELECT l.id, l.license_key FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + order.order_id + "'" ).split( '\t' );
	const site = 'https://www.desk-site-' + stamp + '.example.com/';
	check( true === api( 'licenses/activate', 'license_key=' + key + '&product_id=' + productId + '&site_url=' + encodeURIComponent( site ) ).success, 'the license is active on a site' );

	// 1. Search by site address and by order number.
	check( await listed( page, 'desk-site-' + stamp + '.example.com', key ), 'searching the site address finds the license' );
	check( await listed( page, 'https://www.desk-site-' + stamp + '.example.com', key ), 'also typed with https and www' );
	check( await listed( page, '#' + order.order_id, key ) && await listed( page, String( order.order_id ), key ), 'searching the order number, with or without #, finds it' );
	check( ! await listed( page, 'desk-site-nobody-' + stamp + '.example.com', key ), 'another site does not' );

	// 2. A new email moves the license to that customer's account.
	const view = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses&license=' + licenseId;
	await page.goto( view );
	await page.fill( 'input[name="email"]', 'desk-b-' + stamp + '@example.com' );
	await submit( page, 'Save changes' );
	check( String( b.user_id ) === sql( 'SELECT user_id FROM wp_wplit_licenses WHERE id=' + licenseId ), 'changing the email links the license to the account with that address' );
	check( /user_id: \d+ → \d+/.test( await page.locator( 'body' ).innerText() ), 'and the history records the account change' );

	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-add-license' );
	await page.selectOption( 'select[name="product_id"]', String( productId ) );
	await page.fill( 'input[name="email"]', 'desk-a-' + stamp + '@example.com' );
	await submit( page, 'Issue license' );
	const added = new URL( page.url() ).searchParams.get( 'license' );
	check( String( a.user_id ) === sql( 'SELECT user_id FROM wp_wplit_licenses WHERE id=' + Number( added ) ), 'a license added by hand without a user goes to the account with its email' );

	// 3. Email the key again.
	woo( { op: 'clear_mail' } );
	await page.goto( view );
	await submit( page, 'Email the key to the customer' );
	let mail = woo( { op: 'mail' } ).mail;
	check( 1 === mail.length && mail[ 0 ].to.includes( 'desk-b-' + stamp ) && mail[ 0 ].message.includes( key ) && /Sites: 2/.test( mail[ 0 ].message ), 'the customer gets the key and the sites again: ' + JSON.stringify( mail ).slice( 0, 200 ) );
	check( /The license key was emailed to the customer/.test( await page.locator( '.notice' ).allInnerTexts().then( ( t ) => t.join( ' ' ) ) ), 'and the screen says so' );

	// 4. Replace a leaked key.
	woo( { op: 'clear_mail' } );
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await submit( page, 'Replace the key' );
	const newKey = sql( 'SELECT license_key FROM wp_wplit_licenses WHERE id=' + licenseId );
	check( newKey !== key && newKey.length === key.length, 'the license has a new key' );
	check( 'not_found' === api( 'licenses/validate', 'license_key=' + key + '&product_id=' + productId + '&site_url=' + encodeURIComponent( site ) ).code, 'the old key no longer works' );
	check( true === api( 'licenses/validate', 'license_key=' + newKey + '&product_id=' + productId + '&site_url=' + encodeURIComponent( site ) ).success, 'the new key works on the same site, which kept its place' );
	mail = woo( { op: 'mail' } ).mail;
	check( 1 === mail.length && mail[ 0 ].message.includes( newKey ), 'the new key was emailed' );

	// 5. A private note, and erasing the customer's data removes it.
	await page.fill( '#wplit-note', 'Customer ' + stamp + ' asked for a new key after posting it on a forum.' );
	await submit( page, 'Add note' );
	await shot( page, '68-support-desk' );
	const body = await page.locator( 'body' ).innerText();
	check( /Note added/.test( body ) && body.includes( 'asked for a new key after posting it on a forum' ) && /Key replaced/.test( body ) && /Key emailed to the customer/.test( body ), 'the note, the replaced key and the emails are in the history' );
	php( 'foreach ( apply_filters( "wp_privacy_personal_data_erasers", array() ) as $eraser ) { if ( false !== strpos( $eraser["eraser_friendly_name"], "License" ) ) { call_user_func( $eraser["callback"], "desk-b-' + stamp + '@example.com", 1 ); } }' );
	check( '0' === sql( "SELECT COUNT(*) FROM wp_wplit_license_events WHERE license_id=" + licenseId + " AND type='note'" ), 'erasing the customer\'s personal data removes the notes' );
	check( '0' === sql( "SELECT COUNT(*) FROM wp_wplit_license_events WHERE license_id=" + licenseId + " AND data LIKE '%desk-b-" + stamp + "%'" ), 'and their address from the history' );

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
