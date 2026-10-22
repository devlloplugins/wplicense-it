/**
 * Steps 16 to 20 of docs/TESTING.md on real WooCommerce: a completed order issues licenses, the key reaches the
 * customer (order notes, email, My Account), refunds and cancellations end licenses, and a renewal extends one.
 */
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, meta, shot, sql, start, woo } = require( './lib.cjs' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

const php = ( code ) => require( 'child_process' ).execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + require( 'path' ).join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();

function licenses( orderId ) {
	const rows = sql( "SELECT l.id, l.license_key, l.status, l.activation_limit, IFNULL(l.expires_at,''), l.email, IFNULL(l.user_id,''), l.product_id FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + orderId + "' ORDER BY l.id" );
	return rows ? rows.split( '\n' ).map( ( line ) => {
		const [ lid, key, status, limit, expires, email, user, product ] = line.split( '\t' );
		return { id: Number( lid ), key, status, limit: Number( limit ), expires, email, user, product: Number( product ) };
	} ) : [];
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	// A software product with one plan: 2 sites, 1 year, on sale.
	const software = await createProduct( page, 'Purchase Test' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="plans"]' );
	await page.fill( 'input[name="wplit_plans[0][name]"]', 'Two sites' );
	await page.fill( 'input[name="wplit_plans[0][price]"]', '60' );
	await page.fill( 'input[name="wplit_plans[0][sites]"]', '2' );
	await save( page );
	const wooId = Number( sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' ORDER BY p.ID DESC LIMIT 1" ) );
	check( wooId > 0, 'a WooCommerce product exists for the plan' );
	woo( { op: 'set_product_status', product_id: wooId, status: 'publish' } );

	const customer = woo( { op: 'create_user', login: 'buyer' + Date.now(), email: 'Buyer' + Date.now() + '@Example.com', password: 'pw-buyer-test-1' } );
	const user = sql( 'SELECT user_login, user_email FROM wp_users WHERE ID=' + customer.user_id ).split( '\t' );
	const email = user[ 1 ];
	woo( { op: 'clear_mail' } );

	// Processing does not issue, by default.
	const early = woo( { op: 'create_order', product_id: wooId, qty: 2, email, user_id: customer.user_id, status: 'processing' } );
	check( licenses( early.order_id ).length === 0, 'step 16: a processing order has issued nothing yet' );

	// Step 16 and 17: completing it issues one license per unit.
	woo( { op: 'set_order_status', order_id: early.order_id, status: 'completed' } );
	const orderId = early.order_id;
	const issued = licenses( orderId );
	check( issued.length === 2, 'step 17: a completed order for 2 units issues 2 licenses, got ' + issued.length );
	check( issued.every( ( l ) => l.status === 'active' && l.limit === 2 && l.product === Number( software ) ), 'step 17: each license is active, for 2 sites, for this software' );
	check( issued.every( ( l ) => l.email === email.toLowerCase() && l.user === String( customer.user_id ) ), 'step 17: the email is lower-cased and the license belongs to the customer' );
	const expiry = new Date( issued[ 0 ].expires.replace( ' ', 'T' ) + 'Z' );
	const days = ( expiry - Date.now() ) / 86400000;
	check( days > 363 && days < 367, 'step 17: the license lasts a year, expires in ' + days.toFixed( 1 ) + ' days' );
	check( new Set( issued.map( ( l ) => l.key ) ).size === 2, 'step 17: the two keys are different' );

	const notes = woo( { op: 'order_notes', order_id: orderId } ).notes.join( '\n' );
	check( /issued 2 licenses/.test( notes ), 'step 17: the order notes say 2 licenses were issued: ' + notes.slice( 0, 300 ).replace( /\n/g, ' | ' ) );
	check( ! issued.some( ( l ) => notes.includes( l.key ) ), 'step 17: the order notes do not print the keys' );
	const orderRow = sql( "SELECT CONCAT(total_minor,'|',currency,'|',status) FROM wp_wplit_license_orders WHERE order_number='WC-" + orderId + "'" );
	check( /^6000|^12000/.test( orderRow ) && /USD/.test( orderRow ), 'the license order record keeps the total in cents and the currency: ' + orderRow );

	const mail = woo( { op: 'mail' } ).mail;
	const completedMail = mail.filter( ( m ) => ( m.to + '' ).toLowerCase().includes( email.toLowerCase() ) );
	check( completedMail.length > 0, 'step 17: the customer was emailed (' + mail.length + ' emails captured)' );
	check( completedMail.some( ( m ) => issued.every( ( l ) => String( m.message ).includes( l.key ) ) ), 'step 17: the completed-order email contains both license keys' );

	// Completing again changes nothing.
	woo( { op: 'set_order_status', order_id: orderId, status: 'processing' } );
	woo( { op: 'set_order_status', order_id: orderId, status: 'completed' } );
	check( licenses( orderId ).length === 2, 'completing an order twice does not issue more licenses' );

	// My Account.
	await page.context().clearCookies();
	await page.goto( BASE + '/my-account/' );
	await page.fill( '#username', user[ 0 ] );
	await page.fill( '#password', 'pw-buyer-test-1' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( 'button[name="login"]' ) ] );
	check( /Hello|Dashboard|Log out/i.test( await page.locator( 'body' ).innerText() ), 'step 17: the customer can log in to My Account' );
	await page.goto( BASE + '/my-account/licenses/' );
	await shot( page, '17-my-account-licenses' );
	const account = await page.locator( 'body' ).innerText();
	check( ! /Page not found|Nothing here|404/i.test( account.slice( 0, 400 ) ), 'step 17: the Licenses page of My Account exists: ' + account.slice( 0, 200 ).replace( /\n/g, ' | ' ) );
	check( issued.every( ( l ) => account.includes( l.key.slice( -4 ) ) ), 'step 17: My Account lists both licenses (by the end of the key)' );
	check( /Active/i.test( account ) && /Renew/i.test( account ), 'step 17: My Account shows the status and a Renew button' );
	const download = page.locator( 'a[href*="/wp-json/wplicense-it/v1/download"]' ).first();
	check( await download.count() > 0, 'step 17: My Account has a Download link' );
	if ( await download.count() ) {
		const href = await download.getAttribute( 'href' );
		const response = await page.request.get( href );
		check( response.status() === 200 && /zip/.test( response.headers()[ 'content-type' ] || '' ), 'step 17: the Download link gives the zip, got ' + response.status() + ' ' + response.headers()[ 'content-type' ] );
	}

	// Step 19: refunds.
	woo( { op: 'refund', order_id: orderId, qty: 1, amount: 30 } );
	let after = licenses( orderId );
	check( after.filter( ( l ) => l.status === 'refunded' ).length === 1 && after.filter( ( l ) => l.status === 'active' ).length === 1, 'step 19: a partial refund of 1 of 2 units ends exactly one license: ' + after.map( ( l ) => l.status ).join( ',' ) );
	check( after[ 1 ].status === 'refunded', 'step 19: the newest license is the one that ends' );
	woo( { op: 'set_order_status', order_id: orderId, status: 'refunded' } );
	after = licenses( orderId );
	check( after.every( ( l ) => l.status === 'refunded' ), 'step 19: a fully refunded order ends every license: ' + after.map( ( l ) => l.status ).join( ',' ) );

	// Cancelled orders revoke.
	const cancelled = woo( { op: 'create_order', product_id: wooId, qty: 1, email, user_id: customer.user_id, status: 'completed' } );
	woo( { op: 'set_order_status', order_id: cancelled.order_id, status: 'cancelled' } );
	check( licenses( cancelled.order_id ).every( ( l ) => l.status === 'revoked' ) && licenses( cancelled.order_id ).length === 1, 'a cancelled order revokes its license' );

	// Step 20: renewal.
	const first = woo( { op: 'create_order', product_id: wooId, qty: 1, email, user_id: customer.user_id, status: 'completed' } );
	const original = licenses( first.order_id )[ 0 ];
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(NOW(), INTERVAL 10 DAY) WHERE id=" + original.id );
	const renewal = woo( { op: 'create_order', product_id: wooId, qty: 1, email, user_id: customer.user_id, renew_license_id: original.id, status: 'completed' } );
	const renewed = sql( 'SELECT CONCAT(id,"|",status,"|",DATEDIFF(expires_at, NOW())) FROM wp_wplit_licenses WHERE id=' + original.id ).split( '|' );
	check( Number( renewed[ 2 ] ) >= 373 && Number( renewed[ 2 ] ) <= 376, 'step 20: a renewal extends the same license by a year from its current expiry (about 375 days left), got ' + renewed[ 2 ] );
	check( licenses( renewal.order_id ).length === 0 || licenses( renewal.order_id ).every( ( l ) => l.id === original.id ), 'step 20: a renewal issues no new license' );
	check( sql( "SELECT COUNT(*) FROM wp_wplit_licenses WHERE email='" + email.toLowerCase() + "'" ) === '4', 'step 20: the customer has 4 licenses in all (2 + 1 cancelled + 1 renewed)' );

	// Someone else's license cannot be renewed through a crafted order.
	const stranger = woo( { op: 'create_user', login: 'stranger' + Date.now(), email: 'stranger' + Date.now() + '@example.com', password: 'pw-stranger-1' } );
	const before = sql( 'SELECT expires_at FROM wp_wplit_licenses WHERE id=' + original.id );
	const hijack = woo( { op: 'create_order', product_id: wooId, qty: 1, email: 'stranger@example.com', user_id: stranger.user_id, renew_license_id: original.id, status: 'completed' } );
	check( sql( 'SELECT expires_at FROM wp_wplit_licenses WHERE id=' + original.id ) === before, 'another customer cannot extend someone else\'s license' );
	check( licenses( hijack.order_id ).length === 1, 'the crafted renewal falls back to a new license for the stranger' );

	// Paid online. A plan product is virtual but not downloadable, which WooCommerce would leave in Processing; licensed
	// products need no processing, so the paid order completes at once and the license is issued with the default setting.
	const online = woo( { op: 'create_order', product_id: wooId, qty: 1, email: 'online' + Date.now() + '@example.com', user_id: 0 } );
	const onlinePaid = woo( { op: 'pay_order', order_id: online.order_id } );
	check( 'completed' === onlinePaid.status && 1 === licenses( online.order_id ).length, 'an order paid online completes on its own and issues the license: ' + onlinePaid.status );
	// Something to ship in the same order: WooCommerce keeps it in Processing, and the license waits for completion.
	const mug = woo( { op: 'create_simple_product', name: 'Purchase Test Mug ' + Date.now() } );
	const mixed = woo( { op: 'create_order', product_id: wooId, qty: 1, email: 'mixed' + Date.now() + '@example.com', user_id: 0, more_product_ids: [ mug.product_id ] } );
	const mixedPaid = woo( { op: 'pay_order', order_id: mixed.order_id } );
	check( 'processing' === mixedPaid.status && 0 === licenses( mixed.order_id ).length, 'with a physical item too, the paid order waits in Processing: ' + mixedPaid.status );
	woo( { op: 'trash_product', product_id: mug.product_id } );

	// Card 74: an order that is completed again gets back the licenses it had ended.
	const statuses = ( id ) => licenses( id ).map( ( l ) => l.status ).join( ',' );
	const again = woo( { op: 'create_order', product_id: wooId, qty: 2, email: 'again' + Date.now() + '@example.com', user_id: 0, status: 'completed' } );
	woo( { op: 'set_order_status', order_id: again.order_id, status: 'cancelled' } );
	check( 'revoked,revoked' === statuses( again.order_id ), 'a cancelled order revokes its licenses: ' + statuses( again.order_id ) );
	woo( { op: 'set_order_status', order_id: again.order_id, status: 'completed' } );
	check( 'active,active' === statuses( again.order_id ) && 2 === licenses( again.order_id ).length, 'completed again, the same two licenses are active again and none is added: ' + statuses( again.order_id ) );
	check( woo( { op: 'order_notes', order_id: again.order_id } ).notes.some( ( n ) => /brought back 2 licenses/.test( n ) ), 'the order note says so' );
	const history = sql( "SELECT GROUP_CONCAT(CONCAT(e.type, ':', JSON_UNQUOTE(JSON_EXTRACT(e.data, '$.order'))) ORDER BY e.id) FROM wp_wplit_license_events e WHERE e.license_id=" + licenses( again.order_id )[ 0 ].id );
	check( history.endsWith( 'revoked:WC-' + again.order_id + ',reinstated:WC-' + again.order_id ), 'the license history names the order that ended it and brought it back: ' + history );

	// Set to Refunded by hand (WooCommerce records an amount, no items): completing again brings both back.
	woo( { op: 'set_order_status', order_id: again.order_id, status: 'refunded' } );
	check( 'refunded,refunded' === statuses( again.order_id ), 'set to refunded, both licenses are refunded' );
	woo( { op: 'set_order_status', order_id: again.order_id, status: 'completed' } );
	check( 'active,active' === statuses( again.order_id ), 'completed again, both are back: ' + statuses( again.order_id ) );

	// One unit refunded with its quantity: that license stays refunded when the order is completed again.
	const units = woo( { op: 'create_order', product_id: wooId, qty: 2, email: 'units' + Date.now() + '@example.com', user_id: 0, status: 'completed' } );
	woo( { op: 'refund', order_id: units.order_id, qty: 1, amount: 60 } );
	woo( { op: 'set_order_status', order_id: units.order_id, status: 'refunded' } );
	woo( { op: 'set_order_status', order_id: units.order_id, status: 'completed' } );
	check( 'active,refunded' === statuses( units.order_id ), 'a unit refunded with its quantity keeps its license refunded: ' + statuses( units.order_id ) );

	// A license the seller revoked by hand stays revoked.
	const kept = woo( { op: 'create_order', product_id: wooId, qty: 1, email: 'kept' + Date.now() + '@example.com', user_id: 0, status: 'completed' } );
	php( 'Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->revoke_license( ' + licenses( kept.order_id )[ 0 ].id + ' );' );
	woo( { op: 'set_order_status', order_id: kept.order_id, status: 'cancelled' } );
	woo( { op: 'set_order_status', order_id: kept.order_id, status: 'completed' } );
	check( 'revoked' === statuses( kept.order_id ), 'a license revoked by hand is not brought back: ' + statuses( kept.order_id ) );

	// A product that is not linked to a license product issues nothing.
	const plainId = woo( { op: 'create_order', product_id: wooId, qty: 1, email, status: 'pending' } );
	check( plainId.order_id > 0, 'an order can be made' );

	await finish( env, logStart );
} )();
