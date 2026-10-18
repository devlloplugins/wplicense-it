/**
 * Renewal invoices: a few days before a license expires the plugin makes the unpaid WooCommerce order that renews it and
 * emails the customer WooCommerce's invoice with the pay link. The test turns the setting on in the admin, runs the daily job
 * through a real wp-cron.php request, checks the orders and emails, pays one as the customer (cash on delivery) and
 * completes it, and checks the license got a year and no second license was made. A guest's pay link works, nothing is made
 * twice, and an unpaid invoice is cancelled when its license is renewed by another order. Mail is kept by the test site's
 * mail capture, so nothing is sent.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const helper = ( ...args ) => JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'php/reminders.php' ), ...args ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } ).trim() );
const SETTINGS = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings';
const mails = () => woo( { op: 'mail' } ).mail;
const option = ( name ) => sql( "SELECT option_value FROM wp_options WHERE option_name='" + name + "'" );
const firstOrder = () => Number( sql( "SELECT IFNULL(MAX(ID),0) FROM wp_posts WHERE post_type IN ('shop_order','shop_order_placehold')" ) );
const save = async ( page, selector ) => Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( selector ) ] );

( async () => {
	const logStart = logSize();
	// Licenses numbers repeat from run to run, so only orders made by this run count.
	const from = firstOrder();
	const invoices = ( id ) => woo( { op: 'find_invoices', license_id: id, min_id: from } ).orders;
	const saved = { enabled: option( 'wplit_invoices_enabled' ), days: option( 'wplit_invoice_days' ), reminders: option( 'wplit_reminders_enabled' ) };
	sql( "DELETE FROM wp_options WHERE option_name IN ('wplit_invoices_enabled','wplit_invoice_days','wplit_reminders_enabled')" );

	const env = await start();
	const page = env.page;
	await login( page );

	// A product that sells a license: 40 for a year, one site.
	const software = await createProduct( page, 'Invoice Test' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page, '#publish' );
	await page.click( '#wplit-tabs [data-tab="plans"]' );
	await page.fill( 'input[name="wplit_plans[0][name]"]', 'One site' );
	await page.fill( 'input[name="wplit_plans[0][price]"]', '40' );
	await page.fill( 'input[name="wplit_plans[0][sites]"]', '1' );
	await save( page, '#publish' );
	const wooId = Number( sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' ORDER BY p.ID DESC LIMIT 1" ) );
	woo( { op: 'set_product_status', product_id: wooId, status: 'publish' } );
	woo( { op: 'enable_checkout' } );

	// Three customers, each with a license from a completed order: an account (due), a guest (due), an account far from expiry.
	const stamp = Date.now();
	const accountEmail = 'invoice-account-' + stamp + '@example.com';
	const customer = woo( { op: 'create_user', login: 'invoiced' + stamp, email: accountEmail, password: 'pw-invoiced-1' } );
	const guestEmail = 'invoice-guest-' + stamp + '@example.com';
	const farEmail = 'invoice-far-' + stamp + '@example.com';
	const licenseFor = ( email, userId ) => {
		const order = woo( { op: 'create_order', product_id: wooId, qty: 1, email, user_id: userId, status: 'completed' } );
		return Number( sql( "SELECT l.id FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + order.order_id + "'" ) );
	};
	const A = licenseFor( accountEmail, customer.user_id );
	const B = licenseFor( guestEmail, 0 );
	const C = licenseFor( farEmail, 0 );
	check( A > 0 && B > 0 && C > 0, 'three licenses exist: ' + [ A, B, C ] );
	sql( 'UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 DAY) WHERE id=' + A );
	sql( 'UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 12 DAY) WHERE id=' + B );
	sql( 'UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 DAY) WHERE id=' + C );
	const licensesBefore = sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' );

	// 1. The settings: off by default, 14 days.
	await page.goto( SETTINGS );
	const box = page.locator( 'input[name="wplit_invoices_enabled"]' );
	check( await box.count() === 1 && ! ( await box.isChecked() ), 'renewal invoices are off until the owner turns them on' );
	check( ( await page.inputValue( '#wplit_invoice_days' ) ) === '14', 'the default is 14 days, got ' + await page.inputValue( '#wplit_invoice_days' ) );
	check( /Needs WooCommerce/.test( await page.locator( 'body' ).innerText() ), 'the screen says what invoices need' );

	// 2. Off: the daily job makes no orders.
	helper( 'run' );
	check( invoices( A ).length === 0, 'with invoices off no order is made' );

	// 3. Turn on, with another number of days first (the field itself refuses more than 90).
	await page.check( 'input[name="wplit_invoices_enabled"]' );
	check( ( await page.getAttribute( '#wplit_invoice_days', 'max' ) ) === '90' && ( await page.getAttribute( '#wplit_invoice_days', 'min' ) ) === '1', 'the days field allows 1 to 90' );
	await page.fill( '#wplit_invoice_days', '21' );
	await save( page, '#submit' );
	check( ( await page.inputValue( '#wplit_invoice_days' ) ) === '21', 'another number of days is saved, got ' + await page.inputValue( '#wplit_invoice_days' ) );
	await page.fill( '#wplit_invoice_days', '14' );
	await save( page, '#submit' );
	check( await page.locator( 'input[name="wplit_invoices_enabled"]' ).isChecked() && ( await page.inputValue( '#wplit_invoice_days' ) ) === '14', 'turning it on, with 14 days, is saved' );

	// 4. The daily job, through the real cron request.
	woo( { op: 'clear_mail' } );
	helper( 'due' );
	const cron = await page.request.get( BASE + '/wp-cron.php' );
	check( cron.status() === 200, 'wp-cron.php runs, got ' + cron.status() );
	await page.waitForTimeout( 2500 );
	const a = invoices( A );
	const b = invoices( B );
	const c = invoices( C );
	check( a.length === 1 && b.length === 1, 'one invoice order for each license inside 14 days, got ' + a.length + ' and ' + b.length );
	check( c.length === 0, 'none for the license 60 days away' );
	const invoiceA = a.length ? woo( { op: 'order_info', order_id: a[ 0 ] } ) : {};
	const invoiceB = b.length ? woo( { op: 'order_info', order_id: b[ 0 ] } ) : {};
	check( invoiceA.status === 'pending' && Number( invoiceA.total ) === 40, 'the invoice is an unpaid order for 40: ' + invoiceA.status + ' ' + invoiceA.total );
	check( invoiceA.created_via === 'wplicense_renewal', 'it says it was made by the renewal invoices, got ' + invoiceA.created_via );
	check( invoiceA.lines && invoiceA.lines.length === 1 && invoiceA.lines[ 0 ].renew === A && invoiceA.lines[ 0 ].product === Number( software ), 'its one line renews license ' + A + ' of the product: ' + JSON.stringify( invoiceA.lines ) );
	check( invoiceA.lines && invoiceA.lines[ 0 ].ids.length === 0, 'and it has not issued anything yet' );
	check( invoiceA.email === accountEmail && invoiceA.user_id === customer.user_id, 'it belongs to the license owner: ' + invoiceA.email + ' / ' + invoiceA.user_id );
	check( invoiceB.email === guestEmail && invoiceB.user_id === 0, 'the guest license gets a guest order: ' + invoiceB.email + ' / ' + invoiceB.user_id );

	// 5. The emails: WooCommerce's invoice, with the pay link, to the right people.
	let sent = mails();
	const toA = sent.filter( ( m ) => String( m.to ).includes( accountEmail ) );
	const toB = sent.filter( ( m ) => String( m.to ).includes( guestEmail ) );
	check( toA.length === 1 && toB.length === 1, 'each customer got one email, ' + toA.length + ' and ' + toB.length + ' (all: ' + sent.map( ( m ) => m.to ).join( ', ' ) + ')' );
	check( ! sent.some( ( m ) => String( m.to ).includes( farEmail ) ), 'the far license got no email' );
	const linkOf = ( mail ) => ( ( mail && mail.message || '' ).match( /href="([^"]*order-pay[^"]*)"/ ) || [ '', '' ] )[ 1 ].replace( /&amp;/g, '&' );
	const payA = linkOf( toA[ 0 ] );
	const payB = linkOf( toB[ 0 ] );
	check( /order-pay\/\d+\/\?pay_for_order=true&key=wc_order_/.test( payA ), 'the email has the pay link, got ' + payA );
	check( payA.includes( '/order-pay/' + a[ 0 ] + '/' ), 'the link is for the invoice order' );
	check( payA === invoiceA.pay_url.replace( /&amp;/g, '&' ), 'and it is the order\'s own pay address' );

	// 6. History, and nothing is made twice.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses&license=' + A );
	check( /Renewal invoice sent/.test( await page.locator( 'body' ).innerText() ), 'the license history shows the invoice' );
	await shot( page, '17-history' );
	woo( { op: 'clear_mail' } );
	helper( 'run' );
	helper( 'run' );
	check( invoices( A ).length === 1 && invoices( B ).length === 1, 'running the job again makes no second invoice' );
	check( mails().length === 0, 'and sends no second email' );

	// 7. A guest can pay from the link without logging in.
	await page.context().clearCookies();
	await page.goto( payB );
	const guestPage = await page.locator( 'body' ).innerText();
	check( guestPage.includes( 'Invoice Test' ) && /Pay for order/i.test( guestPage ), 'the guest sees the order and a Pay button without logging in: ' + guestPage.replace( /\n/g, ' | ' ).slice( 0, 200 ) );
	await shot( page, '17-guest-pay' );

	// 8. The account customer logs in from the link, pays (cash on delivery), and the order is completed.
	await page.goto( payA );
	if ( await page.locator( '#username' ).count() ) {
		await page.fill( '#username', 'invoiced' + stamp );
		await page.fill( '#password', 'pw-invoiced-1' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button[name="login"]' ) ] );
	}
	await page.goto( payA );
	await page.waitForSelector( '#place_order', { timeout: 20000 } );
	await shot( page, '17-pay' );
	if ( await page.locator( '#payment_method_cod' ).count() ) { await page.check( '#payment_method_cod' ); }
	await Promise.all( [ page.waitForURL( /order-received/, { timeout: 30000 } ), page.click( '#place_order' ) ] );
	const paid = woo( { op: 'order_info', order_id: a[ 0 ] } );
	check( paid.status === 'processing', 'paying the invoice puts the order in processing, got ' + paid.status );
	check( sql( 'SELECT DATEDIFF(expires_at, UTC_TIMESTAMP()) FROM wp_wplit_licenses WHERE id=' + A ) <= '11', 'a paid but not completed order does not renew the license yet' );
	woo( { op: 'set_order_status', order_id: a[ 0 ], status: 'completed' } );
	const days = Number( sql( 'SELECT DATEDIFF(expires_at, UTC_TIMESTAMP()) FROM wp_wplit_licenses WHERE id=' + A ) );
	check( days >= 373 && days <= 376, 'completing it renews the license by a year from its old expiry, ' + days + ' days left' );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) === licensesBefore, 'no second license was made' );
	const done = woo( { op: 'order_info', order_id: a[ 0 ] } );
	check( done.lines[ 0 ].ids.includes( A ), 'the order records the renewed license: ' + JSON.stringify( done.lines[ 0 ].ids ) );

	// 9. The guest license is renewed another way (a different order): the unpaid invoice is cancelled.
	woo( { op: 'create_order', product_id: wooId, qty: 1, email: guestEmail, user_id: 0, status: 'completed', renew_license_id: B } );
	const stale = woo( { op: 'order_info', order_id: b[ 0 ] } );
	check( stale.status === 'cancelled', 'the guest\'s unpaid invoice is cancelled once the license is renewed by another order, got ' + stale.status );
	check( woo( { op: 'order_notes', order_id: b[ 0 ] } ).notes.some( ( n ) => /renewed by order/.test( n ) ), 'and the order says why' );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) === licensesBefore, 'still no second license' );
	helper( 'run' );
	check( invoices( B ).length === 1, 'the cancelled invoice is not made again for the same expiry date (and the renewal moved the date anyway)' );

	// Put everything back.
	sql( "DELETE FROM wp_options WHERE option_name IN ('wplit_invoices_enabled','wplit_invoice_days','wplit_reminders_enabled')" );
	for ( const [ name, value ] of [ [ 'wplit_invoices_enabled', saved.enabled ], [ 'wplit_invoice_days', saved.days ], [ 'wplit_reminders_enabled', saved.reminders ] ] ) {
		if ( '' !== value ) { sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" + name + "', '" + value + "', 'yes')" ); }
	}
	woo( { op: 'clear_mail' } );
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
