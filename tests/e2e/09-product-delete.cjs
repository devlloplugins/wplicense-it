/**
 * Card 127: deleting a product for good removes its release zips, logo and banner; trashing it does not.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const FILES = path.join( ROOT, 'wordpress/wp-content/uploads/wplit-files' );

async function openTab( page, tab ) { await page.click( '#wplit-tabs [data-tab="' + tab + '"]' ); }
async function save( page ) { await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] ); }

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	page.on( 'dialog', ( dialog ) => dialog.accept() );
	await login( page );

	const keep = await createProduct( page, 'Keep Me' );
	await openTab( page, 'releases' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );

	const id = await createProduct( page, 'Delete Me' );
	for ( const version of [ '1.0.0', '1.1.0' ] ) {
		await openTab( page, 'releases' );
		await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( version ) );
		await save( page );
	}
	await openTab( page, 'software' );
	await page.setInputFiles( '#wplit_product_logo', fixtures.png( 'logo.png' ) );
	await save( page );

	const folder = path.join( FILES, 'p' + id );
	const keepFolder = path.join( FILES, 'p' + keep );
	const zips = ( dir ) => { const out = []; ( function walk( d ) { for ( const e of fs.readdirSync( d, { withFileTypes: true } ) ) { e.isDirectory() ? walk( path.join( d, e.name ) ) : out.push( path.join( d, e.name ) ); } }( dir ) ); return out; };
	check( zips( folder ).filter( ( f ) => f.endsWith( '.zip' ) ).length === 2, 'the product has two zips on disk' );
	check( zips( folder ).some( ( f ) => /logo\.png$/.test( f ) ), 'the product has its logo on disk' );

	// Plant things that must survive: a stray file the plugin does not know about is removed with the folder, but a link out of it is not followed.
	const decoy = fs.mkdtempSync( path.join( require( 'os' ).tmpdir(), 'wplit-decoy-' ) );
	fs.writeFileSync( path.join( decoy, 'precious.txt' ), 'do not delete' );
	fs.symlinkSync( decoy, path.join( folder, 'link-out' ) );
	fs.writeFileSync( path.join( folder, 'stray.txt' ), 'left over' );

	// Trash keeps the files.
	await page.goto( BASE + '/wp-admin/post.php?post=' + id + '&action=edit' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '#delete-action a.submitdelete' ) ] );
	check( sql( 'SELECT post_status FROM wp_posts WHERE ID=' + id ) === 'trash', 'the product is in the trash' );
	check( zips( folder ).filter( ( f ) => f.endsWith( '.zip' ) ).length === 2, 'trashing keeps the zips, so the product can be restored' );

	// Restore and trash again to be sure restore is harmless.
	await page.goto( BASE + '/wp-admin/edit.php?post_status=trash&post_type=wplit_product' );
	await shot( page, '09-trash' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( '#the-list tr', { hasText: 'Delete Me' } ).hover().then( () => page.locator( '#the-list tr', { hasText: 'Delete Me' } ).locator( '.untrash a' ).click() ) ] );
	check( sql( 'SELECT post_status FROM wp_posts WHERE ID=' + id ) !== 'trash', 'restoring brings the product back' );
	check( fs.existsSync( folder ), 'the folder is still there after a restore' );
	await page.goto( BASE + '/wp-admin/post.php?post=' + id + '&action=edit' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '#delete-action a.submitdelete' ) ] );

	// Delete permanently from the trash.
	await page.goto( BASE + '/wp-admin/edit.php?post_status=trash&post_type=wplit_product' );
	const row = page.locator( '#the-list tr', { hasText: 'Delete Me' } );
	await row.hover();
	await Promise.all( [ page.waitForLoadState( 'load' ), row.locator( '.delete a' ).click() ] );
	check( sql( 'SELECT COUNT(*) FROM wp_posts WHERE ID=' + id ) === '0', 'the product is gone from the database' );

	check( ! fs.existsSync( folder ), 'its folder wplit-files/p' + id + ' is gone, including stray files' );
	check( fs.existsSync( path.join( decoy, 'precious.txt' ) ), 'a link out of the folder was not followed' );
	check( fs.existsSync( keepFolder ) && zips( keepFolder ).some( ( f ) => f.endsWith( '.zip' ) ), 'the other product keeps its files' );
	check( fs.existsSync( path.join( FILES, 'index.php' ) ), 'the protected folder itself is intact' );

	finish( env, logStart );
} )();
