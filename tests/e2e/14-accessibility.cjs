/**
 * Accessibility of the plugin's admin screens: an automated scan of each screen and each product tab (axe-core, WCAG 2.1 A and
 * AA and its best practices), and what a scan cannot judge: that the controls can be reached and used with the keyboard alone,
 * that the keyboard focus can be seen, that screens have one main heading, and that messages announce themselves.
 * Only the plugin's own markup is scanned, not WordPress's or WooCommerce's.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const axeSource = fs.readFileSync( require.resolve( 'axe-core/axe.min.js' ), 'utf8' );
const seed = JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'php/licenses.php' ), 'seed' ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } ).trim() );
const ADMIN = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=';
const onServer = ( code ) => execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( ROOT, 'wordpress/wp-load.php' ) + '"; ' + code ], { encoding: 'utf8' } ).trim();

/** Runs axe on the parts of the page that match the selectors and returns the problems, one line each. */
async function scan( page, include, exclude = [] ) {
	if ( ! await page.evaluate( () => !! window.axe ) ) { await page.addScriptTag( { content: axeSource } ); }
	return page.evaluate( async ( [ inc, exc ] ) => {
		const context = { include: inc.map( ( s ) => [ s ] ) };
		if ( exc.length ) { context.exclude = exc.map( ( s ) => [ s ] ); }
		const result = await window.axe.run( context, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice' ] } } );
		return result.violations.map( ( v ) => '[' + v.impact + '] ' + v.id + ' x' + v.nodes.length + ' ' + v.nodes[ 0 ].target.join( ' ' ) + ' (' + v.help + ')' );
	}, [ include, exclude ] );
}

/** Tabs through the page until the element is focused. Returns the number of presses, or -1. */
async function tabTo( page, selector, limit = 120 ) {
	for ( let i = 1; i <= limit; i++ ) {
		await page.keyboard.press( 'Tab' );
		if ( await page.evaluate( ( s ) => !! document.activeElement && document.activeElement.matches( s ), selector ) ) { return i; }
	}
	return -1;
}

/** Whether the focused element shows where the focus is: an outline or a ring made with a shadow. */
async function focusVisible( page ) {
	return page.evaluate( () => {
		const e = document.activeElement;
		const c = getComputedStyle( e );
		return ( 'none' !== c.outlineStyle && parseFloat( c.outlineWidth ) > 0 ) || ( 'none' !== c.boxShadow && '' !== c.boxShadow );
	} );
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	const dialogs = [];
	page.on( 'dialog', ( dialog ) => { dialogs.push( dialog.message() ); dialog.accept(); } );
	await login( page );

	// An automated scan of every screen.
	const screens = {
		'the overview': ADMIN + 'wplit-overview',
		'the licenses list': ADMIN + 'wplit-licenses',
		'the licenses list with a search': ADMIN + 'wplit-licenses&s=cust1',
		'a license': ADMIN + 'wplit-licenses&license=' + seed.keys[ 21 ].id,
		'a revoked license': ADMIN + 'wplit-licenses&license=' + seed.keys[ 3 ].id,
		'Add license': ADMIN + 'wplit-add-license',
		'Licensing settings': ADMIN + 'wplit-licensing-settings',
		'the products list': BASE + '/wp-admin/edit.php?post_type=wplit_product',
	};
	for ( const [ name, url ] of Object.entries( screens ) ) {
		await page.goto( url );
		const problems = await scan( page, [ '.wrap' ], [ '.notice', '#wpfooter', '.column-author' ] );
		check( 0 === problems.length, name + ' has no accessibility problems' + ( problems.length ? ': ' + problems.join( ' | ' ) : '' ) );
		const headings = await page.locator( '.wrap h1' ).count();
		check( 1 === headings, name + ' has one main heading, found ' + headings );
		check( ( await page.title() ).trim().length > 3, name + ' has a page title: ' + ( await page.title() ).slice( 0, 60 ) );
	}

	// Every tab of a product.
	await page.goto( BASE + '/wp-admin/post.php?post=' + seed.alpha + '&action=edit' );
	for ( const tab of [ 'software', 'plans', 'releases', 'connect' ] ) {
		await page.click( '#wplit-tabs [data-tab="' + tab + '"]' );
		const problems = await scan( page, [ '#wplit-tabs', '#normal-sortables' ], [] );
		check( 0 === problems.length, 'the ' + tab + ' tab of a product has no accessibility problems' + ( problems.length ? ': ' + problems.join( ' | ' ) : '' ) );
	}

	// The messages the plugin shows after an action, and the migration notice with its buttons.
	await page.goto( ADMIN + 'wplit-licenses&license=' + seed.keys[ 21 ].id );
	await page.click( 'a:has-text("Revoke")' );
	await page.waitForLoadState( 'load' );
	const status = page.locator( '.notice-success[role="status"]' );
	check( await status.count() === 1, 'a success message is announced to screen readers (role=status)' );
	check( 0 === ( await scan( page, [ '.notice-success[role="status"]' ] ) ).length, 'and passes the scan' );
	await page.goto( ADMIN + 'wplit-licenses&license=' + seed.keys[ 21 ].id + '&wplit_error=invalid_email' );
	check( await page.locator( '.notice-error[role="alert"]' ).count() === 1, 'an error message is announced (role=alert)' );
	check( 0 === ( await scan( page, [ '.notice-error[role="alert"]' ] ) ).length, 'and passes the scan' );
	onServer( 'update_option( "wplit_migration_state", array_merge( ( new Devllo\\WPLicenseIt\\Migration\\MigrationState() )->to_array(), array( "status" => "needs_attention", "licenses_migrated" => 5, "licenses_skipped" => 2, "problems" => array( "Verify: 1.x has 7 licenses but 5 were migrated.", "License 3: it has no product." ) ) ) );' );
	await page.goto( BASE + '/wp-admin/' );
	await page.evaluate( () => { const n = document.querySelector( '.notice:has(input[value="wplit_run_migration"])' ); if ( n ) { n.id = 'wplit-axe-target'; } } );
	check( await page.locator( '#wplit-axe-target' ).count() === 1, 'the migration notice is shown' );
	const noticeProblems = await scan( page, [ '#wplit-axe-target' ] );
	check( 0 === noticeProblems.length, 'and has no accessibility problems' + ( noticeProblems.length ? ': ' + noticeProblems.join( ' | ' ) : '' ) );
	onServer( 'update_option( "wplit_migration_state", array_merge( ( new Devllo\\WPLicenseIt\\Migration\\MigrationState() )->to_array(), array( "status" => "done", "finalized" => true ) ) );' );

	// The keyboard alone. Start from the top of the licenses list.
	await page.goto( ADMIN + 'wplit-licenses' );
	const unreachable = await page.locator( '.wrap a[href], .wrap button, .wrap input:not([type=hidden]), .wrap select' ).evaluateAll( ( els ) => els.filter( ( e ) => e.offsetParent !== null && ! e.disabled && e.tabIndex < 0 ).map( ( e ) => e.tagName + ':' + ( e.name || e.textContent.trim().slice( 0, 20 ) ) ) );
	check( 0 === unreachable.length, 'every visible control on the list can be reached with the Tab key' + ( unreachable.length ? ': ' + unreachable.join( ', ' ) : '' ) );

	check( await tabTo( page, '#wplit-license-search-input' ) > 0, 'Tab reaches the search box' );
	check( await focusVisible( page ), 'and you can see it has the focus' );
	await page.keyboard.type( 'cust33' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.keyboard.press( 'Enter' ) ] );
	check( /s=cust33/.test( page.url() ) && await page.locator( 'table.wp-list-table tbody tr:not(.no-items)' ).count() === 1, 'typing a search and pressing Enter finds the license' );

	await page.goto( ADMIN + 'wplit-licenses' );
	check( await tabTo( page, '#wplit-filter-product' ) > 0, 'Tab reaches the product filter' );
	await page.keyboard.press( 'ArrowDown' );
	await page.keyboard.press( 'ArrowDown' );
	check( await tabTo( page, '#filter_action', 3 ) > 0 && await focusVisible( page ), 'and then the Filter button, with a visible focus' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.keyboard.press( 'Enter' ) ] );
	check( /product_id=\d+/.test( page.url() ), 'choosing a product with the arrow keys and pressing Enter filters the list: ' + page.url().slice( -40 ) );

	await page.goto( ADMIN + 'wplit-licenses&orderby=id&order=asc' );
	check( await tabTo( page, 'table.wp-list-table tbody tr:first-child a.row-title' ) > 0 && await focusVisible( page ), 'Tab reaches the first license, and you can see the focus' );
	const actionsShown = await page.evaluate( () => { const a = document.activeElement.closest( 'td' ).querySelector( '.row-actions' ); const c = getComputedStyle( a ); return 'static' === c.position || parseInt( c.left, 10 ) > -1000 || 'auto' === c.left; } );
	check( actionsShown, 'the row actions (Manage, Revoke) appear when the license has the focus' );
	check( await tabTo( page, 'table.wp-list-table tbody tr:first-child .row-actions a[href*="revoke"], table.wp-list-table tbody tr:first-child .row-actions a[href*="do=revoke"]', 4 ) > 0, 'and Tab goes on to Revoke' );
	dialogs.length = 0;
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.keyboard.press( 'Enter' ) ] );
	check( dialogs.length === 1 && /revoke/i.test( dialogs[ 0 ] ) && sql( 'SELECT status FROM wp_wplit_licenses ORDER BY id ASC LIMIT 1' ) === 'revoked', 'Enter asks to confirm (a native dialog) and then revokes the license' );

	// Revoking is asked about, whichever way it is done, and answering No leaves the licenses alone.
	await page.goto( ADMIN + 'wplit-licenses&orderby=id&order=asc&paged=2' );
	const target = page.locator( 'table.wp-list-table tbody tr:has(a.submitdelete)' ).first();
	const second = await target.locator( 'input[name="license[]"]' ).getAttribute( 'value' );
	page.removeAllListeners( 'dialog' );
	const asked = [];
	page.on( 'dialog', ( dialog ) => { asked.push( dialog.message() ); dialog.dismiss(); } );
	await target.hover();
	await target.locator( '.row-actions a.submitdelete' ).click();
	await page.waitForTimeout( 800 );
	check( 1 === asked.length && /Revoke this license/.test( asked[ 0 ] ) && 'active' === sql( 'SELECT status FROM wp_wplit_licenses WHERE id = ' + second ), 'the Revoke link in a list row asks first, and No leaves the license active' );
	await page.check( 'input[name="license[]"][value="' + second + '"]' );
	await page.selectOption( '#bulk-action-selector-top', 'revoke' );
	await page.click( '#doaction' );
	await page.waitForTimeout( 800 );
	check( 2 === asked.length && /selected licenses/.test( asked[ 1 ] ) && 'active' === sql( 'SELECT status FROM wp_wplit_licenses WHERE id = ' + second ), 'so does bulk Revoke, and No leaves it active' );
	page.removeAllListeners( 'dialog' );
	page.on( 'dialog', ( dialog ) => { dialogs.push( dialog.message() ); dialog.accept(); } );

	// The product tab bar.
	await page.goto( BASE + '/wp-admin/post.php?post=' + seed.alpha + '&action=edit' );
	check( await tabTo( page, '#wplit-tabs [data-tab="plans"]' ) > 0 && await focusVisible( page ), 'Tab reaches the Plans tab, with a visible focus' );
	await page.keyboard.press( 'Enter' );
	check( 'true' === await page.locator( '#wplit-tabs [data-tab="plans"]' ).getAttribute( 'aria-current' ) && await page.locator( '#wplit_plans' ).isVisible().catch( () => false ), 'Enter shows it, and it is marked as the current one' );
	check( 'Product sections' === await page.locator( '#wplit-tabs' ).getAttribute( 'aria-label' ), 'the tab bar is a navigation with a name' );
	check( await page.evaluate( () => document.documentElement.lang.length > 1 ), 'the page says what language it is in' );
	await shot( page, '36-keyboard-tabs' );

	// Put back what this test changed.
	sql( 'UPDATE wp_wplit_licenses SET status = "active" WHERE status = "revoked"' );
	await finish( env, logStart );
} )();
