/**
 * Card 100 (the purchase review): buying a license needs an account even when the store allows guest checkout, while a
 * cart without a license still checks out as a guest; and the customer's emails carry what they need: while the order
 * waits for payment, that the key follows; once complete, the key with its sites, how to activate, and the My Account link.
 * Everything goes through the real classic checkout, paid by cash on delivery.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const php = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const option = ( name ) => sql( "SELECT option_value FROM wp_options WHERE option_name='" + name + "'" );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

async function checkout( page, email ) {
	await page.goto( BASE + '/checkout/' );
	await page.waitForSelector( '#place_order', { timeout: 20000 } );
	for ( const [ field, value ] of Object.entries( { '#billing_first_name': 'Gus', '#billing_last_name': 'Guest', '#billing_address_1': '1 Main St', '#billing_city': 'Town', '#billing_postcode': '12345', '#billing_phone': '5551234', '#billing_email': email } ) ) {
		if ( await page.locator( field ).count() ) { await page.fill( field, value ); }
	}
	if ( await page.locator( '#billing_state' ).count() ) { await page.locator( '#billing_state' ).selectOption( { index: 1 } ).catch( () => {} ); }
	await page.waitForTimeout( 1500 );
	if ( await page.locator( '#payment_method_cod' ).count() ) { await page.check( '#payment_method_cod' ); }
	await Promise.all( [ page.waitForURL( /order-received/, { timeout: 30000 } ), page.click( '#place_order' ) ] );
	return Number( new URL( page.url() ).pathname.match( /order-received\/(\d+)/ )[ 1 ] );
}

( async () => {
	const logStart = logSize();
	const guestSetting = option( 'woocommerce_enable_guest_checkout' );
	const env = await start();
	const page = env.page;
	await login( page );

	// A product with a 2-site plan, on sale.
	const software = await createProduct( page, 'Checkout Account Test' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="plans"]' );
	await page.fill( 'input[name="wplit_plans[0][name]"]', 'Two sites' );
	await page.fill( 'input[name="wplit_plans[0][price]"]', '40' );
	await page.fill( 'input[name="wplit_plans[0][sites]"]', '2' );
	await save( page );
	const wooId = Number( sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' ORDER BY p.ID DESC LIMIT 1" ) );
	woo( { op: 'set_product_status', product_id: wooId, status: 'publish' } );
	woo( { op: 'enable_checkout' } );
	sql( "UPDATE wp_options SET option_value='yes' WHERE option_name='woocommerce_enable_guest_checkout'" );
	woo( { op: 'clear_mail' } );

	// 1. A guest buys the license: an account is made for them.
	const stamp = Date.now();
	const buyer = 'licensed-guest-' + stamp + '@example.com';
	const guest = await env.browser.newContext();
	const gpage = await guest.newPage();
	await gpage.goto( BASE + '/?add-to-cart=' + wooId );
	const orderId = await checkout( gpage, buyer );
	await shot( gpage, '30-received' );
	const customer = Number( php( 'echo (int) wc_get_order( ' + orderId + ' )->get_customer_id();' ) );
	check( customer > 0 && buyer === sql( 'SELECT user_email FROM wp_users WHERE ID=' + customer ), 'with guest checkout allowed, buying a license still makes an account: order customer ' + customer );

	// 2. Cash on delivery: the order waits, and the email says the key follows.
	const waiting = woo( { op: 'mail' } ).mail.filter( ( m ) => String( m.to ) === buyer );
	check( waiting.length > 0 && waiting.every( ( m ) => ! /License key/.test( m.message ) ) && waiting.some( ( m ) => /Your license key will be emailed to you once your payment is confirmed/.test( m.message ) ), 'the order email says the key follows once payment is confirmed: ' + waiting.map( ( m ) => m.subject ).join( ' | ' ) );
	check( /Your license key will be emailed to you once your payment is confirmed/.test( await gpage.locator( 'body' ).innerText() ), 'and so does the order received page' );

	// 3. Completed: the key, its sites, how to activate, and where to manage it.
	woo( { op: 'clear_mail' } );
	woo( { op: 'set_order_status', order_id: orderId, status: 'completed' } );
	const key = sql( "SELECT l.license_key FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + orderId + "'" );
	const done = woo( { op: 'mail' } ).mail.find( ( m ) => String( m.to ) === buyer && String( m.message ).includes( key ) );
	check( '' !== key && !! done, 'the completed email has the key' );
	check( !! done && /2 sites/.test( done.message ), 'with the number of sites' );
	check( !! done && /enter the license key on the plugin or theme.s license page/i.test( done.message ), 'how to activate it' );
	check( !! done && done.message.includes( BASE + '/my-account/licenses/' ), 'and a link to My Account, Licenses' );

	// 4. A cart without a license still checks out as a guest.
	const mug = woo( { op: 'create_simple_product', name: 'Checkout Mug ' + stamp } );
	php( '$p = wc_get_product( ' + mug.product_id + ' ); $p->set_virtual( true ); $p->save();' );
	const other = await env.browser.newContext();
	const opage = await other.newPage();
	await opage.goto( BASE + '/?add-to-cart=' + mug.product_id );
	const mugOrder = await checkout( opage, 'mug-guest-' + stamp + '@example.com' );
	check( 0 === Number( php( 'echo (int) wc_get_order( ' + mugOrder + ' )->get_customer_id();' ) ) && '0' === sql( "SELECT COUNT(*) FROM wp_users WHERE user_email='mug-guest-" + stamp + "@example.com'" ), 'a cart without a license is a guest order, as the store allows' );
	woo( { op: 'trash_product', product_id: mug.product_id } );

	sql( "UPDATE wp_options SET option_value='" + guestSetting + "' WHERE option_name='woocommerce_enable_guest_checkout'" );
	await guest.close();
	await other.close();
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
