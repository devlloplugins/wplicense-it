/**
 * Browser test for the product screen's tab bar.
 *
 * Needs Playwright with Chromium (PLAYWRIGHT_BROWSERS_PATH) and PHP on the path.
 * Run: node tests/js/product-tabs.cjs
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

const page_html = execFileSync( 'php', [ path.join( __dirname, 'render-tabs.php' ) ], { encoding: 'utf8' } );
const URL = 'http://wplit.test/wp-admin/post.php';
const BOXES = { software: [ 'wplit_software', 'wplit_defaults', 'authordiv' ], plans: [ 'wplit_plans' ], releases: [ 'wplit_release', 'wplit_release_history' ], connect: [ 'wplit_connect', 'wplit_legacy_key' ] };
const ALWAYS = [ 'postdivrich', 'submitdiv' ];

let failures = 0;
function check( condition, message ) {
	if ( ! condition ) { failures++; console.log( 'FAIL ' + message ); }
}

async function visibleIds( page ) {
	return page.evaluate( () => Array.from( document.querySelectorAll( '.postbox' ) ).filter( ( el ) => el.style.display !== 'none' ).map( ( el ) => el.id ).sort() );
}

async function expectTab( page, tab, label ) {
	const expected = BOXES[ tab ].concat( ALWAYS ).sort();
	const shown = await visibleIds( page );
	check( JSON.stringify( shown ) === JSON.stringify( expected ), label + ': expected ' + expected + ' got ' + shown );
	const selected = await page.evaluate( () => Array.from( document.querySelectorAll( '[data-tab]' ) ).filter( ( a ) => a.getAttribute( 'aria-selected' ) === 'true' ).map( ( a ) => a.dataset.tab ) );
	check( JSON.stringify( selected ) === JSON.stringify( [ tab ] ), label + ': aria-selected is ' + selected );
	const active = await page.evaluate( () => Array.from( document.querySelectorAll( '.nav-tab-active' ) ).map( ( a ) => a.dataset.tab ) );
	check( JSON.stringify( active ) === JSON.stringify( [ tab ] ), label + ': nav-tab-active is ' + active );
}

( async () => {
	const browser = await chromium.launch();

	async function open( options, hash, setup ) {
		const context = await browser.newContext( options || {} );
		const page = await context.newPage();
		const errors = [];
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await page.route( URL + '**', ( route ) => route.fulfill( { contentType: 'text/html', body: page_html } ) );
		if ( setup ) { await setup( page ); }
		await page.goto( URL + ( hash || '' ) );
		return { context, page, errors };
	}

	// 1. First visit: the Software tab, the bar visible, other boxes untouched.
	let t = await open();
	check( await t.page.isVisible( '#wplit-tabs' ), 'the bar is visible once the script has run' );
	await expectTab( t.page, 'software', 'first visit' );

	// 2. Clicking switches tabs.
	for ( const tab of [ 'plans', 'releases', 'connect', 'software' ] ) {
		await t.page.click( '[data-tab="' + tab + '"]' );
		await expectTab( t.page, tab, 'click ' + tab );
	}

	// 3. The chosen tab is remembered on reload.
	await t.page.click( '[data-tab="plans"]' );
	await t.page.reload();
	await expectTab( t.page, 'plans', 'after reload' );

	// 4. A link with a tab in the hash wins over the remembered one.
	await t.page.goto( URL + '#wplit-connect' );
	await t.page.reload();
	await expectTab( t.page, 'connect', 'hash wins' );
	check( t.errors.length === 0, 'no script errors: ' + t.errors );
	await t.context.close();

	// 5. A nonsense hash falls back to the default.
	t = await open( {}, '#wplit-nope' );
	await expectTab( t.page, 'software', 'bad hash' );
	await t.context.close();

	// 6. A hash that tries something odd does not break the page.
	t = await open( {}, '#wplit-__proto__' );
	await expectTab( t.page, 'software', 'prototype hash' );
	check( t.errors.length === 0, 'no script errors for a prototype hash: ' + t.errors );
	await t.context.close();

	// 7. Without JavaScript every box is visible and the bar stays hidden.
	t = await open( { javaScriptEnabled: false } );
	check( ! ( await t.page.isVisible( '#wplit-tabs' ) ), 'without JavaScript the bar stays hidden' );
	const all = await visibleIds( t.page );
	check( all.length === Object.values( BOXES ).flat().length + ALWAYS.length, 'without JavaScript every box is visible, saw ' + all );
	await t.context.close();

	// 8. Blocked storage does not break the tabs.
	t = await open( {}, '', ( page ) => page.addInitScript( () => {
		Object.defineProperty( window, 'sessionStorage', { get() { throw new Error( 'blocked' ); } } );
	} ) );
	await expectTab( t.page, 'software', 'storage blocked' );
	await t.page.click( '[data-tab="releases"]' );
	await expectTab( t.page, 'releases', 'storage blocked, click' );
	check( t.errors.length === 0, 'no script errors with storage blocked: ' + t.errors );
	await t.context.close();

	// 9. A box the user hid with Screen Options (missing from the page) is skipped.
	t = await open( {}, '', ( page ) => page.addInitScript( () => {
		document.addEventListener( 'DOMContentLoaded', () => {} );
	} ) );
	await t.page.evaluate( () => [ 'wplit_release', 'wplit_release_history' ].forEach( ( id ) => document.getElementById( id ).remove() ) );
	await t.page.click( '[data-tab="releases"]' );
	check( ( await visibleIds( t.page ) ).join() === ALWAYS.slice().sort().join(), 'a missing box leaves an empty tab without errors' );
	check( t.errors.length === 0, 'no script errors with a missing box: ' + t.errors );
	await t.context.close();

	await browser.close();
	console.log( failures ? failures + ' check(s) failed' : 'All browser checks passed' );
	process.exit( failures ? 1 : 0 );
} )();
