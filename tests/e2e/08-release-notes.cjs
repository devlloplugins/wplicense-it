/**
 * Card 128: the notes of any release can be edited from the Release history, not only at upload.
 */
const fixtures = require( './fixtures.cjs' );
const { BASE, check, createProduct, finish, login, logSize, meta, shot, start } = require( './lib.cjs' );

async function openTab( page, tab ) { await page.click( '#wplit-tabs [data-tab="' + tab + '"]' ); }
async function save( page ) { await Promise.all( [ page.waitForURL( /post\.php\?post=\d+/ ), page.click( '#publish' ) ] ); }
const row = ( page, version ) => page.locator( '#wplit_release_history tbody tr', { hasText: version } ).first();

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;
	await login( page );
	const id = await createProduct( page, 'Notes Test' );

	for ( const [ version, notes ] of [ [ '1.0.0', 'First release.' ], [ '1.1.0', 'Second release.' ] ] ) {
		await openTab( page, 'releases' );
		await page.setInputFiles( '#wplit_product_file_upload', fixtures.samplePlugin( version, '= ' + version + ' =\n* ' + notes ) );
		await page.fill( '#wplit_release_notes', notes );
		await save( page );
	}
	await openTab( page, 'releases' );
	check( ( await page.locator( '#wplit_release_history tbody tr' ).count() ) === 2, 'two releases are in the history' );
	check( meta( id, 'wplit_changelog' ) === 'Second release.', 'the current notes are those of 1.1.0, got ' + meta( id, 'wplit_changelog' ) );

	// Edit the older release.
	const older = row( page, '1.0.0' );
	check( await older.locator( '.wplit-notes-editor' ).isHidden(), 'the editor starts closed' );
	await older.locator( '.wplit-notes-edit' ).click();
	check( await older.locator( '.wplit-notes-editor' ).isVisible(), 'Edit notes opens the editor' );
	check( ( await older.locator( 'textarea' ).inputValue() ) === 'First release.', 'the editor holds the existing notes' );
	await shot( page, '08-notes-editor' );

	// Cancel changes nothing.
	await older.locator( 'textarea' ).fill( 'Throwaway text' );
	await older.locator( '.wplit-notes-cancel' ).click();
	check( await older.locator( '.wplit-notes-editor' ).isHidden(), 'Cancel closes the editor' );

	// Save.
	await older.locator( '.wplit-notes-edit' ).click();
	await older.locator( 'textarea' ).fill( 'Fixed the login bug.\n\n<b>Added</b> a dark mode.' );
	await Promise.all( [ page.waitForURL( /post\.php\?post=\d+/ ), older.locator( '.wplit-notes-save' ).click() ] );
	check( /The release notes were saved\./.test( ( await page.locator( '.notice' ).allInnerTexts() ).join( '\n' ) ), 'a notice says the notes were saved' );
	check( page.url().includes( '#wplit-releases' ) || await page.locator( '#wplit_release_history' ).isVisible(), 'we land back on the Releases tab' );

	const after = row( page, '1.0.0' );
	const shown = await after.innerText();
	check( /Fixed the login bug\./.test( shown ) && /Added a dark mode\./.test( shown ), 'the new notes show in the history: ' + shown.slice( 0, 200 ) );
	check( ! /<b>/.test( shown ), 'markup in the notes is stripped, not shown' );
	check( /First release|Throwaway/.test( shown ) === false, 'the old text and the cancelled text are gone' );
	check( ( await row( page, '1.1.0' ).innerText() ).includes( 'Second release.' ), 'the other release keeps its notes' );
	check( meta( id, 'wplit_changelog' ) === 'Second release.', 'customers still get the current release\'s notes, got ' + meta( id, 'wplit_changelog' ) );

	// Editing the current release changes what customers get.
	const current = row( page, '1.1.0' );
	await current.locator( '.wplit-notes-edit' ).click();
	await current.locator( 'textarea' ).fill( 'Second release, reworded.' );
	await Promise.all( [ page.waitForURL( /post\.php\?post=\d+/ ), current.locator( '.wplit-notes-save' ).click() ] );
	check( meta( id, 'wplit_changelog' ) === 'Second release, reworded.', 'editing the current notes updates the customer-facing changelog, got ' + meta( id, 'wplit_changelog' ) );

	// Clearing works.
	const first = row( page, '1.0.0' );
	await first.locator( '.wplit-notes-edit' ).click();
	await first.locator( 'textarea' ).fill( '' );
	await Promise.all( [ page.waitForURL( /post\.php\?post=\d+/ ), first.locator( '.wplit-notes-save' ).click() ] );
	check( ( await row( page, '1.0.0' ).innerText() ).includes( '—' ), 'an emptied note shows a dash' );

	// The link itself, opened directly, changes nothing and does not wipe notes.
	const href = await row( page, '1.1.0' ).locator( '.wplit-notes-editor' ).getAttribute( 'data-url' );
	const direct = await page.request.get( href.startsWith( 'http' ) ? href : BASE + href );
	check( direct.status() === 405, 'opening the save link directly is refused with 405, got ' + direct.status() );
	await page.reload();
	await openTab( page, 'releases' );
	check( ( await row( page, '1.1.0' ).innerText() ).includes( 'reworded' ), 'the direct request did not change the notes' );

	// A tampered link fails.
	const bad = await page.request.post( ( href.startsWith( 'http' ) ? '' : BASE ) + href.replace( /_wpnonce=[a-f0-9]+/, '_wpnonce=0000000000' ), { form: { notes: 'hacked' } } );
	check( bad.status() === 403, 'a wrong nonce is refused with 403, got ' + bad.status() );

	// The editor limits the length.
	await row( page, '1.0.0' ).locator( '.wplit-notes-edit' ).click();
	check( ( await row( page, '1.0.0' ).locator( 'textarea' ).getAttribute( 'maxlength' ) ) === '5000', 'the editor limits the text to 5000 characters' );

	finish( env, logStart );
} )();
