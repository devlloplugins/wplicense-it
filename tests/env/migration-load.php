<?php
/**
 * Measures the 1.x to 2.0 migration on a big 1.x database: how many cron runs it takes, how long each run is, how much memory
 * it needs, and whether every row arrives. It is a measurement, not part of the regular tests, because it takes minutes.
 *
 *   php tests/env/migration-load.php [licenses] [memory limit] [rows that cannot be migrated] [stop]      (defaults: 100000, 64M and 0; 'stop' leaves it waiting for the owner's decision)
 *
 * It uses the test site built by tests/env/setup.sh and replaces that site's licenses with the generated 1.x data.
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, Squiz.PHP.DiscouragedFunctions, WordPress.PHP.DevelopmentFunctions -- A measuring script for the command line, not plugin code.

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';

if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
	// Runs the cron job the way WordPress cron would, one run at a time, and reports each one as a line of JSON.
	define( 'WP_ADMIN', true );
	require $root . '/wordpress/wp-load.php';

	$runs = 0;
	// What WordPress and the other active plugins already use before the migration does anything.
	echo wp_json_encode( array( 'base_mb' => round( memory_get_usage( true ) / 1048576, 1 ) ) ) . "\n";

	while ( $runs < 400 ) {
		++$runs;
		$started = microtime( true );
		do_action( 'wplit_migration_tick' );
		$state = get_option( 'wplit_migration_state' );
		echo wp_json_encode(
			array(
				'run'      => $runs,
				'seconds'  => round( microtime( true ) - $started, 2 ),
				'peak_mb'  => round( memory_get_peak_usage( true ) / 1048576, 1 ),
				'status'   => $state['status'],
				'phase'    => $state['phase'],
				'licenses' => $state['licenses_migrated'],
				'orders'   => $state['orders_migrated'],
			)
		) . "\n";

		if ( in_array( $state['status'], array( 'done', 'needs_attention' ), true ) ) {
			break;
		}
	}

	exit( 0 );
}

$total  = (int) ( $argv[1] ?? 100000 );
$memory = $argv[2] ?? '64M';
$bad    = (int) ( $argv[3] ?? 0 );
$stop   = 'stop' === ( $argv[4] ?? '' );
$sql    = static fn( string $query ): string => trim( (string) shell_exec( 'mariadb -N -r wp_test -e ' . escapeshellarg( $query ) . ' 2>&1' ) );

echo "Building a 1.x database with {$total} licenses and " . intdiv( $total, 2 ) . " orders...\n";
$seed = json_decode( (string) shell_exec( 'php ' . escapeshellarg( __DIR__ . '/../e2e/php/legacy.php' ) . ' seed' ), true );

if ( ! is_array( $seed ) ) {
	fwrite( STDERR, "Could not build the test data. Is the test site up (tests/env/setup.sh)?\n" );
	exit( 1 );
}

$yearly   = (int) $seed['yearly'];
$lifetime = (int) $seed['lifetime'];
$extra    = max( 0, $total - 448 );

$sql( 'TRUNCATE TABLE wp_wplit_product_licenses' );
$sql( 'TRUNCATE TABLE wp_wplit_orders' );
// Licenses: half yearly and half lifetime, some already expired, each with its own customer and key.
$sql(
	"INSERT INTO wp_wplit_product_licenses (user_id, product_id, license_key, product_api_key, email, license_status, valid_until, created_at, updated_at)
	 SELECT 10000 + seq, IF(seq % 2 = 0, {$yearly}, {$lifetime}), CONCAT('LOAD-', LPAD(seq, 7, '0'), '-AAAA-BBBB-CCCC'),
	        IF(seq % 2 = 0, '" . $seed['yearly_key'] . "', '" . $seed['lifetime_key'] . "'), CONCAT('load', seq, '@example.com'),
	        IF(seq % 97 = 0, 'inactive', 'active'),
	        IF(seq % 2 = 0, IF(seq % 11 = 0, NOW() - INTERVAL 40 DAY, NOW() + INTERVAL 150 DAY), '0000-00-00 00:00:00'),
	        NOW() - INTERVAL (seq % 700) DAY, NOW()
	 FROM seq_1_to_" . ( $extra + 448 )
);
$sql(
	"INSERT INTO wp_wplit_orders (user_id, product_id, order_number, order_sub_total, order_total, order_email, first_name, last_name, billing_company, billing_address, billing_state, billing_city, billing_country, billing_phone, postal_code, order_status, discount_code, created_at, updated_at)
	 SELECT 10000 + seq, {$yearly}, CONCAT('LOAD-', seq), '49.99', '49.99', CONCAT('load', seq, '@example.com'), 'Load', 'Customer', 'Example Ltd', '1 Main Street', 'NY', 'New York', 'US', '555-0100', '10001', 'completed', '', NOW() - INTERVAL (seq % 700) DAY, NOW()
	 FROM seq_1_to_" . intdiv( $total, 2 ) . ' WHERE seq % 2 = 0'
);
if ( $bad > 0 ) {
	// Rows with an empty key: the migration has to skip them and say so.
	$sql( "INSERT INTO wp_wplit_product_licenses (user_id, product_id, license_key, product_api_key, email, license_status, valid_until, created_at, updated_at) SELECT 90000 + seq, {$yearly}, '', '', CONCAT('bad', seq, '@example.com'), 'active', '0000-00-00 00:00:00', NOW(), NOW() FROM seq_1_to_{$bad}" );
}
$legacy_licenses = (int) $sql( 'SELECT COUNT(*) FROM wp_wplit_product_licenses' );
$legacy_orders   = (int) $sql( 'SELECT COUNT(*) FROM wp_wplit_orders' );
// The seed made the 1.x tables and cleared the 2.0 state; the licenses table is now the generated one.
$before = $sql( 'CHECKSUM TABLE wp_wplit_product_licenses, wp_wplit_orders' );
echo "1.x tables hold {$legacy_licenses} licenses and {$legacy_orders} orders.\n";

$site    = 'http://127.0.0.1:8080';
$timed   = static function ( string $url, int $times = 5 ): string {
	$best = INF;

	for ( $i = 0; $i < $times; $i++ ) {
		$t = microtime( true );
		shell_exec( 'curl -s -o /dev/null ' . escapeshellarg( $url ) );
		$best = min( $best, microtime( true ) - $t );
	}

	return sprintf( '%.0f ms', $best * 1000 );
};
$last    = (int) $sql( "SELECT MAX(id) FROM wp_wplit_product_licenses WHERE license_key <> ''" );
$old_url = static function () use ( $site, $sql, $last ): string {
	$row = explode( "\t", $sql( 'SELECT CONCAT_WS(CHAR(9), email, license_key, product_id, product_api_key) FROM wp_wplit_product_licenses WHERE id = ' . $last ) );

	return $site . '/?__wp_license_api=status&p=' . $row[2] . '&e=' . rawurlencode( $row[0] ) . '&l=' . rawurlencode( $row[1] ) . '&k=' . rawurlencode( $row[3] );
};
echo '  before migrating, the old status URL for the newest license (reads the 1.x table, which has no index): ' . $timed( $old_url() ) . ' (best of 5)' . "\n";
echo '  a page that does not touch licenses, for comparison: ' . $timed( $site . '/wp-login.php' ) . "\n\n";
echo "Running the migration cron job with memory_limit={$memory}:\n";

$wall    = microtime( true );
$runs    = array();
$handle  = popen( 'php -d memory_limit=' . escapeshellarg( $memory ) . ' ' . escapeshellarg( __FILE__ ) . ' --child 2>&1', 'r' );
$crashed = '';
$base_mb = 0;

$line = fgets( $handle );
while ( false !== $line ) {
	$row = json_decode( $line, true );

	if ( is_array( $row ) && isset( $row['base_mb'] ) ) {
		$base_mb = $row['base_mb'];
		echo "  (WordPress and the active plugins use {$base_mb} MB before the migration starts)\n";
	} elseif ( is_array( $row ) ) {
		$runs[] = $row;
		printf( "  run %-3d %6.2fs  peak %5.1f MB  %-15s %-9s licenses %-7d orders %d\n", $row['run'], $row['seconds'], $row['peak_mb'], $row['status'], $row['phase'], $row['licenses'], $row['orders'] );
	} else {
		$crashed .= $line;
	}
	$line = fgets( $handle );
}

pclose( $handle );
$elapsed = microtime( true ) - $wall;
$largest = static fn( string $field ): float => array() === $runs ? 0.0 : (float) max( array_column( $runs, $field ) );

if ( '' !== $crashed ) {
	echo "\nThe job printed something that is not a progress line (a crash or a PHP error):\n" . trim( $crashed ) . "\n";
}

if ( $stop ) {
	echo "\nStopped before the switch, as asked. What the notice would show (first 10 lines):\n";
	$state_now = $sql( "SELECT option_value FROM wp_options WHERE option_name = 'wplit_migration_state'" );
	$shown     = shell_exec( 'php -r ' . escapeshellarg( 'define( "WP_ADMIN", true ); require "' . $root . '/wordpress/wp-load.php"; $s = ( new \\Devllo\\WPLicenseIt\\Migration\\OptionStateStore() )->load(); echo "Left behind so far: {$s->licenses_skipped} licenses, {$s->orders_skipped} orders.\n" . implode( "\n", $s->problems_to_show( 10 ) ) . "\n";' ) );
	echo $shown;
	exit( 0 );
}

echo "\nSwitching over like the site owner would (Switch to 2.0 anyway)...\n";
$switch = microtime( true );
$code   = 'define( "WP_ADMIN", true ); require "' . $root . '/wordpress/wp-load.php"; $t = microtime( true ); $s = \\Devllo\\WPLicenseIt\\Migration\\MigrationRunner::migrator()->run( 20, 200, true ); echo json_encode( array( "status" => $s->status, "finalized" => $s->finalized, "seconds" => round( microtime( true ) - $t, 2 ) ) );';
$out    = shell_exec( 'php -d memory_limit=' . escapeshellarg( $memory ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1' );
echo '  ' . trim( (string) $out ) . "\n";

$migrated = (int) $sql( 'SELECT COUNT(*) FROM wp_wplit_licenses' );
$orders   = (int) $sql( 'SELECT COUNT(*) FROM wp_wplit_license_orders' );
$dupes    = (int) $sql( 'SELECT COUNT(*) FROM (SELECT license_key FROM wp_wplit_licenses GROUP BY license_key HAVING COUNT(*) > 1) d' );
$after    = $sql( 'CHECKSUM TABLE wp_wplit_product_licenses, wp_wplit_orders' );
$state    = $sql( "SELECT option_value FROM wp_options WHERE option_name = 'wplit_migration_state'" );

echo "\nResult\n";
printf( "  cron runs: %d, longest run %.2fs, highest peak memory %.1f MB\n", count( $runs ), $largest( 'seconds' ), $largest( 'peak_mb' ) );
printf( "  time inside the migration (without the gaps cron leaves between runs): %.0fs\n", $elapsed );
printf( "  with cron's 10 second gap after every run, the job would take about %.0f minutes\n", ( $elapsed + 10 * count( $runs ) ) / 60 );
printf( "  licenses: %d of %d migrated, orders: %d of %d, duplicate keys: %d\n", $migrated, $legacy_licenses, $orders, $legacy_orders, $dupes );
printf( "  problems kept in the notice: %d of %d rows skipped\n", count( (array) ( @unserialize( $state )['problems'] ?? array() ) ), $legacy_licenses - $migrated );
printf( "  1.x tables unchanged: %s\n", $before === $after ? 'yes' : 'NO' );
printf( "  finished: %s\n", preg_match( '/"finalized";b:1/', $state ) && preg_match( '/"status";s:4:"done"/', $state ) ? 'yes, switched over' : 'no: ' . $state );

// The same lookup after the switch, from the indexed 2.0 table.
echo '  after migrating, the same old status URL (reads the 2.0 table): ' . $timed( $old_url() ) . ' (best of 5)' . "\n";
printf( "  memory the migration itself needs on top of WordPress: about %.1f MB\n", $largest( 'peak_mb' ) - $base_mb );

echo "\nPut the test site back to normal with: php tests/e2e/php/legacy.php restore\n";
