/**
 * A paid order that could not be given its license: the problem is shown where an administrator will see it (the order's own
 * screen, with a Try again button, and the Overview screen), the site's administrator is emailed once, and pressing Try again
 * after the cause is fixed issues the license and clears the problem. The cause here is a product whose license period is
 * nonsense, which WooCommerce itself does not mind.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const OVERVIEW = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-overview';
const php = ( code ) => execFileSync( 'php', [ '-d', 'memory_limit=512M', '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();
// WooCommerce emails the administrator about a new order too; only this plugin's email counts here.
const problemMails = ( adminEmail ) => woo( { op: 'mail' } ).mail.filter( ( m ) => String( m.to ).includes( adminEmail ) && /license could not be issued/.test( m.subject || '' ) );
const save = async ( page, selector ) => Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( selector ) ] );

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	// A product that sells a license.
	const software = await createProduct( page, 'Problem Product' );
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
	const adminEmail = sql( "SELECT option_value FROM wp_options WHERE option_name='admin_email'" );
	const licensesOf = ( orderId ) => Number( sql( "SELECT COUNT(*) FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + orderId + "'" ) );

	// 1. The product's license period is broken, and an order is paid.
	woo( { op: 'set_product_meta', product_id: wooId, key: '_wplit_period', value: 'every other tuesday' } );
	woo( { op: 'clear_mail' } );
	const stamp = Date.now();
	const email = 'problem-' + stamp + '@example.com';
	const order = woo( { op: 'create_order', product_id: wooId, qty: 1, email, user_id: 0, status: 'completed' } );
	const info = woo( { op: 'order_info', order_id: order.order_id } );
	check( licensesOf( order.order_id ) === 0, 'no license was made for the order' );
	check( /No license was issued/.test( info.problem ), 'the order remembers what went wrong: ' + info.problem );
	check( woo( { op: 'order_notes', order_id: order.order_id } ).notes.some( ( n ) => /No license was issued/.test( n ) ), 'the order note says so too' );

	// 2. The administrator is emailed, once, with the order's address.
	const sent = problemMails( adminEmail );
	check( sent.length === 1, 'the site administrator got one email, got ' + sent.length );
	check( /was paid but its license could not be issued/.test( sent[ 0 ] ? sent[ 0 ].subject : '' ) && String( sent[ 0 ] ? sent[ 0 ].subject : '' ).includes( info.number ), 'its subject names the order: ' + ( sent[ 0 ] && sent[ 0 ].subject ) );
	check( sent[ 0 ] && sent[ 0 ].message.includes( 'No license was issued' ) && sent[ 0 ].message.includes( 'Try again' ), 'it says what went wrong and what to press' );
	const editLink = ( sent[ 0 ] ? sent[ 0 ].message : '' ).match( /https?:\/\/\S+/ );
	check( !! editLink && editLink[ 0 ].replace( /&#038;|&amp;/g, '&' ) === info.edit_url.replace( /&#038;|&amp;/g, '&' ), 'and links to the order: ' + ( editLink && editLink[ 0 ] ) );

	// 3. The order's own screen shows it, with a Try again button.
	await page.goto( info.edit_url.replace( /&#038;|&amp;/g, '&' ) );
	const notice = page.locator( '.notice-error', { hasText: 'could not issue the license for this order' } );
	check( await notice.count() === 1, 'the order screen shows an error notice' );
	check( /No license was issued/.test( await notice.innerText() ) && /customer has not received it/.test( await notice.innerText() ), 'it says what went wrong and that the customer has no license' );
	check( await notice.getAttribute( 'role' ) === 'alert', 'and it is announced as an alert' );
	const retry = notice.locator( 'a.button', { hasText: 'Try again' } );
	check( await retry.count() === 1, 'with a Try again button' );
	await shot( page, '19-order' );

	// 4. The Overview lists it.
	await page.goto( OVERVIEW );
	const problem = page.locator( '.notice-error', { hasText: 'could not be given' } );
	check( await problem.count() === 1, 'the Overview has a problem for paid orders without a license' );
	check( await problem.locator( 'a', { hasText: 'Order ' + info.number } ).count() === 1, 'it lists the order with a link' );
	await shot( page, '19-overview' );

	// 5. Try again while it is still broken: the problem stays, and nobody is emailed again.
	await page.goto( info.edit_url.replace( /&#038;|&amp;/g, '&' ) );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( '.notice-error a.button', { hasText: 'Try again' } ).click() ] );
	check( await page.locator( '.notice-error', { hasText: 'could not issue the license' } ).count() === 1, 'trying again while it is broken shows the problem again' );
	check( licensesOf( order.order_id ) === 0, 'and still no license' );
	check( problemMails( adminEmail ).length === 1, 'and no second email' );

	// 6. Fix the cause, try again: the license is issued and the problem is gone.
	woo( { op: 'set_product_meta', product_id: wooId, key: '_wplit_period', value: 'P1Y' } );
	// The order line took the broken value when the order was made, so the line is fixed too (a shop owner would edit the product,
	// and the order line keeps its own copy of the terms only when it was snapshotted at checkout).
	php( 'foreach ( wc_get_order( ' + order.order_id + ' )->get_items() as $item ) { $item->delete_meta_data( "_wplit_period" ); $item->save(); }' );
	await page.goto( info.edit_url.replace( /&#038;|&amp;/g, '&' ) );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( '.notice-error a.button', { hasText: 'Try again' } ).click() ] );
	check( await page.locator( '.notice-success', { hasText: 'The license was issued' } ).count() === 1, 'after the fix, Try again says the license was issued' );
	check( await page.locator( '.notice-error', { hasText: 'could not issue the license' } ).count() === 0, 'and the error is gone' );
	check( licensesOf( order.order_id ) === 1, 'the license exists, for the customer' );
	const after = woo( { op: 'order_info', order_id: order.order_id } );
	check( after.problem === '', 'the order no longer carries the problem' );
	check( woo( { op: 'order_notes', order_id: order.order_id } ).notes.some( ( n ) => /issued 1 license/.test( n ) ), 'the order note says one license was issued' );
	await page.goto( OVERVIEW );
	check( await page.locator( '.notice-error', { hasText: 'Order ' + info.number } ).count() === 0 && ! ( await page.locator( 'body' ).innerText() ).includes( 'Order ' + info.number + ':' ), 'the Overview no longer lists it' );

	// 7. Someone who cannot manage licenses cannot press the button by hand.
	php( 'if ( ! get_user_by( "login", "wplit_probsub" ) ) { wp_insert_user( array( "user_login" => "wplit_probsub", "user_pass" => "pw-wplit-1", "user_email" => "wplit_probsub@example.test", "role" => "subscriber" ) ); }' );
	await page.context().clearCookies();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'wplit_probsub' );
	await page.fill( '#user_pass', 'pw-wplit-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wp-submit' ) ] );
	const denied = await page.goto( BASE + '/wp-admin/admin-post.php?action=wplit_retry_order&order_id=' + order.order_id );
	check( denied.status() === 403 || /not allowed/i.test( await page.locator( 'body' ).innerText() ), 'a subscriber cannot use the retry address (' + denied.status() + ')' );

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
