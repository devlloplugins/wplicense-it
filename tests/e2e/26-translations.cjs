/**
 * Translations load (card 92). A German translation of a few strings is made from languages/wplicense-it.pot, compiled with
 * WP-CLI into the plugin's languages folder (where a translator's file goes), and the admin user switches to German: the
 * menu (registered with the product type) and the Licensing settings screen are in German, and nothing is logged about
 * translations loading too early. Everything is put back afterwards.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start } = require( './lib.cjs' );

const LANG = path.join( __dirname, '..', '..', 'languages' );
const PO = path.join( LANG, 'wplicense-it-de_DE.po' );
const MO = path.join( LANG, 'wplicense-it-de_DE.mo' );
const WORDS = {
	'License Products': 'Lizenzprodukte',
	'Licensing settings': 'Lizenzeinstellungen',
	'Expiry reminders': 'Ablauf-Erinnerungen',
	'API protection': 'API-Schutz',
};

function cleanUp() {
	for ( const file of [ PO, MO ] ) { fs.rmSync( file, { force: true } ); }
	sql( "DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='locale'" );
}

( async () => {
	const logStart = logSize();
	const pot = fs.readFileSync( path.join( LANG, 'wplicense-it.pot' ), 'utf8' );
	for ( const msgid of Object.keys( WORDS ) ) {
		check( pot.includes( 'msgid "' + msgid + '"' ), 'the .pot has "' + msgid + '"' );
	}

	// A small German translation, compiled the way a translator's .po is.
	const header = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Language: de_DE\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n\n';
	fs.writeFileSync( PO, header + Object.entries( WORDS ).map( ( [ en, de ] ) => 'msgid "' + en + '"\nmsgstr "' + de + '"\n' ).join( '\n' ) );
	execFileSync( 'php', [ path.join( ROOT, 'wp-cli.phar' ), '--allow-root', 'i18n', 'make-mo', PO, LANG ] );
	check( fs.existsSync( MO ), 'the translation compiles to ' + path.basename( MO ) );

	sql( "DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='locale'" );
	sql( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'locale', 'de_DE')" );

	const env = await start();
	const page = env.page;
	try {
		await login( page );
		await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings' );
		await shot( page, '46-translated-settings' );
		const body = await page.locator( 'body' ).innerText();
		check( /Lizenzeinstellungen/.test( body ), 'the settings screen title is German' );
		check( /Ablauf-Erinnerungen/.test( body ) && /API-Schutz/.test( body ), 'and so are its sections' );
		check( /Lizenzprodukte/.test( await page.locator( '#adminmenu' ).innerText() ), 'the menu of the product type (registered at init) is German too' );
		check( ! /Licensing settings/.test( body ), 'the English title is gone' );
	} finally {
		cleanUp();
	}

	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings' );
	check( /Licensing settings/.test( await page.locator( 'body' ).innerText() ), 'back in English once the translation and the user language are removed' );

	// finish() also fails on any new debug.log line, such as WordPress's notice about translations loading too early.
	await finish( env, logStart );
} )().catch( ( error ) => { cleanUp(); console.error( error ); process.exit( 1 ); } );
