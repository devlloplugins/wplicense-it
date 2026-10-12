/**
 * Helpers for the browser tests that drive a real WordPress site built by tests/env/setup.sh.
 *
 * Needs Playwright with Chromium. Run a test with:
 *   NODE_PATH=$(npm root -g) node tests/e2e/01-admin.cjs
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( 'playwright' );

const ROOT = process.env.WPLIT_ENV_ROOT || '/opt/wp-test';
const BASE = process.env.WPLIT_URL || 'http://127.0.0.1:8080';
const CLIENT = process.env.WPLIT_CLIENT_URL || 'http://127.0.0.1:8081';
const SHOTS = process.env.WPLIT_SHOTS || '/tmp/wplit-shots';
const DEBUG_LOG = path.join( ROOT, 'wordpress/wp-content/debug.log' );

function secret( name ) {
	const text = fs.readFileSync( path.join( ROOT, 'secrets.env' ), 'utf8' );
	const match = text.match( new RegExp( '^' + name + '=(.*)$', 'm' ) );
	if ( ! match ) { throw new Error( 'No ' + name + ' in secrets.env' ); }
	return match[ 1 ].trim();
}

let failures = 0;
let checks = 0;
function check( condition, message ) {
	checks++;
	if ( ! condition ) { failures++; console.log( 'FAIL ' + message ); }
	return !! condition;
}

function logSize() {
	try { return fs.statSync( DEBUG_LOG ).size; } catch ( e ) { return 0; }
}

/** Lines added to debug.log since a given size. */
function logSince( size ) {
	try {
		const buffer = fs.readFileSync( DEBUG_LOG );
		return buffer.slice( size ).toString( 'utf8' ).split( '\n' ).filter( Boolean );
	} catch ( e ) {
		return [];
	}
}

async function start() {
	fs.mkdirSync( SHOTS, { recursive: true } );
	const browser = await chromium.launch();
	const context = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	const page = await context.newPage();
	const problems = [];
	page.on( 'pageerror', ( e ) => problems.push( 'page error: ' + e.message ) );
	page.on( 'console', ( m ) => { if ( 'error' === m.type() && ! /Failed to load resource/.test( m.text() ) ) { problems.push( 'console: ' + m.text() ); } } );
	page.on( 'response', ( r ) => { if ( r.status() >= 500 ) { problems.push( 'HTTP ' + r.status() + ' ' + r.url() ); } } );
	return { browser, context, page, problems };
}

/** Logs in to the license server, or to another site (such as the customer site) given its address. */
async function login( page, base = BASE ) {
	await page.goto( base + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', secret( 'ADMIN_PASSWORD' ) );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );
}

async function shot( page, name ) {
	await page.screenshot( { path: path.join( SHOTS, name + '.png' ), fullPage: true } );
}

function finish( env, logStart ) {
	// Not the plugin's doing: PHP deprecations inside WordPress core, and core's update checks that cannot reach
	// wordpress.org from a sandbox without internet access.
	const noise = [ /PHP Deprecated:.*(wp-includes|wp-admin)\//, /(wp_version_check|wp_update_plugins|wp_update_themes)\(\): An unexpected error/ ];
	const lines = logSince( logStart ).filter( ( line ) => ! noise.some( ( pattern ) => pattern.test( line ) ) );
	check( 0 === lines.length, 'debug.log has new lines:\n  ' + lines.slice( 0, 12 ).join( '\n  ' ) );
	check( 0 === env.problems.length, 'browser problems:\n  ' + env.problems.slice( 0, 12 ).join( '\n  ' ) );
	return env.browser.close().then( () => {
		console.log( ( failures ? failures + ' of ' + checks + ' checks failed' : 'All ' + checks + ' checks passed' ) + '. Screenshots: ' + SHOTS );
		process.exit( failures ? 1 : 0 );
	} );
}

/** Runs a query on the test database and returns the rows as text. */
function sql( query, database = 'wp_test' ) {
	return execFileSync( 'mariadb', [ '-N', '-r', database, '-e', query ], { encoding: 'utf8' } ).trim();
}

/** A product setting (post meta) of a license product. */
function meta( productId, key ) {
	return sql( "SELECT meta_value FROM wp_postmeta WHERE post_id=" + Number( productId ) + " AND meta_key='" + key + "' LIMIT 1" );
}

/** Creates and publishes a license product through the admin screens, and returns its ID. */
async function createProduct( page, title ) {
	await page.goto( BASE + '/wp-admin/post-new.php?post_type=wplit_product' );
	await page.fill( '#title', title );
	await Promise.all( [ page.waitForURL( /post\.php\?post=\d+/ ), page.click( '#publish' ) ] );
	return new URL( page.url() ).searchParams.get( 'post' );
}

module.exports = { BASE, CLIENT, check, createProduct, finish, login, logSize, logSince, meta, secret, shot, sql, start, ROOT };
