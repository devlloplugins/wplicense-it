<?php
/**
 * Checks the plugin on a WordPress multisite network (built by tests/env/setup-multisite.sh): network activation, the tables
 * and settings of each site, who may manage licenses where, that one site's licenses are invisible to another, the
 * migration of a subsite, and what is left behind when a site is deleted or the plugin is uninstalled.
 *
 *   php tests/env/multisite-check.php
 *
 * Each site is loaded in its own PHP process with the site's host and path set, which runs the code a web request would.
 * It ends by uninstalling the plugin from the whole network, so the next load makes the tables again.
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, Squiz.PHP.DiscouragedFunctions, Squiz.PHP.Eval, WordPress.PHP.DevelopmentFunctions -- A command line check, not plugin code.

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';
$net  = $root . '/network';

if ( isset( $argv[1] ) && '--site' === $argv[1] ) {
	// A child process: becomes the site at the given path, runs the code it is handed, and prints the result as JSON.
	$_SERVER['HTTP_HOST']   = '127.0.0.1';
	$_SERVER['REQUEST_URI'] = $argv[2];
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	define( 'WP_ADMIN', true );
	require $net . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	$result = eval( (string) getenv( 'WPLIT_CODE' ) );
	echo "\n@@RESULT@@" . wp_json_encode( $result );
	exit( 0 );
}

$failed = 0;
$passed = 0;

function check( bool $ok, string $what ): void {
	global $failed, $passed;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
	$ok ? ++$passed : ++$failed;
}

/**
 * Runs code as the site at a path and returns what it returned.
 *
 * @param string $path Site path, such as / or /alpha/.
 * @param string $code PHP that ends with a return statement.
 * @return mixed
 */
function site( string $path, string $code ) {
	$out = shell_exec( 'WPLIT_CODE=' . escapeshellarg( $code ) . ' php ' . escapeshellarg( __FILE__ ) . ' --site ' . escapeshellarg( $path ) . ' 2>&1' );
	$pos = strrpos( (string) $out, '@@RESULT@@' );

	if ( false === $pos ) {
		echo '  (the site did not answer: ' . substr( trim( preg_replace( '/<[^>]+>|\s+/', ' ', (string) $out ) ), 0, 300 ) . ")\n";

		return null;
	}

	return json_decode( substr( (string) $out, $pos + 10 ), true );
}

$sql       = static fn( string $query ): string => trim( (string) shell_exec( 'mariadb -N -r wp_network -e ' . escapeshellarg( $query ) . ' 2>&1' ) );
$tables_of = static fn( int $blog ): int => (int) $sql( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_network' AND table_name LIKE '" . ( 1 === $blog ? 'wp\\_wplit\\_%' : 'wp\\_' . $blog . '\\_wplit\\_%' ) . "'" );

if ( '' === $sql( 'SHOW TABLES LIKE "wp_blogs"' ) ) {
	fwrite( STDERR, "There is no network yet. Run tests/env/setup.sh and then tests/env/setup-multisite.sh.\n" );
	exit( 1 );
}

// Tables that an earlier run (or an older version of the plugin) left behind for a site that no longer exists would make the
// counts below wrong, so remove them first.
$live = array_map( 'intval', explode( "\n", $sql( 'SELECT blog_id FROM wp_blogs' ) ) );
foreach ( explode( "\n", $sql( "SELECT table_name FROM information_schema.tables WHERE table_schema='wp_network' AND table_name REGEXP '^wp_[0-9]+_wplit_'" ) ) as $orphan ) {
	if ( '' !== $orphan && ! in_array( (int) explode( '_', $orphan )[1], $live, true ) ) {
		$sql( "DROP TABLE IF EXISTS `{$orphan}`" );
	}
}

echo "Network activation\n";
$active = site( '/', 'require_once ABSPATH . "wp-admin/includes/plugin.php"; $r = activate_plugin( "wplicense-it/wplicense-it.php", "", true ); return array( "error" => is_wp_error( $r ) ? $r->get_error_message() : null, "network" => is_plugin_active_for_network( "wplicense-it/wplicense-it.php" ) );' );
check( is_array( $active ) && null === $active['error'] && true === $active['network'], 'the plugin activates for the whole network' );
check( 4 === $tables_of( 1 ), 'the main site has its 4 tables' );

// A fresh site: no tables until somebody opens it, then the plugin makes them.
site( '/', 'foreach ( array( "delta", "alpha" ) as $slug ) { if ( ! get_id_from_blogname( $slug ) ) { wpmu_create_blog( "127.0.0.1", "/" . $slug . "/", ucfirst( $slug ), 1, array( "public" => 1 ), 1 ); } } return true;' );
$delta = (int) site( '/', 'return get_id_from_blogname( "delta" );' );
$alpha = (int) site( '/', 'return get_id_from_blogname( "alpha" );' );
check( $delta > 1 && $alpha > 1, "two subsites exist (alpha is site {$alpha}, delta is site {$delta})" );
check( 0 === $tables_of( $delta ), 'a new site has no plugin tables before anyone opens it' );
$visit = site( '/delta/', 'return array( "prefix" => $GLOBALS["wpdb"]->prefix, "loaded" => class_exists( "Devllo\\\\WPLicenseIt\\\\Plugin" ), "db" => get_option( "wplit_db_version" ) );' );
check( is_array( $visit ) && true === $visit['loaded'], 'the plugin loads on a subsite' );
check( 4 === $tables_of( $delta ), "the first visit made the 4 tables of site {$delta}" );
site( '/alpha/', 'return 1;' );
check( 4 === $tables_of( $alpha ), "and site {$alpha} has its own 4 tables" );

echo "\nWho may manage licenses where\n";
$admin = site( '/delta/', '$u = get_user_by( "login", "deltaadmin" ); if ( ! $u ) { $id = wpmu_create_user( "deltaadmin", "pw-delta-admin-1", "deltaadmin@example.test" ); $u = get_user_by( "id", $id ); } add_user_to_blog( ' . $delta . ', $u->ID, "administrator" ); return $u->ID;' );
$caps  = site( '/delta/', 'wp_set_current_user( ' . (int) $admin . ' ); do_action( "admin_init" ); return array( "super" => is_super_admin(), "manage" => current_user_can( "manage_wplicense_it" ), "woo" => current_user_can( "edit_products" ) );' );
check( is_array( $caps ) && false === $caps['super'] && true === $caps['manage'], 'the administrator of a subsite (not a super admin) may manage licenses on it' );
$other = site( '/alpha/', 'wp_set_current_user( ' . (int) $admin . ' ); do_action( "admin_init" ); return current_user_can( "manage_wplicense_it" );' );
check( false === $other, "and has no say on another site's licenses" );
$sdk    = static function ( string $path, int $user ) {
	// The handler checks the capability first and the link second, so which message it dies with says which gate stopped it.
	return site( $path, 'wp_set_current_user( ' . $user . ' ); do_action( "admin_init" ); add_filter( "wp_die_handler", static function () { return static function ( $message ) { throw new Exception( wp_strip_all_tags( is_string( $message ) ? $message : "" ) ); }; } ); try { do_action( "admin_post_wplit_download_sdk" ); return "reached the handler without a stop"; } catch ( Exception $e ) { return $e->getMessage(); }' );
};
$answer = $sdk( '/delta/', (int) $admin );
check( is_string( $answer ) && false === strpos( $answer, 'not allowed' ), 'a subsite administrator is let through to the SDK download (the capability check passes even where WooCommerce is not active): ' . substr( (string) $answer, 0, 80 ) );

echo "\nOne site cannot see another's licenses\n";
$issued = site( '/alpha/', '$product = wp_insert_post( array( "post_type" => "wplit_product", "post_status" => "publish", "post_title" => "Alpha Product" ) ); $license = Devllo\\WPLicenseIt\\Plugin::instance()->licenses()->issue_license( $product, "buyer@example.com", null, null, 1, null ); return array( "product" => $product, "key" => $license->license_key );' );
check( is_array( $issued ) && ! empty( $issued['key'] ), 'a license is issued on alpha' );
check( '1' === $sql( "SELECT COUNT(*) FROM wp_{$alpha}_wplit_licenses" ) && '0' === $sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' ) && '0' === $sql( "SELECT COUNT(*) FROM wp_{$delta}_wplit_licenses" ), 'it is stored in alpha\'s table only' );
$validate = static fn( string $path, array $body ) => site( $path, '$request = new WP_REST_Request( "POST", "/wplicense-it/v1/licenses/validate" ); $request->set_header( "content-type", "application/json" ); $request->set_body( wp_json_encode( ' . var_export( $body, true ) . ' ) ); $response = rest_do_request( $request ); $data = $response->get_data(); return array( "status" => $response->get_status(), "ok" => ! empty( $data["success"] ) );' );
$here     = $validate(
	'/alpha/',
	array(
		'license_key' => $issued['key'],
		'product_id'  => $issued['product'],
	)
);
$there    = $validate(
	'/delta/',
	array(
		'license_key' => $issued['key'],
		'product_id'  => $issued['product'],
	)
);
check( is_array( $here ) && true === $here['ok'], 'alpha\'s API accepts the key' );
check( is_array( $there ) && false === $there['ok'], 'delta\'s API does not know it, got status ' . ( is_array( $there ) ? $there['status'] : '?' ) );

echo "\nFiles\n";
$dirs = array(
	'main'  => site( '/', 'return Devllo\\WPLicenseIt\\Files\\ProtectedStorage::directory();' ),
	'alpha' => site( '/alpha/', 'return Devllo\\WPLicenseIt\\Files\\ProtectedStorage::directory();' ),
	'delta' => site( '/delta/', 'return Devllo\\WPLicenseIt\\Files\\ProtectedStorage::directory();' ),
);
check( 3 === count( array_unique( $dirs ) ) && ! in_array( null, $dirs, true ), 'every site keeps its package files in its own folder' );
check( is_dir( (string) $dirs['delta'] ), 'and the folder exists' );

echo "\nMigrating a subsite from 1.x\n";
$prefix = "wp_{$alpha}_";
$sql( "DROP TABLE IF EXISTS {$prefix}wplit_product_licenses, {$prefix}wplit_orders" );
$sql( "CREATE TABLE {$prefix}wplit_product_licenses (id mediumint(9) NOT NULL auto_increment, user_id mediumint(9) NOT NULL, product_id mediumint(9) DEFAULT 0 NOT NULL, license_key varchar(48) NOT NULL, product_api_key varchar(48) NOT NULL, email varchar(48) NOT NULL, license_status varchar(48) NOT NULL, valid_until DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, UNIQUE KEY id (id))" );
$sql( "CREATE TABLE {$prefix}wplit_orders (id mediumint(9) NOT NULL auto_increment, user_id mediumint(9) NOT NULL, product_id mediumint(9) DEFAULT 0 NOT NULL, order_number varchar(48) NOT NULL, order_sub_total varchar(16) NOT NULL, order_total varchar(16) NOT NULL, order_email varchar(100) NOT NULL, first_name varchar(50) NOT NULL, last_name varchar(50) NOT NULL, billing_company varchar(50) NOT NULL, billing_address varchar(255) NOT NULL, billing_state varchar(50) NOT NULL, billing_city varchar(50) NOT NULL, billing_country varchar(50) NOT NULL, billing_phone varchar(32) NOT NULL, postal_code varchar(16) NOT NULL, order_status varchar(16) NOT NULL, discount_code varchar(16) NOT NULL, created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, UNIQUE KEY id (id))" );
$sql( "TRUNCATE TABLE {$prefix}wplit_licenses" );
$sql( "TRUNCATE TABLE {$prefix}wplit_license_orders" );
$product = (int) $issued['product'];
$sql( "INSERT INTO {$prefix}wplit_product_licenses (user_id, product_id, license_key, product_api_key, email, license_status, valid_until, created_at, updated_at) VALUES (5, {$product}, 'ALPHA-OLD-0001', 'k', 'a@example.com', 'active', '0000-00-00 00:00:00', NOW(), NOW()), (6, {$product}, 'ALPHA-OLD-0002', 'k', 'b@example.com', 'active', '0000-00-00 00:00:00', NOW(), NOW()), (7, {$product}, 'ALPHA-OLD-0003', 'k', 'c@example.com', 'active', '0000-00-00 00:00:00', NOW(), NOW())" );
$main_state_before = site( '/', 'return get_option( "wplit_migration_state" );' );
site( '/alpha/', 'delete_option( "wplit_migration_state" ); update_option( "wplit_db_version", "1.0.2" ); return true;' );
$ticks = site( '/alpha/', 'for ( $i = 0; $i < 5; $i++ ) { do_action( "wplit_migration_tick" ); $s = get_option( "wplit_migration_state" ); if ( in_array( $s["status"], array( "done", "needs_attention" ), true ) ) { break; } } return array( "status" => $s["status"], "licenses" => $s["licenses_migrated"], "final" => $s["finalized"] );' );
check( is_array( $ticks ) && 3 === $ticks['licenses'], 'alpha\'s three 1.x licenses were migrated from its own wp_' . $alpha . '_ tables: ' . json_encode( $ticks ) );
check( '3' === $sql( "SELECT COUNT(*) FROM {$prefix}wplit_licenses WHERE license_key LIKE 'ALPHA-OLD-%'" ) && '0' === $sql( "SELECT COUNT(*) FROM wp_wplit_licenses WHERE license_key LIKE 'ALPHA-OLD-%'" ), 'they landed in alpha\'s 2.0 table and not in the main site\'s' );
check( site( '/', 'return get_option( "wplit_migration_state" );' ) === $main_state_before, 'the main site\'s migration state is untouched' );

echo "\nDeleting a site\n";
check( 4 === $tables_of( $delta ), "site {$delta} has its tables before it is deleted" );
site( '/', 'require_once ABSPATH . "wp-admin/includes/ms.php"; wpmu_delete_blog( ' . $delta . ', true ); return true;' );
check( 0 === (int) $sql( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_network' AND table_name LIKE 'wp\\_{$delta}\\_%'" ), 'deleting the site removed all its tables, including the 4 of the plugin (and the licenses in them)' );
clearstatcache();
check( ! is_dir( (string) $dirs['delta'] ), 'and its package folder (' . $dirs['delta'] . ')' );

echo "\nUninstalling the plugin from the network\n";
$sql( "DROP TABLE IF EXISTS {$prefix}wplit_product_licenses, {$prefix}wplit_orders" );
$sites  = array_map( 'intval', explode( "\n", $sql( 'SELECT blog_id FROM wp_blogs' ) ) );
$count  = static function () use ( $tables_of, $sites ): int {
	return array_sum( array_map( $tables_of, $sites ) );
};
$before    = $count();
$uninstall = 'define( "WP_UNINSTALL_PLUGIN", "wplicense-it/wplicense-it.php" ); include WP_PLUGIN_DIR . "/wplicense-it/uninstall.php"; return true;';

// By default deleting the plugin keeps everything.
site( '/', $uninstall );
$kept = $count();
check( $before >= 8 && $before === $kept, "by default uninstall keeps every table of every site ({$before} before, {$kept} after)" );
check( '2.0.0' === site( '/alpha/', 'return get_option( "wplit_db_version" );' ), 'and keeps the settings and the database version' );

// Each site decides for itself: the main site opts in, the others do not.
site( '/', 'update_option( "wplit_delete_data_on_uninstall", 1 ); return true;' );
site( '/', $uninstall );
$main_left  = $tables_of( 1 );
$alpha_left = $tables_of( $alpha );
check( 0 === $main_left && 4 === $alpha_left, "a site that opted in loses its tables and the others keep theirs (main {$main_left}, alpha {$alpha_left})" );

foreach ( explode( "\n", $sql( 'SELECT path FROM wp_blogs' ) ) as $path ) {
	site( $path, 'update_option( "wplit_delete_data_on_uninstall", 1 ); return true;' );
}
site( '/', $uninstall );
$left = $count();
check( 0 === $left, "with every site opted in, uninstall removes the plugin's tables from every site ({$before} before, {$left} after)" );
$again = site( '/alpha/', 'return get_option( "wplit_db_version" );' );
check( '2.0.0' === $again && 4 === $tables_of( $alpha ), 'the plugin starts again on the next visit and makes alpha\'s tables anew' );

echo "\n" . ( 0 === $failed ? "All {$passed} checks passed.\n" : "{$failed} of " . ( $passed + $failed ) . " checks failed.\n" );
exit( 0 === $failed ? 0 : 1 );
