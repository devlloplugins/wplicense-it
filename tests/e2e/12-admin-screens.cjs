/**
 * Clicks through every admin screen of the plugin as a site owner would: the licenses list (views, filter, search, sorting,
 * paging, bulk actions), one license (renew, edit, revoke, refund, reinstate, free a site, history), Add license, Licensing
 * settings, and who may open them. Page errors, failed requests and PHP notices fail the test too (see lib.cjs).
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const run = ( ...args ) => JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'php/licenses.php' ), ...args ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } ).trim() );
const LIST = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses';
const rows = ( page ) => page.locator( 'table.wp-list-table tbody tr:not(.no-items)' );
const text = async ( page ) => ( await page.locator( 'body' ).innerText() );
// A PHP warning or notice as WordPress prints it, not the word on its own (WooCommerce's dev build prints its own notice).
const PHP_MESSAGE = /(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)[:\w ]*: [^\n]*( on line \d+|\bin \/)/;
const license = ( id ) => sql( 'SELECT CONCAT_WS("|",status,email,activation_limit,IFNULL(expires_at,"NULL")) FROM wp_wplit_licenses WHERE id=' + Number( id ) );

async function go( page, url ) {
	const response = await page.goto( url );
	return response ? response.status() : 0;
}

( async () => {
	const logStart = logSize();
	const seed = run( 'seed' );
	const { keys } = seed;
	const env = await start();
	const page = env.page;
	// Every dialog is accepted (the Revoke buttons ask), and written down, so a script that runs an alert would show.
	const dialogs = [];
	page.on( 'dialog', ( dialog ) => { dialogs.push( dialog.message() ); dialog.accept(); } );
	await login( page );

	// The menu.
	await go( page, BASE + '/wp-admin/' );
	const menu = await page.locator( '#adminmenu' ).innerText();
	check( /Products/.test( menu ) && /Licenses/.test( menu ) && /Add license/.test( menu ) && /Licensing settings/.test( menu ), 'the menu has Products, Licenses, Add license and Licensing settings: ' + menu.replace( /\n/g, ' | ' ).slice( 0, 400 ) );

	// The licenses list.
	check( 200 === await go( page, LIST ), 'the licenses list opens' );
	await shot( page, '30-licenses-list' );
	check( await rows( page ).count() === 20, 'the first page shows 20 licenses, got ' + await rows( page ).count() );
	check( /45 items/.test( await page.locator( '.tablenav.top .displaying-num' ).innerText() ), 'it says there are 45 items' );
	check( /of 3/.test( await page.locator( '.tablenav.top .tablenav-pages' ).innerText() ), 'and 3 pages' );
	const views = await page.locator( 'ul.subsubsub' ).innerText();
	check( /All\s*\(45\)/.test( views ) && /Active\s*\(36\)/.test( views ) && /Expired\s*\(3\)/.test( views ) && /Revoked\s*\(4\)/.test( views ) && /Refunded\s*\(2\)/.test( views ), 'the status links show the right counts: ' + views.replace( /\n/g, ' ' ) );
	check( await page.locator( 'th.column-license, #cb' ).count() > 0, 'the table has a checkbox column and a license column' );

	// A status view.
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'ul.subsubsub a:has-text("Revoked")' ) ] );
	check( await rows( page ).count() === 4, 'the Revoked view lists the 4 revoked licenses, got ' + await rows( page ).count() );
	check( ( await rows( page ).allInnerTexts() ).every( ( t ) => /Revoked/i.test( t ) ), 'and every row says revoked' );

	// The product filter.
	await go( page, LIST );
	await page.selectOption( 'select[name="product_id"]', String( seed.beta ) );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#filter_action' ) ] );
	check( await rows( page ).count() === 15 && ( await rows( page ).allInnerTexts() ).every( ( t ) => /Admin Test Beta/.test( t ) ), 'filtering by product shows only its 15 licenses, got ' + await rows( page ).count() + ' rows, url ' + page.url().slice( -60 ) );

	// Search.
	await go( page, LIST );
	await page.fill( '#wplit-license-search-input', keys[ 17 ].key );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#search-submit' ) ] );
	check( await rows( page ).count() === 1, 'searching for a whole key finds one license' );
	await go( page, LIST );
	await page.fill( '#wplit-license-search-input', 'cust1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#search-submit' ) ] );
	check( await rows( page ).count() === 11, 'the start of an email (cust1) finds cust1 and cust10 to cust19 (11), got ' + await rows( page ).count() );
	await go( page, LIST );
	await page.fill( '#wplit-license-search-input', '@example.com' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#search-submit' ) ] );
	check( await rows( page ).count() === 20, 'text from the middle of an email (@example.com) still finds licenses, got ' + await rows( page ).count() );
	await go( page, LIST );
	await page.fill( '#wplit-license-search-input', 'nobody-at-all' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#search-submit' ) ] );
	check( /No licenses found|no items/i.test( await text( page ) ), 'a search with no match says so' );
	check( ! PHP_MESSAGE.test( await text( page ) ), 'and shows no PHP message' );
	await go( page, LIST + '&s=' + encodeURIComponent( "o'brien\"<script>alert(1)</script>_%" ) );
	// The text must not become a script. (Not a search of the page source for "<script>": some browsers write a safely escaped
	// attribute value, such as the one in the search box, with the angle brackets unescaped.)
	const scripts = await page.locator( 'script' ).evaluateAll( ( els ) => els.filter( ( e ) => /alert\(1\)/.test( e.textContent ) ).length );
	const injected = await page.locator( 'body *' ).evaluateAll( ( els ) => els.filter( ( e ) => e.tagName === 'SCRIPT' && /alert\(1\)/.test( e.textContent ) ).length );
	const where = ( ( await page.content() ).match( /.{0,60}<script>alert.{0,40}/s ) || [ 'not in the page at all' ] )[ 0 ].replace( /\s+/g, ' ' );
	const raw = await ( await page.request.get( LIST + '&s=' + encodeURIComponent( "o'brien\"<script>alert(1)</script>_%" ) ) ).text();
	check( ! raw.includes( '<script>alert(1)' ) && ! raw.includes( '</script>_%' ), 'and the HTML the server sends has the text escaped, not as a tag' );
	check( 0 === scripts && 0 === injected && ! dialogs.some( ( m ) => '1' === m ), 'a hostile search is shown as text, not run (' + where + ')' );

	// Sorting.
	await go( page, LIST + '&orderby=email&order=asc' );
	const first = ( await rows( page ).first().innerText() );
	check( /cust1@example\.com/.test( first ), 'sorting by email puts cust1@example.com first: ' + first.replace( /\n|\t/g, ' ' ).slice( 0, 90 ) );
	await go( page, LIST + '&orderby=expires_at&order=desc' );
	check( await rows( page ).count() === 20, 'sorting by expiry works' );
	await go( page, LIST );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'th a:has-text("Customer")' ) ] );
	check( /orderby=email/.test( page.url() ), 'clicking the Customer heading sorts by email' );

	// Paging.
	await go( page, LIST );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '.tablenav.top a.next-page' ) ] );
	check( /paged=2/.test( page.url() ) && await rows( page ).count() === 20, 'the next page link goes to page 2 with 20 rows' );
	await go( page, LIST + '&paged=3' );
	check( await rows( page ).count() === 5, 'page 3 has the last 5' );
	await go( page, LIST + '&paged=99' );
	check( ! PHP_MESSAGE.test( await text( page ) ), 'a page far past the end does not break' );

	// Bulk actions: revoke two, then reinstate them.
	await go( page, LIST + '&orderby=id&order=asc' );
	const ids = [ keys[ 1 ].id, keys[ 2 ].id ];
	for ( const id of ids ) { await page.check( 'input[name="license[]"][value="' + id + '"], input[type="checkbox"][value="' + id + '"]' ); }
	await page.selectOption( '#bulk-action-selector-top', 'revoke' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#doaction' ) ] );
	check( ids.every( ( id ) => /^revoked/.test( license( id ) ) ), 'bulk Revoke ends both selected licenses: ' + ids.map( license ).join( ' ; ' ) );
	check( /2 licenses updated/.test( await text( page ) ), 'and says 2 licenses were updated' );
	await go( page, LIST + '&status=revoked' );
	for ( const id of ids ) { await page.check( 'input[type="checkbox"][value="' + id + '"]' ); }
	await page.selectOption( '#bulk-action-selector-top', 'reinstate' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#doaction' ) ] );
	check( ids.every( ( id ) => /^active/.test( license( id ) ) ), 'bulk Reinstate brings them back' );

	// Applying a bulk action with nothing chosen does nothing and does not break.
	await go( page, LIST );
	await page.selectOption( '#bulk-action-selector-top', 'revoke' );
	await page.click( '#doaction' );
	await page.waitForTimeout( 1500 );
	check( ! PHP_MESSAGE.test( await text( page ) ) && sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE status="revoked"' ) === '4' && ! /wplit_notice/.test( page.url() ), 'Apply with nothing ticked does nothing (WordPress stops it in the browser)' );
	// Sent by hand, which skips that browser check, the server must still cope.
	const handmade = await page.request.post( LIST, { form: { action: 'revoke', _wpnonce: await page.locator( '#_wpnonce' ).first().getAttribute( 'value' ) } } );
	check( handmade.status() < 400 && sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE status="revoked"' ) === '4', 'and the server copes with it when sent by hand (' + handmade.status() + ')' );

	// One license.
	// A license that never expires has nothing to renew.
	await go( page, LIST + '&license=' + keys[ 20 ].id );
	check( 0 === await page.locator( '#wplit-renew-period' ).count() && /Never/.test( await text( page ) ), 'a license that never expires offers no Renew' );
	const L = keys[ 21 ];
	check( 200 === await go( page, LIST + '&license=' + L.id ), 'a license opens' );
	await shot( page, '31-license' );
	let body = await text( page );
	check( body.includes( L.key ) && /Active/.test( body ) && /Admin Test Alpha/.test( body ) && /cust21@example\.com/.test( body ), 'it shows the key, status, product and customer' );
	check( /0 \/ 3|0 \/ 1|0 \/ 2/.test( body ), 'and how many sites are in use' );
	check( /No history yet|Issued/.test( body ), 'and its history' );

	// Renew.
	const before = new Date( sql( 'SELECT expires_at FROM wp_wplit_licenses WHERE id=' + L.id ).replace( ' ', 'T' ) + 'Z' );
	await page.selectOption( '#wplit-renew-period', { index: 0 } );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button:has-text("Renew")' ) ] );
	const after = new Date( sql( 'SELECT expires_at FROM wp_wplit_licenses WHERE id=' + L.id ).replace( ' ', 'T' ) + 'Z' );
	check( after > before, 'Renew pushes the expiry later: ' + before.toISOString().slice( 0, 10 ) + ' became ' + after.toISOString().slice( 0, 10 ) );
	check( /renewed/i.test( await text( page ) ), 'and says it was renewed' );

	// Edit.
	await page.fill( '#wplit-email', 'Changed.Customer@Example.COM' );
	await page.fill( '#wplit-limit', '7' );
	await page.check( 'input[name="expiry_mode"][value="date"]' );
	await page.fill( 'input[name="expiry_date"]', '2031-05-17' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'input[type="submit"][value="Save changes"], button:has-text("Save changes")' ) ] );
	check( /^active\|changed\.customer@example\.com\|7\|2031-05-17/.test( license( L.id ) ), 'Edit saves a lower-cased email, 7 sites and the date: ' + license( L.id ) );
	check( /updated/i.test( await text( page ) ), 'and says it was updated' );
	await page.check( 'input[name="expiry_mode"][value="lifetime"]' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'input[type="submit"][value="Save changes"], button:has-text("Save changes")' ) ] );
	check( /\|NULL$/.test( license( L.id ) ), 'switching to Never expires clears the date' );
	await page.fill( '#wplit-email', 'not-an-email' );
	await page.evaluate( () => { document.querySelector( '#wplit-email' ).type = 'text'; } );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'input[type="submit"][value="Save changes"], button:has-text("Save changes")' ) ] );
	check( /email/i.test( await page.locator( '.notice-error' ).innerText().catch( () => '' ) ) && /^active\|changed\.customer/.test( license( L.id ) ), 'an invalid email is refused with a message and nothing changes' );
	await page.fill( '#wplit-email', 'changed.customer@example.com' );
	await page.fill( '#wplit-limit', '-3' );
	await page.evaluate( () => { document.querySelector( '#wplit-limit' ).removeAttribute( 'min' ); } );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'input[type="submit"][value="Save changes"], button:has-text("Save changes")' ) ] );
	check( /\|7\|/.test( license( L.id ) ), 'a negative number of sites is refused: ' + license( L.id ) );

	// Revoke, reinstate, refund.
	await go( page, LIST + '&license=' + L.id );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'a:has-text("Revoke")' ) ] );
	check( /^revoked/.test( license( L.id ) ), 'Revoke ends the license' );
	check( /Revoked/.test( await text( page ) ) && ! await page.locator( 'a:has-text("Revoke")' ).count(), 'and the screen shows it, without a second Revoke button' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'a:has-text("Reinstate")' ) ] );
	check( /^active/.test( license( L.id ) ), 'Reinstate makes it active again' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'a:has-text("Refund")' ) ] );
	check( /^refunded/.test( license( L.id ) ), 'Mark as refunded ends it as refunded' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'a:has-text("Reinstate")' ) ] );
	body = await text( page );
	check( /Issued/.test( body ) && /Revoked/.test( body ) && /Reinstated/.test( body ) && /Refunded/.test( body ) && /Renewed/.test( body ) && /Edited/.test( body ), 'the history lists everything that was done' );

	// Free a site.
	const site = 'https://shop.customer-example.com';
	run( 'activate', L.key, site );
	await go( page, LIST + '&license=' + L.id );
	check( new RegExp( 'shop\\.customer-example\\.com' ).test( await text( page ) ), 'an active site is listed' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button:has-text("Deactivate")' ) ] );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_activations WHERE license_id=' + L.id + ' AND status="active"' ) === '0', 'Deactivate frees the site' );
	check( /Site deactivated/.test( await text( page ) ), 'and it appears in the history' );

	// A license that does not exist, and an action without its link.
	await go( page, LIST + '&license=99999999' );
	check( ! PHP_MESSAGE.test( await text( page ) ), 'a license that does not exist does not break the page: ' + ( await text( page ) ).replace( /\n/g, ' ' ).slice( 0, 160 ) );
	const noNonce = await page.request.get( BASE + '/wp-admin/admin-post.php?action=wplit_license_action&do=revoke&license=' + L.id, { maxRedirects: 0 } );
	check( /^active/.test( license( L.id ) ), 'an action link without its security token does nothing (' + noNonce.status() + ')' );

	// Add license.
	check( 200 === await go( page, BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-add-license' ), 'Add license opens' );
	await shot( page, '32-add-license' );
	check( await page.locator( '#wplit-product option' ).count() >= 2, 'it offers the products' );
	woo( { op: 'clear_mail' } );
	await page.selectOption( '#wplit-product', String( seed.beta ) );
	await page.fill( '#wplit-email', 'New.Buyer@Example.com' );
	await page.fill( '#wplit-user', 'admin' );
	await page.fill( '#wplit-limit', '4' );
	await page.check( 'input[name="expiry_mode"][value="period"]' );
	await page.check( 'input[name="send_email"]' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'form button[type="submit"], form input[type="submit"]' ).last().click() ] );
	const added = sql( 'SELECT CONCAT_WS("|",id,email,activation_limit,status,user_id,IFNULL(expires_at,"NULL")) FROM wp_wplit_licenses ORDER BY id DESC LIMIT 1' ).split( '|' );
	check( added[ 1 ] === 'new.buyer@example.com' && added[ 2 ] === '4' && added[ 3 ] === 'active' && added[ 4 ] !== '' && added[ 5 ] !== 'NULL', 'a license is added for the customer, linked to the WordPress user, with an expiry: ' + added.join( '|' ) );
	check( /license=\d+/.test( page.url() ) && /created|issued|added/i.test( await text( page ) ), 'and the screen shows it with a message' );
	const mail = woo( { op: 'mail' } ).mail;
	check( mail.some( ( m ) => String( m.to ).toLowerCase().includes( 'new.buyer@example.com' ) && String( m.message ).includes( sql( 'SELECT license_key FROM wp_wplit_licenses WHERE id=' + added[ 0 ] ) ) ), 'the customer is emailed the key' );

	// Add license, with bad input.
	for ( const [ label, email, user ] of [ [ 'no email', '', '' ], [ 'a user that does not exist', 'x@example.com', 'no-such-user-xyz' ] ] ) {
		await go( page, BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-add-license' );
		await page.selectOption( '#wplit-product', String( seed.alpha ) );
		await page.evaluate( () => { document.querySelector( '#wplit-email' ).removeAttribute( 'required' ); } );
		await page.fill( '#wplit-email', email );
		await page.fill( '#wplit-user', user );
		const count = sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'form button[type="submit"], form input[type="submit"]' ).last().click() ] );
		check( sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) === count && await page.locator( '.notice-error' ).count() > 0, 'Add license with ' + label + ' is refused with a message: ' + ( await page.locator( '.notice-error' ).first().innerText().catch( () => 'no message' ) ).slice( 0, 90 ) );
	}

	// Licensing settings.
	check( 200 === await go( page, BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings' ), 'Licensing settings opens' );
	await shot( page, '33-settings' );
	const fields = await page.locator( 'input[name^="wplit"], select[name^="wplit"]' ).evaluateAll( ( els ) => els.map( ( e ) => e.name ) );
	check( fields.length >= 4, 'the settings have their fields: ' + fields.join( ', ' ) );
	const numeric = await page.locator( 'input[type="number"]' ).evaluateAll( ( els ) => els.map( ( e ) => ( { name: e.name, max: e.max } ) ) );
	for ( const field of numeric ) {
		await page.evaluate( ( name ) => { const e = document.querySelector( 'input[name="' + name + '"]' ); e.removeAttribute( 'max' ); e.removeAttribute( 'min' ); }, field.name );
		await page.fill( 'input[name="' + field.name + '"]', '987654' );
	}
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit, input[type="submit"][value="Save Changes"]' ) ] );
	for ( const field of numeric ) {
		const stored = Number( sql( 'SELECT option_value FROM wp_options WHERE option_name="' + field.name + '"' ) || 0 );
		check( ! field.max || stored <= Number( field.max ), field.name + ' is limited to its maximum (' + field.max + ') even when the form is bypassed, stored ' + stored );
	}
	for ( const field of numeric ) { await page.fill( 'input[name="' + field.name + '"]', field.max ? String( Math.min( 5, Number( field.max ) ) ) : '5' ).catch( () => {} ); }
	await page.uncheck( 'input[name="wplit_keep_legacy_billing"]' ).catch( () => {} );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit, input[type="submit"][value="Save Changes"]' ) ] );
	await go( page, BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings' );
	check( ( await page.locator( 'input[name="wplit_keep_legacy_billing"]' ).isChecked().catch( () => false ) ) === false, 'a setting that was turned off stays off after saving' );
	await page.check( 'input[name="wplit_keep_legacy_billing"]' ).catch( () => {} );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit, input[type="submit"][value="Save Changes"]' ) ] );

	// The products list and the product screen.
	check( 200 === await go( page, BASE + '/wp-admin/edit.php?post_type=wplit_product' ), 'the products list opens' );
	check( /Admin Test Alpha/.test( await text( page ) ), 'and lists the products' );
	check( 200 === await go( page, BASE + '/wp-admin/post.php?post=' + seed.alpha + '&action=edit' ), 'a product opens' );
	for ( const tab of [ 'software', 'plans', 'releases', 'connect' ] ) {
		await page.click( '#wplit-tabs [data-tab="' + tab + '"]' );
		check( await page.locator( '#wplit-tabs [data-tab="' + tab + '"]' ).getAttribute( 'aria-selected' ).catch( () => 'true' ) !== 'false', 'the ' + tab + ' tab opens' );
	}

	// Who may open these screens.
	await page.context().clearCookies();
	execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + ROOT + '/wordpress/wp-load.php"; foreach ( array( "wplit_editor" => "editor", "wplit_sub" => "subscriber" ) as $login => $role ) { if ( ! get_user_by( "login", $login ) ) { wp_insert_user( array( "user_login" => $login, "user_pass" => "pw-wplit-1", "user_email" => $login . "@example.test", "role" => $role ) ); } }' ] );
	for ( const user of [ 'wplit_editor', 'wplit_sub' ] ) {
		await page.context().clearCookies();
		await page.goto( BASE + '/wp-login.php' );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', 'pw-wplit-1' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wp-submit' ) ] );
		const status = await go( page, LIST );
		check( 403 === status || /not allowed|permission/i.test( await text( page ) ), 'the ' + user + ' cannot open the licenses list (' + status + ')' );
		const status2 = await go( page, BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings' );
		check( 403 === status2 || /not allowed|permission/i.test( await text( page ) ), 'nor the settings (' + status2 + ')' );
		const post = await page.request.post( BASE + '/wp-admin/admin-post.php', { form: { action: 'wplit_license_action', do: 'revoke', license: String( L.id ) }, maxRedirects: 0 } );
		check( /^active/.test( license( L.id ) ), 'nor revoke a license by sending the request by hand (' + user + ', ' + post.status() + ')' );
	}

	await finish( env, logStart );
} )();
