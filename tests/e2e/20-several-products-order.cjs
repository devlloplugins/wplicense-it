/**
 * An order with lines for several licensed products on real WooCommerce: every line gets its license, all linked to the one
 * order record, the order record does not name just one of the products, and the products of the order can be read back from
 * its licenses. An order for one product still records that product.
 */
const fixtures = require( './fixtures.cjs' );
const { check, createProduct, finish, login, logSize, sql, start, woo } = require( './lib.cjs' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

// A software product with one plan, on sale. Returns the WPLicense It product and the WooCommerce product it created.
async function product( page, name, price ) {
	const software = await createProduct( page, name );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	await page.click( '#wplit-tabs [data-tab="plans"]' );
	await page.fill( 'input[name="wplit_plans[0][name]"]', 'One site' );
	await page.fill( 'input[name="wplit_plans[0][price]"]', price );
	await page.fill( 'input[name="wplit_plans[0][sites]"]', '1' );
	await save( page );
	const wooId = Number( sql( "SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wplit_product_id' AND m.meta_value='" + software + "' WHERE p.post_type='product' ORDER BY p.ID DESC LIMIT 1" ) );
	woo( { op: 'set_product_status', product_id: wooId, status: 'publish' } );
	return { software: Number( software ), wooId };
}

const row = ( orderId ) => sql( "SELECT id, product_id, status FROM wp_wplit_license_orders WHERE order_number='WC-" + orderId + "'" ).split( '\t' ).map( Number ).concat( [] );

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	const a = await product( page, 'Several A', '30' );
	const b = await product( page, 'Several B', '45' );
	check( a.wooId > 0 && b.wooId > 0 && a.software !== b.software, 'two products are on sale' );

	const email = 'several' + Date.now() + '@example.com';
	const customer = woo( { op: 'create_user', login: 'several' + Date.now(), email, password: 'pw-several-test-1' } );

	// One order, lines for both products.
	const order = woo( { op: 'create_order', product_id: a.wooId, more_product_ids: [ b.wooId ], qty: 2, email, user_id: customer.user_id, status: 'completed' } );
	const licenses = sql( "SELECT l.product_id, COUNT(*) FROM wp_wplit_licenses l JOIN wp_wplit_license_orders o ON o.id=l.order_id WHERE o.order_number='WC-" + order.order_id + "' GROUP BY l.product_id ORDER BY MIN(l.id)" ).split( '\n' ).map( ( line ) => line.split( '\t' ).map( Number ) );
	check( JSON.stringify( licenses ) === JSON.stringify( [ [ a.software, 2 ], [ b.software, 1 ] ] ), 'every line got its licenses, all linked to the one order: ' + JSON.stringify( licenses ) );
	check( 1 === Number( sql( "SELECT COUNT(*) FROM wp_wplit_license_orders WHERE order_number='WC-" + order.order_id + "'" ) ), 'and the order has one record' );

	const [ rowId, recorded ] = row( order.order_id );
	check( 0 === recorded, 'the order record does not name one of the two products as the product (it holds ' + recorded + ')' );
	const read = woo( { op: 'order_products', order_row_id: rowId } ).ids.map( Number );
	check( JSON.stringify( read ) === JSON.stringify( [ a.software, b.software ] ), 'the products of the order are read back from its licenses: ' + JSON.stringify( read ) );

	// An order for one product still records it.
	const single = woo( { op: 'create_order', product_id: b.wooId, qty: 3, email, user_id: customer.user_id, status: 'completed' } );
	const [ singleRow, singleProduct ] = row( single.order_id );
	check( b.software === singleProduct, 'an order for one product records that product: ' + singleProduct );
	check( JSON.stringify( woo( { op: 'order_products', order_row_id: singleRow } ).ids.map( Number ) ) === JSON.stringify( [ b.software ] ), 'and reads back as that one product' );

	check( 0 === Number( sql( "SELECT COUNT(*) FROM wp_wplit_licenses WHERE order_id IS NULL AND email='" + email + "'" ) ), 'no license of this customer is left without its order' );

	await finish( env, logStart );
} )();
