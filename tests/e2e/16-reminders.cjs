/**
 * Expiry reminder emails: the settings, what is sent and to whom, that nothing is sent twice, that a renewal starts a new
 * cycle, and that the renewal address in the email works. Mail is kept by the test site's mail capture (see
 * tests/env/setup-woocommerce.sh), so nothing leaves the sandbox. The daily job is run through the real WordPress cron request.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, ROOT, check, finish, login, logSize, shot, sql, start, woo } = require( './lib.cjs' );

const helper = ( ...args ) => JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'php/reminders.php' ), ...args ], { encoding: 'utf8', env: { ...process.env, WPLIT_ENV_ROOT: ROOT } } ).trim() );
const SETTINGS = BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings';
const mails = () => woo( { op: 'mail' } ).mail;

( async () => {
	const logStart = logSize();
	woo( { op: 'clear_mail' } );

	const saved = { enabled: sql( "SELECT option_value FROM wp_options WHERE option_name='wplit_reminders_enabled'" ), days: sql( "SELECT option_value FROM wp_options WHERE option_name='wplit_reminder_days'" ), url: sql( "SELECT option_value FROM wp_options WHERE option_name='wplit_reminder_url'" ) };
	sql( "DELETE FROM wp_options WHERE option_name IN ('wplit_reminders_enabled','wplit_reminder_days','wplit_reminder_url')" );

	const stamp = Date.now();
	const accountEmail = 'account-customer-' + stamp + '@example.com';
	const customer = woo( { op: 'create_user', login: 'reminded' + stamp, email: accountEmail, password: 'pw-reminded-1' } );
	check( customer.user_id > 0, 'the customer account was made: ' + JSON.stringify( customer ) );
	const seed = helper( 'seed', String( customer.user_id ), accountEmail );
	const L = seed.licenses;

	const env = await start();
	const page = env.page;
	await login( page );

	// 1. The settings: off by default, with the days 30 and 7.
	await page.goto( SETTINGS );
	await shot( page, '16-settings-default' );
	const box = page.locator( 'input[name="wplit_reminders_enabled"]' );
	check( await box.count() === 1 && ! ( await box.isChecked() ), 'reminders are off until the owner turns them on' );
	check( ( await page.inputValue( '#wplit_reminder_days' ) ) === '30, 7', 'the default days are 30, 7, got ' + await page.inputValue( '#wplit_reminder_days' ) );
	check( ( await page.inputValue( '#wplit_reminder_url' ) ) === '', 'no renewal address is set by default' );
	check( /Off until you turn it on/.test( await page.locator( 'body' ).innerText() ), 'the screen says nothing is sent until it is turned on' );

	// 2. Off: the daily job emails nobody.
	helper( 'run' );
	check( mails().length === 0, 'with reminders off the daily job sends nothing, sent ' + mails().length );

	// 3. Junk in the days field is cleaned when saved.
	await page.fill( '#wplit_reminder_days', 'abc, 0, -4, 7; 30, 30, 999' );
	await page.check( 'input[name="wplit_reminders_enabled"]' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit' ) ] );
	check( ( await page.inputValue( '#wplit_reminder_days' ) ) === '30, 7', 'junk is cleaned out of the days when saved, got ' + await page.inputValue( '#wplit_reminder_days' ) );
	check( await page.locator( 'input[name="wplit_reminders_enabled"]' ).isChecked(), 'turning reminders on is saved' );

	// 4. On: the daily job, run by the real cron request.
	woo( { op: 'clear_mail' } );
	helper( 'due' );
	const cron = await page.request.get( BASE + '/wp-cron.php' );
	check( cron.status() === 200, 'wp-cron.php runs, got ' + cron.status() );
	await page.waitForTimeout( 1500 );
	let sent = mails();
	check( sent.length === 3, 'the daily job sent three emails (account, guest, later), sent ' + sent.length + ': ' + sent.map( ( m ) => m.to ).join( ', ' ) );
	const to = ( who ) => sent.find( ( m ) => String( [].concat( m.to ) ).includes( who ) );
	check( !! to( 'account-customer' ) && !! to( 'guest-customer' ) && !! to( 'later-customer' ), 'each license that is inside a window got its email' );
	check( ! to( 'far-customer' ), 'a license that expires in 100 days got nothing' );
	check( ! to( 'lifetime-customer' ), 'a license that never expires got nothing' );
	check( ! to( 'revoked-customer' ), 'a revoked license got nothing' );
	const account = to( 'account-customer' ) || {};
	const guest = to( 'guest-customer' ) || {};
	check( /expires in 2[56] days/.test( account.subject || '' ) && /Reminder Product/.test( account.subject || '' ), 'the subject names the product and the days: ' + account.subject );
	check( /expires in [56] days/.test( guest.subject || '' ), 'the guest license 5 days away: ' + guest.subject );
	for ( const mail of sent ) {
		check( ! Object.values( L ).some( ( l ) => String( mail.message ).includes( l.key ) ), 'the email for ' + mail.to + ' does not contain the license key' );
		check( /Reminder Product/.test( mail.message ) && /no longer receive updates/.test( mail.message ), 'the email for ' + mail.to + ' says which product and what expiry means' );
	}
	const accountUrl = ( ( account.message || '' ).match( /https?:\/\/\S+/ ) || [ '' ] )[ 0 ];
	const guestUrl = ( ( guest.message || '' ).match( /https?:\/\/\S+/ ) || [ '' ] )[ 0 ];
	check( /\/my-account\/licenses\/?$/.test( accountUrl ), 'a license with an account sends the customer to My Account > Licenses, got ' + accountUrl );
	check( /^https?:\/\/127\.0\.0\.1:8080\/$/.test( guestUrl ), 'a license without an account sends the customer to the home page, got ' + guestUrl );

	// 5. The address in the email works: the customer logs in and sees the license.
	await page.context().clearCookies();
	await page.goto( accountUrl );
	check( await page.locator( '#username' ).count() === 1, 'the link asks the customer to log in' );
	await page.fill( '#username', 'reminded' + stamp );
	await page.fill( '#password', 'pw-reminded-1' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'button[name="login"]' ) ] );
	await page.goto( accountUrl );
	const accountText = await page.locator( 'body' ).innerText();
	check( accountText.includes( L.account.key ), 'after logging in the link shows the customer their license: ' + accountText.replace( /\n/g, ' | ' ).slice( 0, 300 ) );
	await shot( page, '16-account' );
	await page.context().clearCookies();
	await login( page );

	// 6. It is written to the license history, and nothing is sent twice.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses&license=' + L.account.id );
	const history = await page.locator( 'body' ).innerText();
	check( /Expiry reminder emailed/.test( history ), 'the license history shows the reminder' );
	check( ! history.includes( 'account-customer@example.com' ) || ( history.match( /account-customer@example\.com/g ) || [] ).length <= 2, 'the history does not repeat the customer address for the reminder' );
	await shot( page, '16-history' );
	helper( 'run' );
	check( mails().length === 3, 'running the job again sends nothing more, now ' + mails().length );
	check( sql( "SELECT COUNT(*) FROM wp_wplit_license_events WHERE type='reminder_sent'" ) === '3', 'three reminders are recorded' );

	// 7. Later the account license gets close: its second window (7 days) sends a second email.
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 6 DAY) WHERE id=" + L.account.id );
	helper( 'run' );
	check( mails().length === 4, 'a changed expiry date is a new cycle, so the license is mailed again: ' + mails().length );
	check( String( mails()[ 3 ].to ).includes( 'account-customer' ) && /expires in [67] days/.test( mails()[ 3 ].subject ), 'the new email is for the account license, with its new date: ' + mails()[ 3 ].subject );
	// The guest license (5 days away) was already sent its closest window, so it stays quiet.
	check( mails().filter( ( m ) => String( m.to ).includes( 'guest-customer' ) ).length === 1, 'the guest license got exactly one email' );

	// 8. A renewal in the admin moves the expiry a year ahead: nothing is due. A year later it is.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses&license=' + L.later.id );
	await page.selectOption( '#wplit-renew-period', 'P1Y' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( 'form:has(#wplit-renew-period) button[type="submit"]' ) ] );
	const days = Number( sql( 'SELECT DATEDIFF(expires_at, UTC_TIMESTAMP()) FROM wp_wplit_licenses WHERE id=' + L.later.id ) );
	check( days > 300, 'renewing in the admin moved the expiry about a year ahead, ' + days + ' days' );
	const before = mails().length;
	helper( 'run' );
	check( mails().length === before, 'a renewed license is not mailed' );
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 25 DAY) WHERE id=" + L.later.id );
	helper( 'run' );
	check( mails().length === before + 1 && String( mails()[ before ].to ).includes( 'later-customer' ), 'a year later the renewed license gets a new email' );

	// 9. Another renewal address and other days.
	await page.goto( SETTINGS );
	await page.fill( '#wplit_reminder_url', 'https://shop.example.test/renew?utm=reminder' );
	await page.fill( '#wplit_reminder_days', '14' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit' ) ] );
	check( ( await page.inputValue( '#wplit_reminder_url' ) ) === 'https://shop.example.test/renew?utm=reminder', 'the renewal address is saved' );
	check( ( await page.inputValue( '#wplit_reminder_days' ) ) === '14', 'the new days are saved' );
	sql( "UPDATE wp_wplit_licenses SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 13 DAY) WHERE id=" + L.far.id );
	const count = mails().length;
	helper( 'run' );
	const extra = mails().slice( count );
	check( extra.length === 1 && String( extra[ 0 ].to ).includes( 'far-customer' ), 'with the day set to 14 only the license inside 14 days is mailed: ' + extra.map( ( m ) => m.to ).join( ',' ) );
	check( ( extra[ 0 ] && extra[ 0 ].message || '' ).includes( 'https://shop.example.test/renew?utm=reminder' ), 'the email uses the renewal address from the settings' );
	await page.fill( '#wplit_reminder_url', 'javascript:alert(1)' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#submit' ) ] );
	check( ! /javascript:/i.test( await page.inputValue( '#wplit_reminder_url' ) ), 'a javascript: renewal address is not kept, got "' + await page.inputValue( '#wplit_reminder_url' ) + '"' );

	// Put everything back.
	woo( { op: 'clear_mail' } );
	sql( "DELETE FROM wp_options WHERE option_name IN ('wplit_reminders_enabled','wplit_reminder_days','wplit_reminder_url')" );
	for ( const [ name, value ] of [ [ 'wplit_reminders_enabled', saved.enabled ], [ 'wplit_reminder_days', saved.days ], [ 'wplit_reminder_url', saved.url ] ] ) {
		if ( '' !== value ) { sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" + name + "', '" + value.replace( /'/g, "''" ) + "', 'yes')" ); }
	}
	await finish( env, logStart );
} )().catch( ( error ) => { console.error( error ); process.exit( 1 ); } );
