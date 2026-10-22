/**
 * Steps 7 to 9 and 26 to 30 of docs/TESTING.md: uploads, the release history, rollback and delete.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, meta, shot, start } = require( './lib.cjs' );

const UPLOADS = path.join( ROOT, 'wordpress/wp-content/uploads' );

async function openTab( page, tab ) {
	await page.click( '#wplit-tabs [data-tab="' + tab + '"]' );
}

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

async function notices( page ) {
	return ( await page.locator( '.notice' ).allInnerTexts() ).join( '\n' );
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	page.on( 'dialog', ( dialog ) => dialog.accept() );

	await login( page );
	const id = await createProduct( page, 'Releases Test' );

	// Step 7: upload a release.
	await openTab( page, 'releases' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.0.0' ) );
	await save( page );
	const text = await notices( page );
	check( /Release saved\. Read from the zip: WPLit Sample 1\.0\.0 \(plugin\)/.test( text ), 'step 7: a green notice names the plugin and version: ' + text.slice( 0, 300 ) );
	check( /Needs WordPress 6\.2 and PHP 7\.4/.test( text ), 'step 7: the notice shows the requirements' );
	check( meta( id, 'wplit_product_version' ) === '1.0.0', 'step 7: the version setting is 1.0.0, got ' + meta( id, 'wplit_product_version' ) );
	check( meta( id, 'wplit_package_name' ) === 'WPLit Sample', 'step 7: the package name is saved' );
	const stored = meta( id, 'file_dir_path' );
	check( /^wplit-files\/p\d+\/v1\.0\.0\/[a-f0-9]{32}\/wplit-sample-1\.0\.0\.zip$/.test( stored ), 'step 7: the zip path has the product ID, version and a random folder, got ' + stored );
	check( fs.existsSync( path.join( UPLOADS, stored ) ), 'step 7: the zip file exists on disk' );
	await openTab( page, 'releases' );
	check( ( await page.locator( '#wplit_release_history tbody tr' ).count() ) === 1, 'step 7: the history has one release' );
	check( ( await page.locator( '#wplit_release_history' ).innerText() ).includes( 'Current' ), 'step 7: that release is marked Current' );
	await shot( page, '07-one-release' );

	// Step 8: bad uploads change nothing.
	await openTab( page, 'releases' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.notAPlugin() );
	await save( page );
	check( /The release was not saved\./.test( await notices( page ) ), 'step 8: a zip that is not a plugin is refused with a message: ' + ( await notices( page ) ).slice( 0, 200 ) );
	check( meta( id, 'file_dir_path' ) === stored, 'step 8: the stored release is unchanged' );

	// This server accepts 2 MB per upload (upload_max_filesize), so a 3 MB zip is stopped in the browser at once.
	await openTab( page, 'releases' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.bigZip( 3 * 1024 * 1024 ) );
	const big = await page.locator( '#wplit_product_file_upload-warning' ).innerText();
	check( /too big for this server\. It accepts up to 2 MB/.test( big ), 'step 8: a 3 MB zip is stopped in the browser and names the 2 MB limit: ' + big.slice( 0, 200 ) );
	await page.setInputFiles( '#wplit_product_file_upload', [] );

	// A file over post_max_size (8M) would make PHP drop the whole form and WordPress show the Posts list with no
	// message. The product screen must warn as soon as the file is chosen, and keep the form intact.
	await openTab( page, 'releases' );
	await page.fill( '#wplit_product_version', '1.0.0' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.bigZip( 9 * 1024 * 1024 ) );
	check( await page.locator( '#wplit_product_file_upload-warning' ).isVisible(), 'step 8: choosing a 9 MB zip shows a warning at once' );
	check( /too big for this server/.test( await page.locator( '#wplit_product_file_upload-warning' ).innerText() ), 'step 8: the warning says the file is too big' );
	check( ( await page.locator( '#wplit_product_file_upload' ).evaluate( ( el ) => el.files.length ) ) === 0, 'step 8: the oversized file is cleared from the form' );
	await shot( page, '08-over-post-max' );
	await save( page );
	check( page.url().includes( 'post.php?post=' + id ), 'step 8: saving afterwards stays on the product, not the Posts list: ' + page.url() );
	check( meta( id, 'file_dir_path' ) === stored, 'step 8: the stored release is unchanged' );
	await openTab( page, 'releases' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.bigZip( 1024 * 1024 ) );
	check( ! ( await page.locator( '#wplit_product_file_upload-warning' ).isVisible() ), 'step 8: a 1 MB zip shows no warning' );
	await page.setInputFiles( '#wplit_product_file_upload', [] );

	// Step 9: a logo.
	await openTab( page, 'software' );
	await page.setInputFiles( '#wplit_product_logo', fixtures.png( 'logo.png' ) );
	await save( page );
	check( /The logo was saved\./.test( await notices( page ) ), 'step 9: the logo is saved: ' + ( await notices( page ) ).slice( 0, 200 ) );
	const logoUrl = meta( id, 'wplit_product_logo_url' );
	check( /\/wplit-files\/p\d+\/logo\/logo\.png$/.test( logoUrl ), 'step 9: the logo address is under the product folder, got ' + logoUrl );
	check( ( await page.request.get( logoUrl ) ).status() === 200, 'step 9: the logo address loads' );
	await openTab( page, 'software' );
	check( await page.locator( '#wplit_software img' ).first().isVisible(), 'step 9: the logo shows as a preview' );
	await shot( page, '09-logo' );

	await openTab( page, 'software' );
	await page.setInputFiles( '#wplit_product_logo', fixtures.fakePng( 'not-really.png' ) );
	await save( page );
	check( /The logo was not saved\./.test( await notices( page ) ), 'step 9: a text file named .png is refused: ' + ( await notices( page ) ).slice( 0, 200 ) );
	check( meta( id, 'wplit_product_logo_url' ) === logoUrl, 'step 9: the earlier logo is unchanged' );

	// Steps 26 to 30: a second release, rollback and delete.
	await openTab( page, 'releases' );
	await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( '1.1.0', '= 1.1.0 =\n* Fixed a pretend bug.\n* Added a line.\n\n= 1.0.0 =\n* First.\n' ) );
	await save( page );
	check( meta( id, 'wplit_product_version' ) === '1.1.0', 'step 26: the version is now 1.1.0' );
	check( meta( id, 'wplit_changelog' ) === '* Fixed a pretend bug.\n* Added a line.', 'step 26: the notes are read from the readme, got ' + JSON.stringify( meta( id, 'wplit_changelog' ) ) );
	await openTab( page, 'releases' );
	const rows = await page.locator( '#wplit_release_history tbody tr' ).allInnerTexts();
	check( rows.length === 2, 'step 26: the history lists two releases, got ' + rows.length );
	check( /1\.1\.0/.test( rows[ 0 ] ) && /Current/.test( rows[ 0 ] ), 'step 26: 1.1.0 is first and Current' );
	await shot( page, '26-two-releases' );
	const newZip = meta( id, 'file_dir_path' );

	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wplit_release_history tbody tr:nth-child(2) a:has-text("Make current")' ) ] );
	check( meta( id, 'wplit_product_version' ) === '1.0.0', 'step 29: rolling back sets the version to 1.0.0, got ' + meta( id, 'wplit_product_version' ) );
	check( meta( id, 'file_dir_path' ) === stored, 'step 29: rolling back points at the first zip' );
	check( /Version 1\.0\.0 is now the release customers get/.test( await notices( page ) ), 'step 29: a message confirms the rollback' );
	await openTab( page, 'releases' );
	await shot( page, '29-rolled-back' );

	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#wplit_release_history tbody tr:nth-child(1) a:has-text("Delete")' ) ] );
	check( ! fs.existsSync( path.join( UPLOADS, newZip ) ), 'step 30: the deleted release\'s zip is gone from disk' );
	check( fs.existsSync( path.join( UPLOADS, stored ) ), 'step 30: the current release\'s zip is still there' );
	await openTab( page, 'releases' );
	check( ( await page.locator( '#wplit_release_history tbody tr' ).count() ) === 1, 'step 30: one release is left in the history' );

	await finish( env, logStart );
} )();
