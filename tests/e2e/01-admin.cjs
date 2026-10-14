/**
 * Steps 2 to 6 of docs/TESTING.md: the menus load, the settings save, and a product shows its four tabs.
 */
const { BASE, check, finish, login, logSize, shot, start } = require( './lib.cjs' );

( async () => {
	const logStart = logSize();
	const env = await start();
	const page = env.page;

	await login( page );

	// Step 2: the menu and each screen.
	const screens = {
		'products list': '/wp-admin/edit.php?post_type=wplit_product',
		'licenses': '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licenses',
		'add license': '/wp-admin/edit.php?post_type=wplit_product&page=wplit-add-license',
		'settings': '/wp-admin/edit.php?post_type=wplit_product&page=wplit-licensing-settings',
	};
	for ( const [ name, url ] of Object.entries( screens ) ) {
		const response = await page.goto( BASE + url );
		check( response.status() === 200, name + ' answers 200, got ' + response.status() );
		const body = await page.locator( 'body' ).innerText();
		check( ! /There has been a critical error|Fatal error|Parse error|not allowed to access this page/i.test( body ), name + ' shows an error: ' + body.slice( 0, 200 ) );
		await shot( page, '02-' + name.replace( / /g, '-' ) );
	}

	// A fresh install has nothing to migrate, so it must not show a migration notice.
	await page.goto( BASE + screens[ 'products list' ] );
	check( ! /moving your licenses to the new 2\.0 format/.test( await page.locator( 'body' ).innerText() ), 'a fresh install shows no migration notice' );

	// Step 4: the settings page saves.
	await page.goto( BASE + screens.settings );
	const save = page.locator( '#submit' );
	check( await save.count() === 1, 'the settings page has a save button' );
	if ( await save.count() ) {
		await Promise.all( [ page.waitForLoadState( 'load' ), save.click() ] );
		const text = await page.locator( 'body' ).innerText();
		check( /Settings saved|settings-updated/i.test( text + page.url() ), 'saving the settings gives a confirmation' );
		await shot( page, '04-settings-saved' );
	}

	// Step 6: a new product with four tabs.
	await page.goto( BASE + '/wp-admin/post-new.php?post_type=wplit_product' );
	await shot( page, '06-new-product' );
	check( await page.locator( '#wplit-tabs' ).isVisible(), 'the tab bar is visible on a new product' );
	check( ( await page.locator( '#wplit-tabs [data-tab]' ).allInnerTexts() ).join( ',' ) === 'Software,Plans,Releases,Connect', 'the tabs are Software, Plans, Releases, Connect' );
	check( await page.locator( '#postdivrich' ).count() === 0, 'there is no content editor' );
	check( await page.locator( '#postimagediv' ).count() === 0, 'there is no featured image box' );

	await page.fill( '#title', 'SEO Pro' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'load' } ), page.click( '#publish' ) ] );
	const productId = new URL( page.url() ).searchParams.get( 'post' );
	check( !! productId, 'publishing goes to the edit screen' );
	console.log( 'product id ' + productId );

	const boxes = { software: 'wplit_software', plans: 'wplit_plans', releases: 'wplit_release', connect: 'wplit_connect' };
	for ( const [ tab, box ] of Object.entries( boxes ) ) {
		await page.click( '#wplit-tabs [data-tab="' + tab + '"]' );
		check( await page.locator( '#' + box ).isVisible(), tab + ' tab shows its box (#' + box + ')' );
		for ( const [ other, otherBox ] of Object.entries( boxes ) ) {
			if ( other !== tab ) { check( ! ( await page.locator( '#' + otherBox ).isVisible() ), tab + ' tab hides #' + otherBox ); }
		}
		await shot( page, '06-tab-' + tab );
	}

	await page.click( '#wplit-tabs [data-tab="software"]' );
	check( await page.locator( '#authordiv' ).isVisible(), 'the author box is on the Software tab' );
	await page.click( '#wplit-tabs [data-tab="connect"]' );
	check( ! ( await page.locator( '#authordiv' ).isVisible() ), 'the author box is not on the Connect tab' );
	check( ! /is not available yet/.test( await page.locator( '#wplit_connect' ).innerText() ), 'the Connect checklist no longer says the test button is missing' );

	await page.reload();
	check( await page.locator( '#wplit-tabs [data-tab="connect"][aria-selected="true"]' ).count() === 1, 'the tab you were on is remembered after a reload' );

	await finish( env, logStart );
} )();
