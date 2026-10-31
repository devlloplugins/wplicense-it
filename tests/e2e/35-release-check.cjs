/**
 * Card 112 (the review of releasing a new version): a release that lost the license code is held as a draft.
 * - the first upload without the code is published with a warning (setting up is not blocked);
 * - a release with the Connect code is published;
 * - a later zip without the code is saved as a draft: an error says why, customers keep the current release (the
 *   update check still answers the old version), and the Release history marks it Draft with "Make current anyway";
 * - "Make current anyway" publishes it.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const fixtures = require( './fixtures.cjs' );
const { BASE, check, createProduct, finish, login, logSize, meta, shot, sql, start } = require( './lib.cjs' );

async function save( page ) {
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
}

async function upload( page, productId, zip ) {
	await page.goto( BASE + '/wp-admin/post.php?post=' + productId + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await page.setInputFiles( '#wplit_product_file_upload', zip );
	await save( page );
	return page.locator( '.notice' ).allInnerTexts().then( ( texts ) => texts.join( '\n' ) );
}

/** The sample plugin at a version, with the Connect code pasted in (or without it). */
function sample( version, snippet ) {
	const zip = fixtures.samplePlugin( version );
	if ( ! snippet ) {
		return zip;
	}
	const dir = fs.mkdtempSync( path.join( fixtures.DIR, 'rc-' ) );
	execFileSync( 'unzip', [ '-q', zip, '-d', dir ] );
	const main = path.join( dir, 'wplit-sample', 'wplit-sample.php' );
	fs.writeFileSync( main, fs.readFileSync( main, 'utf8' ).replace( /^\/\/ STEP 1:.*$/m, snippet ) );
	const out = path.join( dir, 'wplit-sample-' + version + '.zip' );
	execFileSync( 'zip', [ '-qr', out, 'wplit-sample' ], { cwd: dir } );
	return out;
}

const answer = ( productId, key ) => JSON.parse( execFileSync( 'curl', [ '-s', '-X', 'POST', BASE + '/wp-json/wplicense-it/v1/updates/check', '-d', 'license_key=' + key + '&product_id=' + productId + '&current_version=0.1' ], { encoding: 'utf8' } ) );

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );

	const productId = await createProduct( page, 'Release Check Product' );
	let notices = await upload( page, productId, sample( '1.0.0' ) );
	check( '1.0.0' === meta( productId, 'wplit_product_version' ), 'a first upload without the license code is published' );
	check( /no wplicense_it_client\(\) call was found.*cannot activate their license or get updates/s.test( notices ), 'with a warning that customers cannot get updates without the code' );

	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const snippet = await page.inputValue( '#wplit-connect-code' );
	notices = await upload( page, productId, sample( '1.1.0', snippet ) );
	check( '1.1.0' === meta( productId, 'wplit_product_version' ) && ! /SDK|wplicense_it_client/.test( notices ), 'a release with the Connect code is published without that warning' );

	execFileSync( 'php', [ '-r', 'define( "WP_ADMIN", true ); require "' + path.join( require( './lib.cjs' ).ROOT, 'wordpress/wp-load.php' ) + '"; Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->issue_license( ' + productId + ', "releases@example.com", null, null, 1, null );' ] );
	const key = sql( 'SELECT license_key FROM wp_wplit_licenses WHERE product_id=' + productId + ' ORDER BY id DESC LIMIT 1' );

	// The next zip lost the code (say the vendor folder was left out of the build).
	notices = await upload( page, productId, sample( '1.2.0' ) );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	await shot( page, '64-release-draft' );
	check( /Release 1\.2\.0 was saved as a draft and is not offered to customers/.test( notices ) && /whoever installs this one would stop getting updates/.test( notices ), 'an error says the release is a draft and why: ' + notices.slice( 0, 300 ).replace( /\n/g, ' | ' ) );
	check( '1.1.0' === meta( productId, 'wplit_product_version' ), 'customers keep 1.1.0' );
	check( '1.1.0' === answer( productId, key ).product.version, 'and the update check still answers 1.1.0' );
	const row = page.locator( '#wplit-releases tr, .wplit-releases tr, table tr', { hasText: '1.2.0' } ).filter( { has: page.locator( '.wplit-draft' ) } );
	check( 1 === await row.count(), 'the Release history marks 1.2.0 as Draft' );
	check( /Not offered to customers/.test( await row.innerText() ) && 1 === await row.locator( 'a', { hasText: 'Make current anyway' } ).count(), 'with the reason and Make current anyway' );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), row.locator( 'a', { hasText: 'Make current anyway' } ).click() ] );
	check( '1.2.0' === meta( productId, 'wplit_product_version' ) && '1.2.0' === answer( productId, key ).product.version, 'Make current anyway publishes it' );

	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
