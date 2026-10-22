/**
 * Card 119: the Connect tab offers the SDK as a zip. The link works for a seller, is refused without its nonce or a login,
 * and the unzipped folder is what the pasted code expects.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, check, createProduct, finish, login, logSize, shot, start } = require( './lib.cjs' );

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;

	await login( page );
	const id = await createProduct( page, 'SDK Download Test' );

	await page.goto( BASE + '/wp-admin/post.php?post=' + id + '&action=edit' );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	const link = page.locator( '#wplit-sdk-download' );
	check( await link.isVisible(), 'the Connect tab shows a Download the SDK link' );
	check( ! /Copy the sdk folder/.test( await page.locator( '#wplit_connect' ).innerText() ), 'the old copy-by-hand instruction is gone' );
	await shot( page, '07-connect-sdk' );

	const href = await link.getAttribute( 'href' );
	check( /action=wplit_download_sdk/.test( href ) && /_wpnonce=/.test( href ), 'the link carries the action and a nonce' );

	// Click it like a seller would.
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), link.click() ] );
	const out = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'wplit-sdk-' ) ), 'download.zip' );
	await download.saveAs( out );
	check( download.suggestedFilename() === 'wplicense-it-client.zip', 'the file is named wplicense-it-client.zip, got ' + download.suggestedFilename() );

	const listing = execFileSync( 'unzip', [ '-Z1', out ], { encoding: 'utf8' } ).trim().split( '\n' );
	check( listing.every( ( n ) => n.startsWith( 'wplicense-it-client/' ) ), 'everything is inside one wplicense-it-client folder' );
	check( listing.includes( 'wplicense-it-client/wplicense-it-client.php' ), 'the loader file is inside' );
	check( ! listing.some( ( n ) => /phpcs/.test( n ) ), 'development files are left out' );

	// Unpack into a vendor folder and check every PHP file parses and the loader can be required.
	const vendor = fs.mkdtempSync( path.join( os.tmpdir(), 'wplit-vendor-' ) );
	execFileSync( 'unzip', [ '-q', out, '-d', vendor ] );
	const bad = listing.filter( ( n ) => n.endsWith( '.php' ) ).filter( ( n ) => {
		try { execFileSync( 'php', [ '-l', path.join( vendor, n ) ], { stdio: 'pipe' } ); return false; } catch ( e ) { return true; }
	} );
	check( bad.length === 0, 'every PHP file in the zip parses: ' + bad.join( ',' ) );

	// Every SDK source file in the plugin is in the zip.
	const repoFiles = fs.readdirSync( path.resolve( __dirname, '../../sdk/src' ) ).map( ( f ) => 'wplicense-it-client/src/' + f );
	check( repoFiles.every( ( f ) => listing.includes( f ) ), 'every file of sdk/src is in the zip' );

	// Without a valid nonce, nothing is sent.
	const noNonce = await page.request.get( BASE + '/wp-admin/admin-post.php?action=wplit_download_sdk' );
	check( ! /zip/.test( noNonce.headers()[ 'content-type' ] || '' ), 'no zip without a nonce (status ' + noNonce.status() + ')' );
	const badNonce = await page.request.get( BASE + '/wp-admin/admin-post.php?action=wplit_download_sdk&_wpnonce=abcdef1234' );
	check( ! /zip/.test( badNonce.headers()[ 'content-type' ] || '' ), 'no zip with a wrong nonce (status ' + badNonce.status() + ')' );

	// Logged out: even the real link gives nothing.
	const anon = await env.browser.newContext();
	const anonResponse = await anon.request.get( href.startsWith( 'http' ) ? href : BASE + href );
	check( ! /zip/.test( anonResponse.headers()[ 'content-type' ] || '' ), 'no zip when logged out (status ' + anonResponse.status() + ')' );
	await anon.close();

	finish( env, logStart );
} )();
