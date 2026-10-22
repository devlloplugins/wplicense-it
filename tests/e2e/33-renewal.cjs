/**
 * Card 103 (the review of renewals), on real WooCommerce with a product that has two plans:
 * - Renew in My Account, the cart and a renewal invoice use the plan the license was bought with, not another plan of the
 *   product (which would renew it at that plan's price while it keeps its own sites); a link edited to the cheaper plan
 *   renews nothing;
 * - refunding a renewal order takes back only the renewed year: the license and the time paid for before stay;
 *   completing the order again renews it again;
 * - when the plan is no longer sold, a plan with the same sites takes over, and without one there is no Renew button.
 */
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

async function addPlan( page, n, name, price, sites ) {
	await page.click( '#wplit-tabs [data-tab="plans"]' );
	const f = ( field ) => 'input[name="wplit_plans[' + n + '][' + field + ']"]';
	await page.fill( f( 'name' ), name );
	await page.fill( f( 'price' ), price );
	await page.fill( f( 'sites' ), String( sites ) );
	await save( page );
}

const php = ( code ) => require( 'child_process' ).execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + require( 'path' ).join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
const expires = ( id ) => sql( 'SELECT CONCAT(status, " ", DATE(expires_at)) FROM wp_wplit_licenses WHERE id=' + id );
const plans = ( software ) => sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' AND p.post_status<>'trash' ORDER BY p.ID" ).split( '\n' ).map( Number );

async function renewHref( page ) {
	await page.goto( BASE + '/my-account/licenses/' );
	const link = page.locator( '.wplit-license a', { hasText: 'Renew' } );
	return await link.count() ? await link.first().getAttribute( 'href' ) : '';
}

async function emptyCart( page ) {
	await page.goto( BASE + '/cart/' );
	while ( await page.locator( 'a.remove' ).count() ) {
		await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'a.remove' ).first().click() ] );
		await page.waitForTimeout( 500 );
		await page.goto( BASE + '/cart/' );
	}
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	// Agency (unlimited sites, 199) first, then Single site (49): the newest plan is what Renew used to pick.
	const software = await createProduct( page, 'Renewal Plans Test' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await addPlan( page, 0, 'Agency', '199', 0 );
	await addPlan( page, 1, 'Single site', '49', 1 );
	const [ agency, single ] = plans( software );
	check( agency > 0 && single > agency, 'two plans: Agency ' + agency + ', then Single site ' + single );
	woo( { op: 'set_product_status', product_id: agency, status: 'publish' } );
	woo( { op: 'set_product_status', product_id: single, status: 'publish' } );
	// Single site is the newest product, which is the one Renew picked before card 103.
	sql( 'UPDATE wp_posts SET post_date = DATE_ADD(post_date, INTERVAL 1 HOUR), post_date_gmt = DATE_ADD(post_date_gmt, INTERVAL 1 HOUR) WHERE ID=' + single );

	const stamp = Date.now();
	const customer = woo( { op: 'create_user', login: 'renewer' + stamp, email: 'renewer' + stamp + '@example.com', password: 'pw-renew-test-1' } );
	const email = 'renewer' + stamp + '@example.com';
	const bought = woo( { op: 'create_order', product_id: agency, qty: 1, email, user_id: customer.user_id, status: 'completed' } );
	const [ licenseId, key, limit ] = sql( "SELECT l.id, l.license_key, l.activation_limit FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + bought.order_id + "'" ).split( '\t' );
	check( Number( licenseId ) > 0 && '0' === limit, 'the Agency order issued an unlimited license' );
	const before = expires( licenseId );

	// 1. Renew uses the Agency plan.
	await page.context().clearCookies();
	await page.goto( BASE + '/my-account/' );
	await page.fill( '#username', 'renewer' + stamp );
	await page.fill( '#password', 'pw-renew-test-1' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( 'button[name="login"]' ) ] );
	const href = await renewHref( page );
	check( String( agency ) === new URL( href, BASE ).searchParams.get( 'add-to-cart' ), 'Renew in My Account uses the Agency plan it was bought with, got ' + href );
	await emptyCart( page );
	await page.goto( href );
	await page.goto( BASE + '/cart/' );
	await shot( page, '61-renewal-cart' );
	let cart = await page.locator( 'body' ).innerText();
	check( /Renews license/.test( cart ) && cart.includes( key ) && /199/.test( cart ), 'the cart renews this license at the Agency price' );

	// The same link edited to the cheaper plan adds a plain purchase, not a renewal.
	await emptyCart( page );
	const edited = new URL( href, BASE );
	edited.searchParams.set( 'add-to-cart', String( single ) );
	await page.goto( edited.href );
	await page.goto( BASE + '/cart/' );
	cart = await page.locator( 'body' ).innerText();
	check( ! /Renews license/.test( cart ), 'a link edited to the Single site plan does not renew the license' );
	await emptyCart( page );

	// A renewal invoice uses the Agency plan too.
	const invoiceProduct = php( '$l = Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->find( ' + licenseId + ' ); $id = ( new Devllo\\WPLicenseIt\\WooCommerce\\WooRenewalOrderCreator( new Devllo\\WPLicenseIt\\WooCommerce\\OrderReader() ) )->create( $l ); $o = wc_get_order( $id ); $i = current( $o->get_items() ); echo $i->get_product_id(); $o->delete( true );' );
	check( String( agency ) === invoiceProduct, 'a renewal invoice is for the Agency plan, got product ' + invoiceProduct );

	// 2. A paid renewal adds a year; refunding it takes back only that year.
	const renewal = woo( { op: 'create_order', product_id: agency, qty: 1, email, user_id: customer.user_id, renew_license_id: Number( licenseId ), status: 'completed' } );
	const renewed = expires( licenseId );
	check( renewed !== before && /^active /.test( renewed ), 'the renewal extended the license: ' + before + ' -> ' + renewed );
	woo( { op: 'refund', order_id: renewal.order_id, full: true } );
	check( before === expires( licenseId ), 'refunding the renewal puts the expiry back and keeps the license active: ' + expires( licenseId ) + ', expected ' + before );
	const notes = JSON.stringify( woo( { op: 'order_notes', order_id: renewal.order_id } ) );
	check( /took back the renewal of 1 license/.test( notes ), 'the order note says the renewal was taken back' );
	check( '1' === sql( "SELECT COUNT(*) FROM wp_wplit_license_events WHERE license_id=" + licenseId + " AND type='renewal_refunded'" ), 'the license history records it' );
	await login( page );
	await page.goto( BASE + '/wp-admin/admin.php?page=wplit-licenses&license=' + licenseId );
	check( /Renewal taken back/.test( await page.locator( 'body' ).innerText() ), 'the license screen shows "Renewal taken back" in its history' );

	// Completed again while the refund is still recorded: the renewal stays taken back (as refunded licenses stay ended).
	woo( { op: 'set_order_status', order_id: renewal.order_id, status: 'completed' } );
	check( before === expires( licenseId ), 'completing it again with the refund still on the order keeps the renewal taken back: ' + expires( licenseId ) );
	check( /renewal stays taken back, because its unit is still refunded/.test( JSON.stringify( woo( { op: 'order_notes', order_id: renewal.order_id } ) ) ), 'and the order note says why' );

	// A renewal order cancelled and then paid after all: taken back, then renewed again, once.
	const second = woo( { op: 'create_order', product_id: agency, qty: 1, email, user_id: customer.user_id, renew_license_id: Number( licenseId ), status: 'completed' } );
	const again = expires( licenseId );
	woo( { op: 'set_order_status', order_id: second.order_id, status: 'cancelled' } );
	check( before === expires( licenseId ), 'cancelling a renewal order takes back its year: ' + expires( licenseId ) );
	woo( { op: 'set_order_status', order_id: second.order_id, status: 'completed' } );
	woo( { op: 'set_order_status', order_id: second.order_id, status: 'completed' } );
	check( again === expires( licenseId ), 'completing it again renews it again, once: ' + expires( licenseId ) + ', expected ' + again );

	// 3. The Agency plan is no longer sold: no Renew button, because no other plan has unlimited sites.
	woo( { op: 'set_product_status', product_id: agency, status: 'draft' } );
	await page.context().clearCookies();
	await page.goto( BASE + '/my-account/' );
	await page.fill( '#username', 'renewer' + stamp );
	await page.fill( '#password', 'pw-renew-test-1' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( 'button[name="login"]' ) ] );
	check( '' === await renewHref( page ), 'without a plan of the same sites there is no Renew button (not the Single site plan)' );

	// A new plan with unlimited sites takes over.
	await page.context().clearCookies();
	await login( page );
	await page.goto( BASE + '/wp-admin/post.php?post=' + software + '&action=edit' );
	await addPlan( page, 2, 'Agency 2026', '219', 0 );
	const agency2 = plans( software ).filter( ( id ) => id !== agency && id !== single )[ 0 ];
	woo( { op: 'set_product_status', product_id: agency2, status: 'publish' } );
	await page.context().clearCookies();
	await page.goto( BASE + '/my-account/' );
	await page.fill( '#username', 'renewer' + stamp );
	await page.fill( '#password', 'pw-renew-test-1' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( 'button[name="login"]' ) ] );
	const fallback = await renewHref( page );
	check( String( agency2 ) === new URL( fallback || '/', BASE ).searchParams.get( 'add-to-cart' ), 'a new plan with the same sites takes over, got ' + fallback );

	woo( { op: 'set_product_status', product_id: agency2, status: 'draft' } );
	woo( { op: 'set_product_status', product_id: single, status: 'draft' } );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
