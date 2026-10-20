/**
 * The licenses of an order on the order's own admin screen (WooCommerce, here with high-performance order storage if the
 * store uses it): a box that lists each license with key, status, sites, expiry and a link, keeps listing a revoked one with
 * its status, says so when nothing was issued yet, is absent from orders that have nothing to do with licenses, and is not
 * shown to someone who may see orders but not manage licenses.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const BOX = '#wplit_order_licenses';
const php = ( code ) => execFileSync( 'php', [ '-d', 'memory_limit=512M', '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const save = async ( page ) => Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );

async function product( page, name, sites ) {
	const software = await createProduct( page, name );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="plans"]' );
	await page.fill( 'input[name="wplit_plans[0][name]"]', 'Plan' );
	await page.fill( 'input[name="wplit_plans[0][price]"]', '30' );
	await page.fill( 'input[name="wplit_plans[0][sites]"]', String( sites ) );
	await save( page );
	const wooId = Number( sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' ORDER BY p.ID DESC LIMIT 1" ) );
	woo( { op: 'set_product_status', product_id: wooId, status: 'publish' } );
	return { software: Number( software ), wooId };
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	const a = await product( page, 'Box Alpha', 3 );
	const b = await product( page, 'Box Beta', 1 );
	const plain = woo( { op: 'create_simple_product', name: 'Box Plain Mug' } );
	const email = 'box' + Date.now() + '@example.com';
	const customer = woo( { op: 'create_user', login: 'box' + Date.now(), email, password: 'pw-box-test-1' } );
	const open = async ( orderId ) => { await page.goto( woo( { op: 'order_info', order_id: orderId } ).edit_url ); };

	// 1. A completed order for two products lists both licenses.
	const order = woo( { op: 'create_order', product_id: a.wooId, more_product_ids: [ b.wooId ], qty: 1, email, user_id: customer.user_id, status: 'completed' } );
	await open( order.order_id );
	check( 1 === await page.locator( BOX ).count(), 'the order screen has the licenses box' );
	await shot( page, '40-order-licenses' );
	const rows = await page.locator( BOX + ' tbody tr' ).allInnerTexts();
	check( 2 === rows.length, 'it lists one row per license: ' + rows.length );
	const keys = sql( "SELECT l.license_key FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + order.order_id + "' ORDER BY l.id" ).split( '\n' );
	const text = rows.join( '\n' );
	check( keys.length === 2 && keys.every( ( key ) => text.includes( key ) ), 'with the full license keys' );
	check( /Box Alpha/.test( rows[ 0 ] ) && /Box Beta/.test( rows[ 1 ] ), 'and the product of each line' );
	check( rows.every( ( row ) => /Active/.test( row ) ), 'both are Active' );
	check( /0 of 3/.test( rows[ 0 ] ) && /0 of 1/.test( rows[ 1 ] ), 'with the sites in use and allowed: ' + rows.map( ( r ) => r.replace( /\s+/g, ' ' ) ).join( ' | ' ) );
	const href = await page.locator( BOX + ' tbody tr:first-child a' ).getAttribute( 'href' );
	const view = await page.goto( href );
	check( view.status() === 200 && ( await page.locator( 'body' ).innerText() ).includes( keys[ 0 ] ), 'the View link opens that license: ' + href );

	// 2. A cancelled order still lists its licenses, now revoked.
	woo( { op: 'set_order_status', order_id: order.order_id, status: 'cancelled' } );
	await open( order.order_id );
	const after = await page.locator( BOX + ' tbody tr' ).allInnerTexts();
	check( 2 === after.length && after.every( ( row ) => /Revoked/.test( row ) ), 'after a cancellation both are listed as Revoked: ' + after.map( ( r ) => r.replace( /\s+/g, ' ' ) ).join( ' | ' ) );

	// 3. An order that has not issued yet says so.
	const waiting = woo( { op: 'create_order', product_id: a.wooId, qty: 1, email, user_id: customer.user_id, status: 'processing' } );
	await open( waiting.order_id );
	check( 1 === await page.locator( BOX ).count() && /No license has been issued/.test( await page.locator( BOX ).innerText() ), 'a licensed order that has not issued yet says no license has been issued' );

	// 4. An order with nothing to do with licenses has no box.
	const mug = woo( { op: 'create_order', product_id: plain.product_id, qty: 1, email, user_id: customer.user_id, status: 'completed' } );
	await open( mug.order_id );
	check( 0 === await page.locator( BOX ).count() && ( await page.locator( 'body' ).innerText() ).includes( 'Box Plain Mug' ), 'an order for an ordinary product has no licenses box' );

	// 5. Someone who can see orders but not manage licenses does not see keys.
	php( 'if ( ! get_user_by( "login", "wplit_boxmgr" ) ) { wp_insert_user( array( "user_login" => "wplit_boxmgr", "user_pass" => "pw-wplit-1", "user_email" => "wplit_boxmgr@example.test", "role" => "shop_manager" ) ); }' );
	const editUrl = woo( { op: 'order_info', order_id: order.order_id } ).edit_url;
	await page.context().clearCookies();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'wplit_boxmgr' );
	await page.fill( '#user_pass', 'pw-wplit-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wp-submit' ) ] );
	await page.goto( editUrl );
	const body = await page.locator( 'body' ).innerText();
	check( /Box Alpha/.test( body ), 'a shop manager can open the order' );
	check( 0 === await page.locator( BOX ).count() && ! keys.some( ( key ) => body.includes( key ) ), 'but sees no licenses box and no keys' );

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
