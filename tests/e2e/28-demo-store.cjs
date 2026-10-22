/**
 * The demo store (card 89): `wp wplit demo create` fills the site through the plugin's own code, with licenses in every
 * state a seller meets, sends no email, refuses to run twice, shows up on the admin screens and in a customer's account,
 * and `wp wplit demo remove` deletes exactly what it made: none of it is left, and nothing else is touched.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const WP = path.join( ROOT, 'wordpress' );
const cli = ( ...args ) => spawnSync( 'php', [ path.join( ROOT, 'wp-cli-2.12.0.phar' ), '--allow-root', '--path=' + WP, ...args ], { encoding: 'utf8' } );
const php = ( code ) => cli( 'eval', code ).stdout.trim();
const one = ( query ) => sql( query );
const DEMO = "email LIKE 'demo-%@example.com'";

( async () => {
	const logStart = logSize();
	cli( 'wplit', 'demo', 'remove', '--yes' );

	// What is on the site before: it must be the same after the demo is removed.
	const before = {
		licenses: one( 'SELECT COUNT(*) FROM wp_wplit_licenses' ),
		users: one( 'SELECT COUNT(*) FROM wp_users' ),
		orders: php( 'echo count( wc_get_orders( array( "limit" => -1, "type" => array( "shop_order", "shop_order_refund" ), "status" => "any", "return" => "ids" ) ) );' ),
		products: one( "SELECT COUNT(*) FROM wp_posts WHERE post_type IN ('product','wplit_product')" ),
		someKey: one( 'SELECT license_key FROM wp_wplit_licenses ORDER BY id LIMIT 1' ),
	};
	woo( { op: 'clear_mail' } );

	// A customer address the demo would use is taken: nothing is made.
	const taken = php( 'echo wp_insert_user( array( "user_login" => "real-erin", "user_email" => "demo-erin@example.com", "user_pass" => wp_generate_password() ) );' );
	const clash = cli( 'wplit', 'demo', 'create', '--yes' );
	check( 0 !== clash.status && /demo-erin@example\.com exists already/.test( clash.stderr ) && '' === php( 'echo get_option( "wplit_demo" ) ? "made" : "";' ), 'an address that is taken stops it before anything is made' );
	php( 'require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user( ' + Number( taken ) + ' );' );

	// 1. Create.
	const made = cli( 'wplit', 'demo', 'create', '--yes' );
	check( 0 === made.status && /Success: The demo store is ready/.test( made.stdout ), 'create succeeds: ' + ( made.stdout + made.stderr ).trim().split( '\n' ).pop() );
	check( ! /Warning/.test( made.stdout + made.stderr ), 'every plan passed its test purchase and was published (no warnings)' );
	check( 0 === woo( { op: 'mail' } ).mail.length, 'no email was sent while making it: ' + woo( { op: 'mail' } ).mail.length );

	const again = cli( 'wplit', 'demo', 'create', '--yes' );
	check( 0 !== again.status && /There is a demo store already/.test( again.stderr ), 'a second create is refused' );
	const record = JSON.parse( php( 'echo wp_json_encode( get_option( "wplit_demo" ) );' ) );
	check( 2 === record.products.length && 4 === record.woo_products.length && 8 === record.users.length && 9 === record.orders.length && 8 === record.licenses.length, 'it recorded 2 products, 4 plan products, 8 customers, 9 orders and 8 licenses: ' + JSON.stringify( Object.fromEntries( Object.entries( record ).filter( ( [ , v ] ) => Array.isArray( v ) ).map( ( [ k, v ] ) => [ k, v.length ] ) ) ) );
	const status = cli( 'wplit', 'demo', 'status' ).stdout;
	check( /orders\s+9/.test( status ), 'status lists what is there' );

	// 2. What it made.
	const plugin = record.products[ 0 ];
	const theme = record.products[ 1 ];
	check( '1.1.0' === one( `SELECT meta_value FROM wp_postmeta WHERE post_id=${ plugin } AND meta_key='wplit_product_version'` ), 'the plugin\'s current release is 1.1.0' );
	check( 2 === JSON.parse( php( `echo wp_json_encode( array_map( fn( $r ) => $r->version, ( new Devllo\\WPLicenseIt\\Releases\\ReleaseService( new Devllo\\WPLicenseIt\\Releases\\MetaReleaseRepository( new Devllo\\WPLicenseIt\\Products\\WpProductMeta() ), new Devllo\\WPLicenseIt\\Products\\WpProductMeta() ) )->all( ${ plugin } ) ) );` ) ).length, 'with 1.0.0 in its release history' );
	check( 'theme' === one( `SELECT meta_value FROM wp_postmeta WHERE post_id=${ theme } AND meta_key='wplit_package_type'` ), 'the theme zip was read as a theme' );
	const zip = one( `SELECT meta_value FROM wp_postmeta WHERE post_id=${ plugin } AND meta_key='file_dir_path'` );
	check( '' !== zip && fs.existsSync( path.join( WP, 'wp-content/uploads', zip ) ), 'the release zip is stored in the protected folder: ' + zip );
	check( '4' === one( `SELECT COUNT(*) FROM wp_posts WHERE ID IN (${ record.woo_products.join( ',' ) }) AND post_status='publish'` ), 'the four plans are published in the shop' );

	const rows = Object.fromEntries( one( `SELECT SUBSTRING_INDEX(SUBSTRING(email,6),'@',1), CONCAT_WS('|', status, IFNULL(DATEDIFF(expires_at, UTC_TIMESTAMP()),'never'), (SELECT COUNT(*) FROM wp_wplit_activations a WHERE a.license_id=l.id AND a.status='active' AND a.is_local=0), (SELECT COUNT(*) FROM wp_wplit_activations a WHERE a.license_id=l.id AND a.is_local=1)) FROM wp_wplit_licenses l WHERE ${ DEMO }` ).split( '\n' ).map( ( line ) => line.split( '\t' ) ) );
	const field = ( name, i ) => ( rows[ name ] || '' ).split( '|' )[ i ];
	check( 'expired' === field( 'gina', 0 ) && Number( field( 'gina', 1 ) ) < -30, 'Gina\'s license expired a month ago: ' + rows.gina );
	check( 'active' === field( 'frank', 0 ) && Number( field( 'frank', 1 ) ) >= 9 && Number( field( 'frank', 1 ) ) <= 11, 'Frank\'s expires in about 10 days: ' + rows.frank );
	check( 'active' === field( 'bob', 0 ) && Number( field( 'bob', 1 ) ) > 500 && '2' === field( 'bob', 2 ) && '1' === field( 'bob', 3 ), 'Bob\'s was renewed early (over 500 days left), on 2 sites plus a staging site: ' + rows.bob );
	check( 'never' === field( 'erin', 1 ), 'Erin\'s theme license is lifetime: ' + rows.erin );
	check( 'revoked' === field( 'henry', 0 ) && 'refunded' === field( 'carol', 0 ), 'the cancelled order revoked Henry\'s, the refund ended Carol\'s' );
	check( 'active' === field( 'alice', 0 ) && 'active' === field( 'press', 0 ), 'Alice\'s and the hand-issued press license are active' );
	check( undefined === rows.dave, 'Dave paid by bank transfer: no license yet' );
	check( 'on-hold' === php( `$o = wc_get_orders( array( "billing_email" => "demo-dave@example.com", "limit" => 1 ) ); echo $o ? $o[0]->get_status() : "none";` ), 'and his order waits on hold' );
	check( 'expired@' === ( one( `SELECT CONCAT(e.type,'@') FROM wp_wplit_license_events e JOIN wp_wplit_licenses l ON l.id=e.license_id WHERE l.email='demo-gina@example.com' ORDER BY e.id DESC LIMIT 1` ) ), 'Gina\'s history ends with the expiry' );
	check( one( `SELECT DATEDIFF(UTC_TIMESTAMP(), MIN(e.created_at)) FROM wp_wplit_license_events e JOIN wp_wplit_licenses l ON l.id=e.license_id WHERE l.email='demo-gina@example.com'` ) >= '399', 'and starts on the day she bought it, 400 days ago' );

	// 3. On the screens.
	const env = await start();
	const page = env.page;
	await login( page );
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses&s=demo-' );
	await shot( page, '49-demo-licenses' );
	const list = await page.locator( 'table.wp-list-table tbody' ).innerText();
	check( /demo-frank@example\.com/.test( list ) && /demo-gina@example\.com/.test( list ) && 8 === await page.locator( 'table.wp-list-table tbody tr' ).count(), 'the Licenses list shows the 8 demo licenses' );
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-overview' );
	await shot( page, '50-demo-overview' );

	php( '$u = get_user_by( "email", "demo-alice@example.com" ); wp_set_password( "pw-demo-alice-1", $u->ID );' );
	await page.context().clearCookies();
	await page.goto( BASE + '/my-account/' );
	await page.fill( '#username', 'demo-alice' );
	await page.fill( '#password', 'pw-demo-alice-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button[name="login"]' ) ] );
	await page.goto( BASE + '/my-account/licenses/' );
	await shot( page, '51-demo-account' );
	const account = await page.locator( '.woocommerce-MyAccount-content' ).innerText();
	const aliceKey = one( "SELECT license_key FROM wp_wplit_licenses WHERE email='demo-alice@example.com'" );
	check( account.includes( aliceKey.slice( -5 ) ) && /Demo Backup Pro/.test( account ), 'Alice sees her license in My Account' );
	const href = await page.locator( '.woocommerce-MyAccount-content a', { hasText: 'Download' } ).first().getAttribute( 'href' );
	const download = await page.request.get( href, { maxRedirects: 5 } );
	const body = await download.body();
	check( 200 === download.status() && 'PK' === body.slice( 0, 2 ).toString(), 'her Download link serves the release zip: ' + download.status() + ', ' + body.length + ' bytes' );

	// 4. Remove.
	const files = record.products.map( ( id ) => path.join( WP, 'wp-content/uploads/wplit-files/p' + id ) );
	const gone = cli( 'wplit', 'demo', 'remove', '--yes' );
	check( 0 === gone.status && /Removed the demo store: 9 orders, 8 licenses, 4 woo products, 2 products, 8 users/.test( gone.stdout ), 'remove says what it deleted: ' + gone.stdout.trim() );
	check( '0' === one( `SELECT COUNT(*) FROM wp_wplit_licenses WHERE ${ DEMO }` ) && '0' === one( "SELECT COUNT(*) FROM wp_users WHERE user_email LIKE 'demo-%@example.com'" ), 'no demo licenses or customers are left' );
	check( '0' === one( `SELECT COUNT(*) FROM wp_wplit_activations WHERE license_id IN (${ record.licenses.join( ',' ) })` ) && '0' === one( `SELECT COUNT(*) FROM wp_wplit_license_events WHERE license_id IN (${ record.licenses.join( ',' ) })` ), 'nor their sites or history' );
	check( '0' === one( `SELECT COUNT(*) FROM wp_wplit_license_orders WHERE order_number IN (${ record.orders.map( ( id ) => "'WC-" + id + "'" ).join( ',' ) })` ), 'nor their order records' );
	check( '0' === one( `SELECT COUNT(*) FROM wp_posts WHERE ID IN (${ record.products.concat( record.woo_products ).join( ',' ) })` ) && files.every( ( dir ) => ! fs.existsSync( dir ) ), 'the products and their release files are gone' );
	check( '{}' === php( 'echo wp_json_encode( (object) array_filter( array( "option" => get_option( "wplit_demo" ) ) ) );' ), 'and so is the record' );
	const after = {
		licenses: one( 'SELECT COUNT(*) FROM wp_wplit_licenses' ),
		users: one( 'SELECT COUNT(*) FROM wp_users' ),
		orders: php( 'echo count( wc_get_orders( array( "limit" => -1, "type" => array( "shop_order", "shop_order_refund" ), "status" => "any", "return" => "ids" ) ) );' ),
		products: one( "SELECT COUNT(*) FROM wp_posts WHERE post_type IN ('product','wplit_product')" ),
		someKey: one( 'SELECT license_key FROM wp_wplit_licenses ORDER BY id LIMIT 1' ),
	};
	check( JSON.stringify( before ) === JSON.stringify( after ), 'everything that was there before is still there, unchanged in number (orders and refunds included): ' + JSON.stringify( after ) );
	check( /no demo store/.test( cli( 'wplit', 'demo', 'remove', '--yes' ).stdout ), 'removing again says there is nothing to remove' );

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
