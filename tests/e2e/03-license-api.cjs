/**
 * Steps 18 and 23 to 25 and 27 (the server side), 31 and the abuse protection: a license issued in the admin works
 * through the REST API: validate, activate, the site limit, deactivate, the update check, the download and rate limiting.
 */
const crypto = require( 'crypto' );
const fs = require( 'fs' );
const path = require( 'path' );
const fixtures = require( './fixtures.cjs' );
const { BASE, ROOT, check, createProduct, finish, login, logSize, meta, shot, sql, start } = require( './lib.cjs' );

const API = BASE + '/wp-json/wplicense-it/v1';
const UPLOADS = path.join( ROOT, 'wordpress/wp-content/uploads' );

async function call( route, body, method = 'POST' ) {
	const url = 'GET' === method ? API + route + '?' + new URLSearchParams( body ) : API + route;
	const response = await fetch( url, {
		method,
		headers: { 'Content-Type': 'application/json' },
		body: 'GET' === method ? undefined : JSON.stringify( body ),
	} );
	const text = await response.text();
	let json = null;
	try { json = JSON.parse( text ); } catch ( e ) { /* not JSON */ }
	return { status: response.status, json, text, headers: response.headers, buffer: Buffer.from( text, 'binary' ) };
}

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;

	await login( page );
	const productId = await createProduct( page, 'License API Test' );
	await page.click( '#wplit-tabs [data-tab="releases"]' );
	const zipFile = fixtures.samplePlugin( '1.0.0' );
	await page.setInputFiles( '#wplit_product_file_upload', zipFile );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
	check( meta( productId, 'wplit_product_version' ) === '1.0.0', 'the product has release 1.0.0' );

	// Issue a license by hand (step 18's screen).
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-add-license' );
	await page.selectOption( '#wplit-product', productId );
	await page.fill( '#wplit-email', 'Buyer@Example.com' );
	await page.fill( '#wplit-limit', '2' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( 'form button[type="submit"], form input[type="submit"]' ).last().click() ] );
	await shot( page, '18-license-issued' );
	const key = sql( 'SELECT license_key FROM wp_wplit_licenses ORDER BY id DESC LIMIT 1' );
	check( /^[A-Za-z0-9-]{20,}$/.test( key ), 'a license key was issued: ' + key );
	check( sql( 'SELECT CONCAT(email,"|",activation_limit,"|",status) FROM wp_wplit_licenses ORDER BY id DESC LIMIT 1' ) === 'buyer@example.com|2|active', 'the license is active, for 2 sites, with a lower-cased email' );
	check( /Buyer@Example\.com|buyer@example\.com/i.test( await page.locator( 'body' ).innerText() ), 'the license screen shows the customer' );

	const base = { license_key: key, product_id: Number( productId ) };

	// Validate.
	let r = await call( '/licenses/validate', base );
	check( r.status === 200 && r.json && r.json.success === true, 'validate: a good key is accepted: ' + r.text.slice( 0, 200 ) );
	check( r.json && r.json.license && r.json.license.status === 'active', 'validate: the license is active' );
	r = await call( '/licenses/validate', { license_key: 'WRONG-KEY-0000000000000000', product_id: Number( productId ) } );
	check( r.status === 404 || r.status === 403, 'validate: a wrong key is refused, got ' + r.status + ' ' + r.text.slice( 0, 120 ) );
	r = await call( '/licenses/validate', { license_key: key, product_id: 999999 } );
	check( r.status >= 400 && r.json && r.json.success === false, 'validate: the right key for a wrong product is refused' );
	r = await call( '/licenses/validate', { license_key: key } );
	check( r.status === 400, 'validate: a missing product_id is a 400, got ' + r.status );

	// Activate, the limit, deactivate.
	const site = ( n ) => 'https://site-' + n + '.example.com';
	r = await call( '/licenses/activate', { ...base, site_url: site( 1 ) } );
	check( r.status === 200 && r.json.code === 'activated', 'activate: site 1: ' + r.text.slice( 0, 200 ) );
	r = await call( '/licenses/activate', { ...base, site_url: site( 1 ) } );
	check( r.status === 200 && r.json.code === 'already_active', 'activate: the same site again is already_active, got ' + ( r.json && r.json.code ) );
	r = await call( '/licenses/activate', { ...base, site_url: site( 2 ) } );
	check( r.status === 200, 'activate: site 2' );
	r = await call( '/licenses/activate', { ...base, site_url: site( 3 ) } );
	check( r.status === 403 && r.json.code === 'limit_reached', 'step 24: site 3 is refused with limit_reached: ' + r.text.slice( 0, 200 ) );
	r = await call( '/licenses/activate', { ...base, site_url: 'http://localhost:8888' } );
	check( r.status === 200, 'activate: a local site does not need a slot: ' + r.text.slice( 0, 200 ) );
	r = await call( '/licenses/deactivate', { license_key: key, site_url: site( 1 ) } );
	check( r.status === 200 && r.json.code === 'deactivated', 'step 25: deactivate frees a slot: ' + r.text.slice( 0, 200 ) );
	r = await call( '/licenses/activate', { ...base, site_url: site( 3 ) } );
	check( r.status === 200, 'step 25: site 3 now fits' );
	r = await call( '/licenses/validate', { ...base, site_url: site( 2 ) } );
	check( r.json && r.json.site_active === true, 'validate with a site_url says whether the site is active' );
	check( sql( 'SELECT COUNT(*) FROM wp_wplit_activations WHERE status="active" AND license_id=(SELECT id FROM wp_wplit_licenses WHERE license_key="' + key + '")' ) === '3', 'the database holds 3 active activations (2 sites and the local one)' );

	// The admin's view of it.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses' );
	check( ( await page.locator( 'body' ).innerText() ).includes( key.slice( 0, 8 ) ), 'the licenses list shows the key' );
	await shot( page, '18-licenses-list' );

	// Update check and download.
	r = await call( '/updates/check', { ...base, current_version: '0.9.0' } );
	check( r.status === 200 && r.json.success === true, 'updates/check: ' + r.text.slice( 0, 200 ) );
	check( r.json.product.version === '1.0.0' && r.json.update_available === true, 'updates/check: version 1.0.0 is offered to 0.9.0' );
	check( r.json.product.requires === '6.2', 'updates/check: the minimum WordPress comes from the zip' );
	check( typeof r.json.product.changelog === 'string', 'updates/check: the response has a changelog field' );
	const downloadUrl = r.json.download_url;
	check( /\/wp-json\/wplicense-it\/v1\/download\?token=/.test( downloadUrl ) && ! downloadUrl.includes( key ), 'updates/check: the link has a token and never the key' );
	const download = await fetch( downloadUrl );
	const bytes = Buffer.from( await download.arrayBuffer() );
	check( download.status === 200 && /zip/.test( download.headers.get( 'content-type' ) || '' ), 'download: 200 and a zip content type, got ' + download.status + ' ' + download.headers.get( 'content-type' ) );
	check( crypto.createHash( 'sha256' ).update( bytes ).digest( 'hex' ) === crypto.createHash( 'sha256' ).update( fs.readFileSync( zipFile ) ).digest( 'hex' ), 'download: the file is byte for byte the uploaded zip' );
	const tampered = await fetch( downloadUrl + 'x' );
	check( tampered.status === 403, 'download: a changed token is refused, got ' + tampered.status );
	r = await call( '/updates/check', { ...base, current_version: '1.0.0' } );
	check( r.json.update_available === false, 'updates/check: 1.0.0 is current for 1.0.0' );

	// Step 31: an expired license stops getting updates, but can still be deactivated.
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE license_key='" + key + "'" );
	r = await call( '/updates/check', { ...base, current_version: '0.9.0' } );
	check( r.status === 403 && r.json.code === 'expired', 'step 31: an expired license gets no update: ' + r.status + ' ' + r.text.slice( 0, 160 ) );
	r = await call( '/licenses/validate', base );
	check( r.json && r.json.code === 'expired', 'step 31: validate says expired' );
	r = await call( '/licenses/deactivate', { license_key: key, site_url: site( 2 ) } );
	check( r.status === 200, 'step 31: an expired license can still be deactivated, so customers can move it' );
	const staleDownload = await fetch( downloadUrl );
	check( staleDownload.status === 403, 'step 31: a download link made before expiry stops working once the license expires, got ' + staleDownload.status );

	// Revoked.
	sql( "UPDATE wp_wplit_licenses SET expires_at = NULL, status='revoked' WHERE license_key='" + key + "'" );
	r = await call( '/licenses/validate', base );
	check( r.json && r.json.code === 'revoked', 'a revoked license validates as revoked, got ' + ( r.json && r.json.code ) );

	// Abuse protection last, because it blocks this machine for a while.
	let blocked = 0;
	for ( let i = 1; i <= 40 && ! blocked; i++ ) {
		const attempt = await call( '/licenses/validate', { license_key: 'GUESS-' + i + '-0000000000000000', product_id: Number( productId ) } );
		if ( attempt.status === 429 ) { blocked = i; check( attempt.headers.get( 'retry-after' ) !== null, 'a rate-limited answer carries Retry-After' ); }
	}
	check( blocked > 0, 'wrong keys are rate limited (blocked after ' + blocked + ' tries)' );
	console.log( 'rate limited after ' + blocked + ' wrong keys' );
	sql( "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_wplit%' OR option_name LIKE '\\_transient\\_timeout\\_wplit%'" );

	await finish( env, logStart );
} )();
