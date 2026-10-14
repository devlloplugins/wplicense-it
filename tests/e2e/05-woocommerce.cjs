/**
 * Steps 10 to 15 of docs/TESTING.md on real WooCommerce: the Plans table, the sync, a price edited in WooCommerce, removing
 * a plan, the test purchase, and Publish.
 */
const fs = require( 'fs' );
const fixtures = require( './fixtures.cjs' );
const { BASE, check, createProduct, finish, login, logSize, meta, shot, sql, start, woo } = require( './lib.cjs' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

async function plansTab( page ) {
	await page.click( '#wplit-tabs [data-tab="plans"]' );
}

async function notices( page ) {
	return ( await page.locator( '.notice, #wplit_plans' ).allInnerTexts() ).join( '\n' );
}

/** Fills plan row n of the table. */
async function fillRow( page, n, row ) {
	const f = ( name ) => 'input[name="wplit_plans[' + n + '][' + name + ']"]';
	await page.fill( f( 'name' ), row.name );
	await page.fill( f( 'price' ), row.price );
	await page.fill( f( 'sites' ), String( row.sites ) );
	await page.selectOption( 'select[name="wplit_plans[' + n + '][period]"]', row.period );
}

/** The WooCommerce products made for a software product's plans, by plan name. */
function wooProducts( software ) {
	const rows = sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' AND p.post_status<>'trash' ORDER BY p.ID" );
	return rows ? rows.split( '\n' ).map( Number ) : [];
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	const id = await createProduct( page, 'Plans Test' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	check( meta( id, 'wplit_product_version' ) === '1.0.0', 'the software has release 1.0.0' );

	// Step 10: add two plans.
	await plansTab( page );
	await fillRow( page, 0, { name: 'One site', price: '49', sites: 1, period: 'P1Y' } );
	await fillRow( page, 1, { name: 'Five sites', price: '99.50', sites: 5, period: 'P1Y' } );
	await save( page );
	await plansTab( page );
	const afterSave = await notices( page );
	check( /Created as a draft product in WooCommerce/.test( afterSave ), 'step 10: both plans report a draft product was created: ' + afterSave.slice( 0, 300 ).replace( /\n/g, ' | ' ) );
	await shot( page, '10-plans-saved' );
	const products = wooProducts( id );
	check( products.length === 2, 'step 10: two WooCommerce products exist for the plans, got ' + products.length );
	const [ one, five ] = products.map( ( pid ) => ( { id: pid, ...woo( { op: 'product_info', product_id: pid } ) } ) );
	check( one && one.status === 'draft' && one.type === 'simple' && one.virtual === true, 'step 10: product 1 is a draft, virtual, simple product: ' + JSON.stringify( one ) );
	check( one && Number( one.price ) === 49 && one.limit === '1' && one.period === 'P1Y' && one.software === String( id ), 'step 10: product 1 has price 49, 1 site, 1 year and the software link' );
	check( five && Number( five.price ) === 99.5 && five.limit === '5', 'step 10: product 2 has price 99.50 and 5 sites: ' + JSON.stringify( five ) );
	check( one && one.name === 'Plans Test - One site', 'step 10: the product is named after the software and the plan, got ' + ( one && one.name ) );

	// Saving again changes nothing.
	await plansTab( page );
	await save( page );
	check( wooProducts( id ).length === 2, 'saving again does not create more products' );

	// Step 11: a price edited in WooCommerce is flagged, not overwritten.
	woo( { op: 'edit_price', product_id: one.id, price: '39' } );
	await page.reload();
	await plansTab( page );
	await save( page );
	await plansTab( page );
	const conflict = await page.locator( '#wplit_plans' ).innerText();
	check( /WooCommerce price is 39\.00, plan price is 49/.test( conflict ), 'step 11: the row shows both prices: ' + conflict.slice( 0, 400 ).replace( /\n/g, ' | ' ) );
	check( woo( { op: 'product_info', product_id: one.id } ).price === '39', 'step 11: the WooCommerce price was not overwritten' );
	await shot( page, '11-price-conflict' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'a:has-text("Keep the plan price")' ) ] );
	check( Number( woo( { op: 'product_info', product_id: one.id } ).price ) === 49, 'step 11: Keep the plan price puts 49 back on the product' );
	woo( { op: 'edit_price', product_id: five.id, price: '120' } );
	await page.reload();
	await plansTab( page );
	await save( page );
	await plansTab( page );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'a:has-text("Use the WooCommerce price")' ) ] );
	await plansTab( page );
	check( await page.inputValue( 'input[name="wplit_plans[1][price]"]' ) === '120.00', 'step 11: Use the WooCommerce price makes the plan 120.00, got ' + await page.inputValue( 'input[name="wplit_plans[1][price]"]' ) );

	// Step 13: Test this plan, then Publish.
	await plansTab( page );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'a:has-text("Test this plan")' ).first().click() ] );
	await plansTab( page );
	const report = await page.locator( '#wplit_plans' ).innerText();
	await shot( page, '13-test-this-plan' );
	check( /Test of "One site"/.test( report ), 'step 13: a test report is shown for the plan: ' + report.slice( 0, 200 ).replace( /\n/g, ' | ' ) );
	for ( const label of [ 'The WooCommerce product', 'The license terms', 'The software and its zip', 'A purchase issues a license', 'The license can be activated', 'The download works', 'This site can reach its own license API' ] ) {
		check( report.includes( label ), 'step 13: the report lists "' + label + '"' );
	}
	const failed = ( await page.locator( '#wplit_plans li' ).allInnerTexts() ).filter( ( text ) => /^(Failed|Not tried)/.test( text ) || /Failed:/.test( text ) );
	check( failed.length === 0, 'step 13: no check failed: ' + failed.join( ' | ' ).slice( 0, 500 ) );
	const publish = page.locator( 'a:has-text("Publish in the shop")' ).first();
	check( await publish.count() === 1, 'step 13: Publish in the shop is offered after the test passes' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), publish.click() ] );
	check( woo( { op: 'product_info', product_id: one.id } ).status === 'publish', 'step 13: Publish makes the WooCommerce product public' );

	// Step 14: changing what was tested cancels the pass.
	await plansTab( page );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.locator( 'a:has-text("Test this plan")' ).nth( 1 ).click() ] );
	await plansTab( page );
	check( await page.locator( 'a:has-text("Publish in the shop")' ).count() >= 1, 'step 14: the second plan can be published after its own test' );
	await page.fill( 'input[name="wplit_plans[1][sites]"]', '7' );
	await save( page );
	await plansTab( page );
	check( await page.locator( 'a:has-text("Publish in the shop")' ).count() === 0, 'step 14: changing the sites of a plan removes its Publish link' );
	check( /changed since the test passed/.test( await page.locator( '#wplit_plans' ).innerText() ), 'step 14: the row says it must be tested again' );
	check( woo( { op: 'product_info', product_id: five.id } ).limit === '7', 'step 14: the new site count reached the WooCommerce product' );

	// Step 12: removing a plan takes its product off sale but keeps it.
	await page.fill( 'input[name="wplit_plans[0][name]"]', '' );
	await page.fill( 'input[name="wplit_plans[0][price]"]', '' );
	await page.fill( 'input[name="wplit_plans[0][sites]"]', '' );
	await save( page );
	await plansTab( page );
	check( woo( { op: 'product_info', product_id: one.id } ).status === 'draft', 'step 12: a removed plan\'s product goes back to draft' );
	check( woo( { op: 'product_info', product_id: one.id } ).name === 'Plans Test - One site', 'step 12: and is not deleted' );
	check( /Removed\. Its WooCommerce product was set back to draft/.test( await page.locator( '#wplit_plans' ).innerText() ), 'step 12: a message says so' );

	// A deleted product can be created again.
	woo( { op: 'trash_product', product_id: five.id } );
	await page.reload();
	await plansTab( page );
	check( /Deleted in WooCommerce/.test( await page.locator( '#wplit_plans' ).innerText() ), 'a trashed product is reported as deleted' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'a:has-text("Create it again")' ) ] );
	check( wooProducts( id ).length >= 2, 'Create it again makes a new product' );

	// Bad input is refused as a whole.
	await plansTab( page );
	await page.fill( 'input[name="wplit_plans[2][name]"]', 'Broken' );
	await page.fill( 'input[name="wplit_plans[2][price]"]', 'free' );
	await page.fill( 'input[name="wplit_plans[2][sites]"]', '1' );
	await save( page );
	await plansTab( page );
	check( /The plans were not saved/.test( await page.locator( '#wplit_plans' ).innerText() ) && /enter the price as a number/.test( await page.locator( '#wplit_plans' ).innerText() ), 'a bad price is refused with a message' );

	await finish( env, logStart );
} )();
