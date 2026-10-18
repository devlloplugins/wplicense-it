<?php
/**
 * Measures the queries the plugin runs on a big license table: how long each takes and what the database says about how it
 * runs it (EXPLAIN: which index, how many rows it expects to read, whether it sorts without an index). It runs the plugin's
 * own code, so the SQL is the SQL the plugin really sends.
 *
 *   php tests/env/query-check.php [licenses]        (default 1000000; takes a few minutes to build)
 *
 * It uses the test site built by tests/env/setup.sh, replaces that site's licenses with generated ones, and empties them
 * again at the end.
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, Squiz.PHP.DiscouragedFunctions, WordPress.PHP.DevelopmentFunctions -- A measuring script for the command line, not plugin code.

$root  = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';
$total = max( 1000, (int) ( $argv[1] ?? 1000000 ) );

define( 'WP_ADMIN', true );
define( 'SAVEQUERIES', true );
require $root . '/wordpress/wp-load.php';

use Devllo\WPLicenseIt\Database\WpdbEventLog;
use Devllo\WPLicenseIt\Database\WpdbLicenseRepository;
use Devllo\WPLicenseIt\Licenses\LicenseQuery;

global $wpdb;

// A table made by an earlier build lacks indexes added since, so bring it up to date first (it adds only what is missing).
\Devllo\WPLicenseIt\Database\Installer::install();

$t_licenses    = $wpdb->prefix . 'wplit_licenses';
$t_activations = $wpdb->prefix . 'wplit_activations';
$t_events      = $wpdb->prefix . 'wplit_license_events';
$t_orders      = $wpdb->prefix . 'wplit_license_orders';
$sql           = static function ( string $query ) use ( $wpdb ): void {
	$wpdb->query( $query );

	if ( '' !== $wpdb->last_error ) {
		fwrite( STDERR, 'SQL error: ' . $wpdb->last_error . "\n" );
		exit( 1 );
	}
};

echo "Building {$total} licenses, " . intdiv( $total, 3 ) . " activations and {$total} events...\n";
$started = microtime( true );
foreach ( array( $t_licenses, $t_activations, $t_events, $t_orders ) as $table ) {
	$sql( "TRUNCATE TABLE {$table}" );
}
$now = gmdate( 'Y-m-d H:i:s' );
// Mostly active, some expired, a few revoked or refunded; expiry dates spread from two years ago to a year ahead; 20 products;
// 5,000 licenses are active but past their date, which is what the daily sweep has to find.
$sql(
	"INSERT INTO {$t_licenses} (product_id, user_id, order_id, license_key, email, status, activation_limit, expires_at, created_at, updated_at)
	 SELECT 100 + (seq % 20), 1000 + (seq % 50000), NULL, CONCAT('KEY-', LPAD(seq, 8, '0'), '-AAAA-BBBB'), CONCAT('user', seq, '@example.com'),
	        CASE WHEN seq % 100 < 85 THEN 'active' WHEN seq % 100 < 95 THEN 'expired' WHEN seq % 100 < 98 THEN 'revoked' ELSE 'refunded' END,
	        1 + (seq % 5),
	        CASE WHEN seq % 10 = 0 THEN NULL WHEN seq <= 5000 THEN '{$now}' - INTERVAL 3 DAY ELSE '{$now}' + INTERVAL (CAST(seq % 1095 AS SIGNED) - 730) DAY END,
	        '{$now}' - INTERVAL (seq % 900) DAY, '{$now}'
	 FROM seq_1_to_{$total}"
);
$sql( "UPDATE {$t_licenses} SET status = 'active' WHERE id <= 5000" );
$sql(
	"INSERT INTO {$t_activations} (license_id, site, is_local, status, product_version, activated_at, last_checked_at)
	 SELECT seq * 3, CONCAT('site', seq, '.example.com'), 0, 'active', '1.0.0', '{$now}' - INTERVAL (seq % 300) DAY, '{$now}'
	 FROM seq_1_to_" . intdiv( $total, 3 )
);
$sql(
	"INSERT INTO {$t_events} (license_id, type, data, created_at)
	 SELECT 1 + (seq % {$total}), IF(seq % 4 = 0, 'activated', 'issued'), NULL, '{$now}' - INTERVAL (seq % 1100) DAY
	 FROM seq_1_to_{$total}"
);
$sql( "ANALYZE TABLE {$t_licenses}, {$t_activations}, {$t_events}" );
printf( "Built in %.0f seconds. Table sizes (data + indexes): licenses %s MB, activations %s MB, events %s MB.\n\n", microtime( true ) - $started, $wpdb->get_var( "SELECT ROUND((data_length+index_length)/1048576) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$t_licenses}'" ), $wpdb->get_var( "SELECT ROUND((data_length+index_length)/1048576) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$t_activations}'" ), $wpdb->get_var( "SELECT ROUND((data_length+index_length)/1048576) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$t_events}'" ) );

$service  = \Devllo\WPLicenseIt\Plugin::instance()->licenses();
$mid_key  = sprintf( 'KEY-%08d-AAAA-BBBB', intdiv( $total, 2 ) );
$mid_mail = 'user' . intdiv( $total, 2 ) . '@example.com';
$query    = static function ( array $input ): LicenseQuery {
	return LicenseQuery::from_input( $input );
};
$events   = new WpdbEventLog( $wpdb );

$scenarios = array(
	'List, first page (what opens on every visit)'      => static fn() => $service->search( $query( array() ) ),
	'List, a deep page (page 20000 of 20)'              => static fn() => $service->search( $query( array( 'page' => 20000 ) ) ),
	'List filtered by status = expired'                 => static fn() => $service->search( $query( array( 'status' => 'expired' ) ) ),
	'List filtered by one product'                      => static fn() => $service->search( $query( array( 'product_id' => 105 ) ) ),
	'List filtered by status and product'               => static fn() => $service->search(
		$query(
			array(
				'status'     => 'active',
				'product_id' => 105,
			)
		)
	),
	'Search: a full license key'                        => static fn() => $service->search( $query( array( 'search' => $mid_key ) ) ),
	'Search: the start of a license key'                => static fn() => $service->search( $query( array( 'search' => 'KEY-00500' ) ) ),
	'Search: a full email address'                      => static fn() => $service->search( $query( array( 'search' => $mid_mail ) ) ),
	'Search: part of an email address'                  => static fn() => $service->search( $query( array( 'search' => 'user50000' ) ) ),
	'Search: nothing matches'                           => static fn() => $service->search( $query( array( 'search' => 'zzz-no-such-thing' ) ) ),
	'List sorted by expiry date'                        => static fn() => $service->search(
		$query(
			array(
				'orderby' => 'expires_at',
				'order'   => 'asc',
			)
		)
	),
	'List sorted by email'                              => static fn() => $service->search(
		$query(
			array(
				'orderby' => 'email',
				'order'   => 'asc',
			)
		)
	),
	'List sorted by created date'                       => static fn() => $service->search( $query( array( 'orderby' => 'created_at' ) ) ),
	'Status counts above the list'                      => static fn() => $service->status_counts(),
	'Validate a key (every API call starts here)'       => static fn() => $service->validate( $mid_key ),
	'Validate a key that does not exist'                => static fn() => $service->validate( 'KEY-NOPE-NOPE' ),
	'A customer\'s licenses by user (My Account)'       => static fn() => $service->licenses_for_user( 1234 ),
	'A customer\'s licenses by email or user (privacy)' => static fn() => ( new WpdbLicenseRepository( $wpdb ) )->for_person( $mid_mail, 1234, 50, 0 ),
	'Daily sweep: find 100 licenses past their date'    => static fn() => $service->expire_due_licenses( 100 ),
	'Events of one license'                             => static fn() => $service->events( $service->find( 777 ), 50 ),
	'Daily prune of events older than 2 years'          => static fn() => $events->prune( new DateTimeImmutable( '-2 years', new DateTimeZone( 'UTC' ) ) ),
);

$slow = array();

foreach ( $scenarios as $name => $run ) {
	$wpdb->queries = array();
	$t             = microtime( true );
	$run();
	$ms      = ( microtime( true ) - $t ) * 1000;
	$queries = $wpdb->queries;
	printf( "%-58s %8.1f ms  (%d queries)\n", $name, $ms, count( $queries ) );

	foreach ( $queries as $entry ) {
		$statement = (string) $entry[0];

		if ( ! preg_match( '/^\s*(SELECT|DELETE|UPDATE)\b/i', $statement ) || false !== stripos( $statement, 'information_schema' ) ) {
			continue;
		}

		$plan = $wpdb->get_results( 'EXPLAIN ' . $statement, ARRAY_A );

		if ( ! is_array( $plan ) ) {
			continue;
		}

		foreach ( $plan as $row ) {
			printf( "    %-8.8s %-9s key=%-16s rows~%-9s %s\n", $row['table'] ?? '', $row['type'] ?? '', $row['key'] ?? 'NONE', $row['rows'] ?? '?', $row['Extra'] ?? '' );
		}

		if ( (float) $entry[1] * 1000 > 100 ) {
			$slow[] = sprintf( '%.0f ms  %s', (float) $entry[1] * 1000, substr( preg_replace( '/\s+/', ' ', $statement ), 0, 150 ) );
		}
	}
}

echo "\nQueries slower than 100 ms:\n";
echo array() === $slow ? "  none\n" : '  ' . implode( "\n  ", $slow ) . "\n";

foreach ( array( $t_licenses, $t_activations, $t_events, $t_orders ) as $table ) {
	$sql( "TRUNCATE TABLE {$table}" );
}
echo "\nThe test site's license tables are empty again.\n";
