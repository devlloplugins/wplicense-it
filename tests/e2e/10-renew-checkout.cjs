/**
 * The Renew button in My Account, clicked through the real cart and checkout: the license gets another year when the
 * order is completed, and no new license is made. Also: a Renew link opened by another customer does nothing.
 */
const fixtures = require( './fixtures.cjs' );
const { BASE, check, createProduct, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	const software = await createProduct( page, 'Renew Test' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="plans"]' );
	await page.fill( 'input[name="wplit_plans[0][name]"]', 'One site' );
	await page.fill( 'input[name="wplit_plans[0][price]"]', '40' );
	await page.fill( 'input[name="wplit_plans[0][sites]"]', '1' );
	await save( page );
	const wooId = Number( sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' ORDER BY p.ID DESC LIMIT 1" ) );
	woo( { op: 'set_product_status', product_id: wooId, status: 'publish' } );
	woo( { op: 'enable_checkout' } );

	const stamp = Date.now();
	const customer = woo( { op: 'create_user', login: 'renewer' + stamp, email: 'renewer' + stamp + '@example.com', password: 'pw-renewer-1' } );
	const email = 'renewer' + stamp + '@example.com';
	const order = woo( { op: 'create_order', product_id: wooId, qty: 1, email, user_id: customer.user_id, status: 'completed' } );
	const license = sql( "SELECT l.id FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + order.order_id + "'" );
	check( Number( license ) > 0, 'the customer has a license' );
	sql( 'UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(NOW(), INTERVAL 10 DAY) WHERE id=' + license );

	// Log in as the customer and click Renew.
	await page.context().clearCookies();
	await page.goto( BASE + '/my-account/' );
	await page.fill( '#username', 'renewer' + stamp );
	await page.fill( '#password', 'pw-renewer-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button[name="login"]' ) ] );
	await page.goto( BASE + '/my-account/licenses/' );
	const renew = page.locator( 'a.button:has-text("Renew")' ).first();
	check( await renew.count() > 0, 'My Account offers a Renew button for a license that ends soon' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), renew.click() ] );
	await shot( page, '10-renew-cart' );
	const cart = await page.locator( 'body' ).innerText();
	check( /Renews license/i.test( cart ), 'the cart says which license it renews: ' + cart.replace( /\n/g, ' | ' ).slice( 0, 250 ) );

	// Check out with cash on delivery.
	await page.goto( BASE + '/checkout/' );
	await page.waitForSelector( '#place_order', { timeout: 20000 } );
	for ( const [ field, value ] of Object.entries( { '#billing_first_name': 'Re', '#billing_last_name': 'Newer', '#billing_address_1': '1 Main St', '#billing_city': 'Town', '#billing_postcode': '12345', '#billing_phone': '5551234' } ) ) {
		if ( await page.locator( field ).count() ) { await page.fill( field, value ); }
	}
	if ( await page.locator( '#billing_state' ).count() ) { await page.locator( '#billing_state' ).selectOption( { index: 1 } ).catch( () => {} ); }
	await page.waitForTimeout( 1500 );
	if ( await page.locator( '#payment_method_cod' ).count() ) { await page.check( '#payment_method_cod' ); }
	await Promise.all( [ page.waitForURL( /order-received/, { timeout: 30000 } ), page.click( '#place_order' ) ] );
	await shot( page, '10-renew-received' );
	const orderId = Number( sql( "SELECT MAX(ID) FROM wp_posts WHERE post_type IN ('shop_order','shop_order_placehold')" ) );
	check( orderId > order.order_id, 'the checkout made a new order (' + orderId + ')' );

	// Not paid yet: nothing changes until the shop owner completes the order.
	const beforeDays = Number( sql( 'SELECT DATEDIFF(expires_at, NOW()) FROM wp_wplit_licenses WHERE id=' + license ) );
	check( beforeDays <= 11, 'a renewal order that is not completed does not extend the license yet (' + beforeDays + ' days)' );
	woo( { op: 'set_order_status', order_id: orderId, status: 'completed' } );
	const after = Number( sql( 'SELECT DATEDIFF(expires_at, NOW()) FROM wp_wplit_licenses WHERE id=' + license ) );
	check( after >= 373 && after <= 376, 'completing the renewal order extends the license by a year from its expiry, got ' + after + ' days' );
	check( sql( "SELECT COUNT(*) FROM wp_wplit_licenses WHERE email='" + email + "'" ) === '1', 'a renewal makes no new license' );
	await page.goto( BASE + '/my-account/licenses/' );
	await shot( page, '10-renew-account' );

	// Another customer opening the same Renew link gets an ordinary purchase, not a renewal.
	const renewHref = await page.locator( 'a.button:has-text("Renew")' ).first().getAttribute( 'href' );
	const other = woo( { op: 'create_user', login: 'other' + stamp, email: 'other' + stamp + '@example.com', password: 'pw-other-1' } );
	await page.context().clearCookies();
	await page.goto( BASE + '/my-account/' );
	await page.fill( '#username', 'other' + stamp );
	await page.fill( '#password', 'pw-other-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button[name="login"]' ) ] );
	await page.goto( renewHref );
	const otherCart = await page.locator( 'body' ).innerText();
	check( other.user_id > 0 && ! /Renews license/i.test( otherCart ), 'opening someone else\'s Renew link does not turn the cart into a renewal: ' + otherCart.replace( /\n/g, ' | ' ).slice( 0, 200 ) );

	await finish( env, logStart );
} )();
