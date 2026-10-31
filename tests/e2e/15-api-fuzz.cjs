/**
 * Probes the REST API (and the 1.x endpoints) the way a hostile or broken client would: wrong types, huge values, unicode and
 * invalid bytes, repeated and array parameters, malformed bodies, wrong methods, key guessing, tampered download links, and
 * many requests at the same moment. Whatever is sent, the answer must be a clean JSON error (never a 5xx, never a PHP message,
 * never a stack trace or SQL), and nothing may be left in debug.log. No browser is needed.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, logSince, logSize, sql, summary } = require( './lib.cjs' );

const run = ( ...args ) => JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'php/licenses.php' ), ...args ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } ).trim() );
const API = BASE + '/wp-json/wplicense-it/v1';
const LEAK = /(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)[:\w ]*: |Stack trace|#\d+ \/|\bwpdb\b|You have an error in your SQL|SELECT .* FROM|mysqli|\/home\/|\/opt\/|\.php on line/i;
const clear_limit = () => sql( "DELETE FROM wp_options WHERE option_name LIKE '\\_transient%wplit\\_rl\\_%'" );

/** Sends a request and returns { status, text, json, headers }. A body can be an object (JSON), a string, or a Buffer. */
async function call( method, url, body, contentType ) {
	const options = { method, headers: {}, redirect: 'manual' };
	if ( undefined !== body ) {
		if ( 'object' === typeof body && ! Buffer.isBuffer( body ) ) {
			options.body = JSON.stringify( body );
			options.headers[ 'Content-Type' ] = contentType || 'application/json';
		} else {
			options.body = body;
			options.headers[ 'Content-Type' ] = contentType || 'application/x-www-form-urlencoded';
		}
	}
	const response = await fetch( url, options );
	const text = await response.text();
	let json = null;
	try { json = JSON.parse( text ); } catch ( e ) { /* not JSON */ }
	return { status: response.status, text, json, headers: response.headers };
}

const post = ( route, body, contentType ) => call( 'POST', API + route, body, contentType );

/** What a client may be told about a request that is wrong: a clean error with a code, and nothing about the server. */
function clean( result, label, allowed = [ 400, 403, 404, 429 ] ) {
	const ok = allowed.includes( result.status ) && result.json && false === result.json.success && 'string' === typeof result.json.code && ! LEAK.test( result.text );
	check( ok, label + ' -> expected a clean JSON error, got ' + result.status + ' ' + result.text.slice( 0, 200 ) );
	return ok;
}

( async () => {
	const logStart = logSize();
	const seed = run( 'seed' );
	// The documented limits (20 wrong keys in 15 minutes); the admin screens test changes them. The old values come back at the end.
	const setting = ( name ) => sql( "SELECT option_value FROM wp_options WHERE option_name='" + name + "'" );
	const saved = { wplit_rate_limit_failures: setting( 'wplit_rate_limit_failures' ), wplit_rate_limit_minutes: setting( 'wplit_rate_limit_minutes' ) };
	for ( const [ name, value ] of [ [ 'wplit_rate_limit_failures', 20 ], [ 'wplit_rate_limit_minutes', 15 ] ] ) {
		sql( "DELETE FROM wp_options WHERE option_name='" + name + "'" );
		sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" + name + "', '" + value + "', 'yes')" );
	}
	const { alpha, beta, keys } = seed;
	const GOOD = keys[ 1 ].key; // Alpha, 2 sites.
	const REVOKED = keys[ 3 ].key;
	const ROUTES = [ '/licenses/validate', '/licenses/activate', '/licenses/deactivate', '/updates/check' ];
	const valid = ( extra ) => ( { license_key: GOOD, product_id: alpha, site_url: 'https://fuzz.example.com', ...extra } );
	let sent = 0;

	// 1. The wrong type in every field of every route.
	const weird = [ null, true, false, 0, -1, 1e21, 1.5, 'abc', '', ' ', [], [ 'x' ], { a: 1 }, [ [ 'x' ] ], '1; DROP TABLE wp_wplit_licenses', '0x10', '1e3', '9999999999999999999999', -9007199254740993 ];
	for ( const route of ROUTES ) {
		for ( const field of [ 'license_key', 'product_id', 'site_url', 'email', 'current_version', 'product_version' ] ) {
			for ( const value of weird ) {
				if ( 0 === sent++ % 8 ) { clear_limit(); }
				const result = await post( route, valid( { [ field ]: value } ) );
				// A wrong type may also be harmless (an ignored extra field, or a field the route does not read), so only 5xx and leaks are failures here.
				const fine = result.status < 500 && ! LEAK.test( result.text ) && result.json && 'boolean' === typeof result.json.success;
				check( fine, route + ' ' + field + ' = ' + JSON.stringify( value ) + ' -> ' + result.status + ' ' + result.text.slice( 0, 160 ) );
			}
		}
	}
	// The whole body of the wrong type.
	for ( const body of [ [], 'plain text', 5, null, [ 1, 2, 3 ], '[]', '{}', '' ] ) {
		for ( const route of ROUTES ) {
			clear_limit();
			const result = await post( route, 'string' === typeof body ? body : JSON.stringify( body ), 'application/json' );
			check( result.status < 500 && ! LEAK.test( result.text ) && result.json && false === result.json.success, route + ' body ' + JSON.stringify( body ) + ' -> ' + result.status + ' ' + result.text.slice( 0, 160 ) );
		}
	}

	// 2. Missing and out of range values give the documented errors.
	clear_limit();
	for ( const route of ROUTES ) {
		clean( await post( route, {} ), route + ' with nothing', [ 400 ] );
	}
	clean( await post( '/licenses/validate', valid( { product_id: 0 } ) ), 'product_id 0', [ 400 ] );
	clean( await post( '/licenses/validate', valid( { product_id: -5 } ) ), 'product_id -5', [ 400 ] );
	clean( await post( '/licenses/validate', valid( { license_key: 'x'.repeat( 65 ) } ) ), 'a 65 character key', [ 400 ] );
	clean( await post( '/licenses/activate', valid( { site_url: 'not a url at all' } ) ), 'activate with a site that is not a URL', [ 400 ] );
	clean( await post( '/licenses/activate', valid( { site_url: 'ftp://fuzz.example.com' } ) ), 'activate with an ftp site', [ 400 ] );
	clean( await post( '/licenses/activate', valid( { site_url: 'javascript:alert(1)' } ) ), 'activate with a javascript: site', [ 400 ] );
	clean( await post( '/licenses/activate', valid( { site_url: 'https://fuzz.example.com/' + '../'.repeat(20) + 'etc/passwd' } ) ), 'activate with a ../ site path', [ 400 ] );

	// 3. Huge values.
	for ( const size of [ 1e4, 1e6, 8e6 ] ) {
		for ( const field of [ 'license_key', 'site_url', 'email', 'product_version', 'current_version' ] ) {
			clear_limit();
			const started = Date.now();
			const route = 'current_version' === field ? '/updates/check' : ( [ 'product_version', 'site_url' ].includes( field ) ? '/licenses/activate' : '/licenses/validate' );
			const result = await post( route, valid( { [ field ]: 'A'.repeat( size ) } ) );
			check( result.status < 500 && ! LEAK.test( result.text ) && ( result.json || 413 === result.status ), field + ' of ' + size + ' characters on ' + route + ' -> ' + result.status + ' ' + result.text.slice( 0, 120 ) );
			check( Date.now() - started < 8000, field + ' of ' + size + ' characters answered in under 8 seconds (took ' + ( Date.now() - started ) + ' ms)' );
			if ( 'license_key' === field || 'site_url' === field ) {
				check( 400 === result.status, field + ' of ' + size + ' characters is a 400, got ' + result.status );
			}
		}
	}
	// A very deep and a very wide JSON body.
	let deep = '1';
	for ( let i = 0; i < 3000; i++ ) { deep = '[' + deep + ']'; }
	let result = await post( '/licenses/validate', '{"license_key":' + deep + ',"product_id":1}', 'application/json' );
	check( result.status < 500 && ! LEAK.test( result.text ), 'a JSON body nested 3000 deep -> ' + result.status + ' ' + result.text.slice( 0, 120 ) );
	const wide = {};
	for ( let i = 0; i < 20000; i++ ) { wide[ 'k' + i ] = i; }
	result = await post( '/licenses/validate', { ...valid(), ...wide } );
	check( result.status < 500 && ! LEAK.test( result.text ), 'a JSON body with 20,000 fields -> ' + result.status + ' ' + result.text.slice( 0, 120 ) );

	// 4. Unicode and invalid bytes.
	const texts = [ '\u{1F680}\u{1F512}', '‮evil‬', 'é́́́', '\u0000', 'a\u0000b', '￿', '\uD800', '\u{10FFFF}', 'İİİ', 'ǅ', '＜script＞', 'ＧＯＯＤ', '​', '%00', '%', '%zz' ];
	for ( const text of texts ) {
		for ( const field of [ 'license_key', 'site_url', 'email', 'product_version' ] ) {
			clear_limit();
			const route = 'product_version' === field ? '/licenses/activate' : '/licenses/validate';
			const r = await post( route, valid( { [ field ]: text } ) );
			check( r.status < 500 && ! LEAK.test( r.text ) && r.json, JSON.stringify( text ) + ' as ' + field + ' -> ' + r.status + ' ' + r.text.slice( 0, 120 ) );
		}
	}
	// Bytes that are not UTF-8, in a form body and in the query string.
	for ( const raw of [ '%FF%FE', '%C0%AF', '%ED%A0%80', '%F8%88%80%80%80', '%80', 'abc%FFdef' ] ) {
		clear_limit();
		for ( const field of [ 'license_key', 'site_url', 'email' ] ) {
			const r = await post( '/licenses/validate', 'product_id=' + alpha + '&license_key=' + GOOD + '&' + field + '=' + raw );
			check( r.status < 500 && ! LEAK.test( r.text ) && r.json, 'invalid bytes ' + raw + ' as ' + field + ' (form) -> ' + r.status + ' ' + r.text.slice( 0, 120 ) );
		}
		const q = await call( 'POST', API + '/licenses/validate?license_key=' + raw + '&product_id=' + alpha );
		check( q.status < 500 && ! LEAK.test( q.text ) && q.json, 'invalid bytes ' + raw + ' (query) -> ' + q.status + ' ' + q.text.slice( 0, 120 ) );
	}
	// A raw invalid byte inside a JSON string.
	result = await post( '/licenses/validate', Buffer.concat( [ Buffer.from( '{"product_id":' + alpha + ',"license_key":"ab' ), Buffer.from( [ 0xff, 0xfe ] ), Buffer.from( '"}' ) ] ), 'application/json' );
	check( result.status < 500 && ! LEAK.test( result.text ) && result.json, 'invalid bytes inside a JSON string -> ' + result.status + ' ' + result.text.slice( 0, 120 ) );

	// A site address with odd characters must not be stored as sent.
	clear_limit();
	for ( const site of [ 'https://fuzz.example.com/<script>alert(1)</script>', 'https://fuzz.example.com/a b', 'https://fuzz.example.com/\u{1F680}', 'https://user:pass@fuzz.example.com', 'https://fuzz.example.com:99999999', 'https://[::1]/', 'https://FUZZ.EXAMPLE.COM/%2e%2e/' ] ) {
		const r = await post( '/licenses/activate', valid( { site_url: site } ) );
		check( r.status < 500 && ! LEAK.test( r.text ) && r.json, 'activate ' + site + ' -> ' + r.status + ' ' + r.text.slice( 0, 120 ) );
	}
	const stored = sql( 'SELECT site FROM wp_wplit_activations' ).split( '\n' ).filter( Boolean );
	check( ! stored.some( ( s ) => /[<>"' \u0000-\u001f]/.test( s ) ), 'no site stored with markup, spaces or control characters: ' + stored.join( ' | ' ).slice( 0, 300 ) );

	// 5. Repeated and array parameters, and the same field in the query string and the body.
	clear_limit();
	for ( const body of [ 'license_key=a&license_key=b&product_id=1', 'license_key[]=a&license_key[]=b&product_id=1', 'license_key[a]=b&product_id=1', 'product_id[]=1&license_key=x', 'product_id=1&product_id=2&license_key=x', 'license_key=&license_key=' + GOOD + '&product_id=' + alpha ] ) {
		const r = await post( '/licenses/validate', body );
		check( r.status < 500 && ! LEAK.test( r.text ) && r.json && 'boolean' === typeof r.json.success, 'form body ' + body + ' -> ' + r.status + ' ' + r.text.slice( 0, 120 ) );
	}
	// An array in the field must never be accepted as a key.
	result = await post( '/licenses/validate', 'license_key[]=' + GOOD + '&product_id=' + alpha );
	check( false === ( result.json || {} ).success, 'a key sent as an array is not accepted: ' + result.text.slice( 0, 120 ) );
	// Query string against body: the body value must not be overridden by something that would let a wrong key through.
	result = await call( 'POST', API + '/licenses/validate?license_key=' + GOOD + '&product_id=' + alpha, 'license_key=wrong&product_id=' + alpha );
	check( result.status < 500 && result.json, 'the key in the query string and a different one in the body -> ' + result.status + ' ' + result.text.slice( 0, 120 ) );
	console.log( '  note: key in the URL and a wrong key in the body answers ' + result.status + ' ' + ( result.json && result.json.code ) );
	result = await call( 'POST', API + '/licenses/validate?license_key=' + GOOD + '&product_id=' + alpha );
	console.log( '  note: key only in the URL query string answers ' + result.status + ' ' + ( result.json && result.json.code ) );

	// 6. Malformed bodies, wrong content types, wrong methods, unknown routes.
	clear_limit();
	for ( const [ body, type ] of [ [ '{"license_key":', 'application/json' ], [ '{', 'application/json' ], [ '{"license_key":"x",}', 'application/json' ], [ '\u0000\u0000', 'application/json' ], [ '<xml/>', 'application/xml' ], [ 'license_key=x', 'text/plain' ], [ '--x\r\n', 'multipart/form-data; boundary=x' ], [ 'a=b', 'application/octet-stream' ], [ '{}', 'application/json; charset=utf-7' ] ] ) {
		const r = await post( '/licenses/validate', body, type );
		check( r.status < 500 && ! LEAK.test( r.text ) && r.json, 'body ' + JSON.stringify( body ).slice( 0, 30 ) + ' as ' + type + ' -> ' + r.status + ' ' + r.text.slice( 0, 120 ) );
	}
	for ( const route of ROUTES ) {
		for ( const method of [ 'GET', 'PUT', 'DELETE', 'PATCH' ] ) {
			const r = await call( method, API + route + '?license_key=' + GOOD + '&product_id=' + alpha );
			check( r.status < 500 && ! LEAK.test( r.text ) && ! ( r.json && true === r.json.success ), method + ' ' + route + ' is refused, got ' + r.status + ' ' + r.text.slice( 0, 100 ) );
		}
	}
	for ( const route of [ '/nothing', '/licenses', '/licenses/', '/licenses/validate/extra', '/licenses/%00', '/../../wp/v2/users', '/licenses/validate%2f..%2f..' ] ) {
		const r = await post( route, valid() );
		check( r.status < 500 && ! LEAK.test( r.text ) && ! ( r.json && true === r.json.success ), 'POST ' + route + ' is not a working route, got ' + r.status + ' ' + r.text.slice( 0, 100 ) );
	}
	result = await call( 'OPTIONS', API + '/licenses/validate' );
	check( result.status < 500, 'OPTIONS on a route -> ' + result.status );

	// 7. The API says the same thing about a wrong key, a wrong product and a wrong email.
	clear_limit();
	const wrong = [
		await post( '/licenses/validate', valid( { license_key: 'NOPE-NOPE-NOPE-NOPE' } ) ),
		await post( '/licenses/validate', valid( { product_id: beta } ) ),
		await post( '/licenses/validate', valid( { email: 'someone-else@example.com' } ) ),
		await post( '/licenses/validate', valid( { product_id: 999999 } ) ),
	];
	check( wrong.every( ( r ) => 404 === r.status && 'not_found' === r.json.code && r.json.message === wrong[ 0 ].json.message ), 'wrong key, wrong product, wrong email and unknown product look the same: ' + wrong.map( ( r ) => r.status + ' ' + r.text ).join( ' / ' ) );
	check( wrong.every( ( r ) => r.text === wrong[ 0 ].text ), 'and the bodies are byte for byte equal' );

	// 8. Keys that should not match: case, padding, wildcards, partial keys, injection.
	clear_limit();
	const nope = [ GOOD.toLowerCase(), GOOD.slice( 0, -1 ), GOOD.slice( 1 ), GOOD + 'X', GOOD.replace( /.$/, '%' ), '%', '_', '%' + GOOD.slice( 4, 10 ) + '%', GOOD.slice( 0, 6 ) + '%', "' OR '1'='1", "' OR 1=1 -- ", GOOD + "' --", '" OR ""="', '\\', GOOD.replace( /-/g, '' ), '*', '.*', '^.*$' ];
	for ( const key of nope ) {
		clear_limit();
		const r = await post( '/licenses/validate', valid( { license_key: key } ) );
		// A key that differs only in case must not match either (keys are compared exactly).
		check( 404 === r.status && 'not_found' === r.json.code || 400 === r.status, 'key ' + JSON.stringify( key ) + ' is not the license -> ' + r.status + ' ' + r.text.slice( 0, 100 ) );
	}
	// The key itself with spaces around it is the same key (it is trimmed).
	clear_limit();
	result = await post( '/licenses/validate', valid( { license_key: '  ' + GOOD + '\n' } ) );
	check( 200 === result.status && true === result.json.success, 'the key with spaces around it works: ' + result.status + ' ' + result.text.slice( 0, 100 ) );

	// 9. Guessing: 20 wrong keys block that client, on every route; expired/revoked answers do not count.
	clear_limit();
	for ( let i = 0; i < 40; i++ ) {
		const r = await post( '/licenses/validate', valid( { license_key: REVOKED } ) );
		if ( 403 !== r.status ) { check( false, 'revoked key answered ' + r.status + ' on attempt ' + i ); break; }
	}
	result = await post( '/licenses/validate', valid() );
	check( 200 === result.status, 'forty answers about a revoked license did not block the client: ' + result.status );
	let blockedAt = 0;
	for ( let i = 1; i <= 40; i++ ) {
		const r = await post( '/licenses/validate', valid( { license_key: 'GUESS-' + i } ) );
		if ( 429 === r.status ) { blockedAt = i; check( r.headers.get( 'retry-after' ) > 0, 'the 429 has a Retry-After header' ); break; }
	}
	check( blockedAt >= 20 && blockedAt <= 22, 'the client is blocked after about 20 wrong keys, was blocked at ' + blockedAt );
	for ( const route of ROUTES ) {
		const r = await post( route, valid() );
		check( 429 === r.status && 'rate_limited' === r.json.code, route + ' is blocked while the client is blocked: ' + r.status + ' ' + r.text.slice( 0, 100 ) );
	}
	check( 429 === ( await call( 'GET', API + '/download?token=x' ) ).status, 'the download route is blocked too' );
	clear_limit();
	check( 200 === ( await post( '/licenses/validate', valid() ) ).status, 'the right key works again once the block is gone' );

	// 10. Download links: a real one, then tampered ones.
	execFileSync( 'php', [ '-d', 'memory_limit=512M', '-r', `
		define( "WP_ADMIN", true );
		require "${ ROOT }/wordpress/wp-load.php";
		$uploads = wp_upload_dir();
		$rel = "wplit-files/p${ alpha }/v1/" . bin2hex( random_bytes( 16 ) ) . "/fuzz.zip";
		wp_mkdir_p( dirname( $uploads["basedir"] . "/" . $rel ) );
		$zip = new ZipArchive();
		$zip->open( $uploads["basedir"] . "/" . $rel, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( "fuzz/fuzz.php", "<?php\\n/* Plugin Name: Fuzz */\\n" );
		$zip->close();
		update_post_meta( ${ alpha }, "file_dir_path", $rel );
		update_post_meta( ${ alpha }, "wplit_product_version", "1.0.0" );
	` ], { encoding: 'utf8' } );
	clear_limit();
	const info = await post( '/updates/check', valid( { current_version: '0.9' } ) );
	check( 200 === info.status && info.json.download_url, 'a valid license gets a download link: ' + info.status + ' ' + info.text.slice( 0, 160 ) );
	const link = info.json.download_url;
	const token = new URL( link ).searchParams.get( 'token' );
	const real = await fetch( link );
	const bytes = Buffer.from( await real.arrayBuffer() );
	check( 200 === real.status && 'PK' === bytes.subarray( 0, 2 ).toString(), 'the real link serves a zip: ' + real.status );
	const tampered = [ '', 'x', '.', '..', token + 'x', token.slice( 0, -1 ), token.slice( 1 ), token.replace( /.$/, token.endsWith( 'A' ) ? 'B' : 'A' ), token.split( '.' )[ 0 ], '.' + token.split( '.' )[ 1 ], token.split( '.' ).reverse().join( '.' ), token + '.' + token, token.toUpperCase(), token.split( '.' )[ 0 ] + '.' + 'A'.repeat( 43 ), 'A'.repeat( 5000 ), '../../../../etc/passwd', '%00', '<script>', "' OR 1=1 --" ];
	for ( const t of tampered ) {
		clear_limit();
		const r = await call( 'GET', API + '/download?token=' + encodeURIComponent( t ) );
		check( r.status >= 400 && r.status < 500 && ! r.text.startsWith( 'PK' ) && ! LEAK.test( r.text ), 'token ' + JSON.stringify( t.slice( 0, 40 ) ) + ' is refused with a 4xx: ' + r.status + ' ' + r.text.slice( 0, 100 ) );
	}
	// The token with the payload changed (another license or product ID) but the old signature.
	const [ payload, signature ] = token.split( '.' );
	const decoded = Buffer.from( payload.replace( /-/g, '+' ).replace( /_/g, '/' ), 'base64' ).toString();
	console.log( '  note: token payload looks like ' + decoded.replace( /\d{5,}/g, 'N' ).slice( 0, 80 ) );
	const forged = Buffer.from( decoded.replace( /\d+/, ( n ) => String( Number( n ) + 1 ) ) ).toString( 'base64' ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	clear_limit();
	result = await call( 'GET', API + '/download?token=' + forged + '.' + signature );
	check( result.status >= 400 && result.status < 500 && ! result.text.startsWith( 'PK' ), 'a token with changed contents and the old signature is refused: ' + result.status );
	// Repeated and array token parameters.
	for ( const q of [ 'token=a&token=' + token, 'token[]=' + token, 'token[a]=' + token, 'token=' + token + '&token=x' ] ) {
		clear_limit();
		const r = await call( 'GET', API + '/download?' + q );
		check( r.status < 500 && ! LEAK.test( r.text.slice( 0, 400 ) ), 'download with ' + q.slice( 0, 30 ) + ' -> ' + r.status );
	}
	// A revoked license: the link already handed out stops working, and a new one is refused.
	sql( "UPDATE wp_wplit_licenses SET status='revoked' WHERE id=" + keys[ 1 ].id );
	clear_limit();
	result = await call( 'GET', link );
	check( 403 === result.status && ! result.text.startsWith( 'PK' ), 'the link of a revoked license stops working: ' + result.status );
	check( 403 === ( await post( '/updates/check', valid( { current_version: '0.9' } ) ) ).status, 'and a new link is refused' );
	sql( "UPDATE wp_wplit_licenses SET status='active' WHERE id=" + keys[ 1 ].id );

	// 11. Many requests at the same moment.
	clear_limit();
	const many = await Promise.all( Array.from( { length: 60 }, () => post( '/licenses/validate', valid() ) ) );
	check( many.every( ( r ) => 200 === r.status && true === r.json.success ), '60 validations at once all succeed: ' + [ ...new Set( many.map( ( r ) => r.status ) ) ].join( ',' ) );

	// Ten requests activate the same site at once: one slot is used.
	sql( 'DELETE FROM wp_wplit_activations WHERE license_id=' + keys[ 1 ].id );
	clear_limit();
	const same = await Promise.all( Array.from( { length: 12 }, () => post( '/licenses/activate', valid( { site_url: 'https://race-one.example.com' } ) ) ) );
	check( same.every( ( r ) => 200 === r.status ), 'twelve activations of one site at once all answer 200: ' + same.map( ( r ) => r.status + (r.json ? r.json.code : '') ).join( ' ' ) );
	check( '1' === sql( "SELECT COUNT(*) FROM wp_wplit_activations WHERE license_id=" + keys[ 1 ].id + " AND status='active'" ), 'and the site uses exactly one slot, found ' + sql( "SELECT COUNT(*) FROM wp_wplit_activations WHERE license_id=" + keys[ 1 ].id ) );

	// Twelve different sites at once on a license with 2 slots: no more than 2 get in.
	sql( 'DELETE FROM wp_wplit_activations WHERE license_id=' + keys[ 1 ].id );
	clear_limit();
	const limit = Number( sql( 'SELECT activation_limit FROM wp_wplit_licenses WHERE id=' + keys[ 1 ].id ) );
	const sites = await Promise.all( Array.from( { length: 12 }, ( _, i ) => post( '/licenses/activate', valid( { site_url: 'https://race-' + i + '.example.com' } ) ) ) );
	const used = Number( sql( "SELECT COUNT(*) FROM wp_wplit_activations WHERE license_id=" + keys[ 1 ].id + " AND status='active'" ) );
	check( used <= limit, 'twelve sites at once on a license with ' + limit + ' slots: ' + used + ' active (answers ' + sites.map( ( r ) => r.json && r.json.code ).join( ' ' ) + ')' );
	check( sites.every( ( r ) => r.status < 500 ), 'and none of them was a server error' );
	sql( 'DELETE FROM wp_wplit_activations WHERE license_id=' + keys[ 1 ].id );

	// 12. The 1.x endpoints.
	clear_limit();
	const legacy = ( endpoint, query ) => call( 'GET', BASE + '/api/wplicense-it-api/v1/' + endpoint + '/?' + query );
	const queries = [ '', 'p=&e=&l=&k=', 'p=1&e=x&l=x&k=x', 'p[]=1&e[]=x&l[]=x&k[]=x', 'p=' + 'A'.repeat( 8e3 ), 'l=' + 'A'.repeat( 5e3 ) + '&k=x&p=1', "p=1' OR 1=1 --&l=x&k=x", 'p=%FF%FE&l=%FF&k=%FF&e=%FF', 'p=-1&l=%00&k=%00', 'p=1&p=2&l=a&l=b&k=a&k=b', 'p=99999999999999999999&l=x&k=x', 'p=1.5&l=%25&k=%25', 'p=' + alpha + '&l=' + GOOD + '&k=wrong' ];
	for ( const endpoint of [ 'info', 'get', 'status' ] ) {
		for ( const query of queries ) {
			clear_limit();
			const r = await legacy( endpoint, query );
			check( r.status < 500 && ! LEAK.test( r.text ) && ! r.text.startsWith( 'PK' ), '1.x ' + endpoint + ' ?' + query.slice( 0, 40 ) + ' -> ' + r.status + ' ' + r.text.slice( 0, 100 ) );
		}
	}
	// A URL of a megabyte is refused by any web server; what matters is that it is, and that the site is fine afterwards.
	try {
		const r = await legacy( 'info', 'p=' + 'A'.repeat( 1e6 ) );
		check( r.status < 500 || 414 === r.status, 'a one megabyte 1.x URL is not a server error: ' + r.status );
	} catch ( e ) {
		console.log( '  note: a one megabyte URL is refused by closing the connection (' + ( e.cause && e.cause.code ) + ')' );
	}
	for ( const endpoint of [ 'nothing', 'INFO', 'info/extra', '%00', '..' ] ) {
		const r = await call( 'GET', BASE + '/api/wplicense-it-api/v1/' + endpoint + '/?l=' + GOOD );
		check( r.status < 500 && ! LEAK.test( r.text ) && ! r.text.startsWith( 'PK' ), '1.x /' + endpoint + ' is not an endpoint -> ' + r.status );
	}

	// 13. After all of that: the site still works, the license rows are whole, and nothing was written to the log.
	clear_limit();
	result = await post( '/licenses/validate', valid() );
	check( 200 === result.status && true === result.json.success, 'the API still answers a valid request: ' + result.status );
	check( '45' === sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ), 'no license was added or removed: ' + sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) );
	check( 'active' === sql( 'SELECT status FROM wp_wplit_licenses WHERE id=' + keys[ 1 ].id ), 'the license is still active' );
	check( '0' === sql( 'SELECT COUNT(*) FROM wp_wplit_licenses WHERE license_key LIKE "%<%" OR email LIKE "%<%"' ), 'no row holds markup from the requests' );

	const noise = [ /PHP Deprecated:.*(wp-includes|wp-admin)\//, /(wp_version_check|wp_update_plugins|wp_update_themes|get_core_checksums)\(\): An unexpected error/, /WPLicense It API error:/ ];
	const lines = logSince( logStart ).filter( ( line ) => ! noise.some( ( pattern ) => pattern.test( line ) ) );
	check( 0 === lines.length, 'debug.log has new lines:\n  ' + lines.slice( 0, 12 ).join( '\n  ' ) );
	const errors = logSince( logStart ).filter( ( line ) => /WPLicense It API error:/.test( line ) );
	check( 0 === errors.length, 'no request made the API catch an unexpected error (that would be a 500):\n  ' + errors.slice( 0, 8 ).join( '\n  ' ) );

	for ( const [ name, value ] of Object.entries( saved ) ) {
		sql( "DELETE FROM wp_options WHERE option_name='" + name + "'" );
		if ( '' !== value ) { sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" + name + "', '" + value + "', 'yes')" ); }
	}
	sql( "UPDATE wp_postmeta SET meta_value='' WHERE post_id=" + alpha + " AND meta_key='file_dir_path'" );
	summary();
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
