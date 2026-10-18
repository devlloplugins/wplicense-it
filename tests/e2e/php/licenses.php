<?php
/**
 * Fills the test site with licenses through the plugin's own service, for the admin screen test.
 *
 *   php tests/e2e/php/licenses.php seed
 *   php tests/e2e/php/licenses.php activate KEY https://customer.example.com
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB -- A test helper that builds rows on purpose.

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';
define( 'WP_ADMIN', true );
require $root . '/wordpress/wp-load.php';

global $wpdb;

$service = \Devllo\WPLicenseIt\Plugin::instance()->licenses();
$op      = $argv[1] ?? '';

if ( 'activate' === $op ) {
	$result = $service->activate( (string) $argv[2], (string) $argv[3] );
	echo wp_json_encode( array( 'ok' => \Devllo\WPLicenseIt\Licenses\ActivationResult::ACTIVATED === $result->code ) );
	exit( 0 );
}

if ( 'seed' !== $op ) {
	echo wp_json_encode( array( 'error' => 'unknown op' ) );
	exit( 1 );
}

foreach ( array( 'licenses', 'activations', 'license_orders', 'license_events' ) as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wplit_{$table}" );
}

$product = static function ( string $title ): int {
	return (int) wp_insert_post(
		array(
			'post_type'   => 'wplit_product',
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
};

$alpha = $product( 'Admin Test Alpha' );
$beta  = $product( 'Admin Test Beta' );
$utc   = new DateTimeZone( 'UTC' );
$keys  = array();

// 45 licenses: 30 for Alpha and 15 for Beta, newest last. Every fifth never expires, the others end at different dates.
for ( $i = 1; $i <= 45; $i++ ) {
	$expires    = 0 === $i % 5 ? null : new DateTimeImmutable( '+' . ( 30 + $i * 3 ) . ' days 12:00', $utc );
	$license    = $service->issue_license( $i <= 30 ? $alpha : $beta, "cust{$i}@example.com", null, null, 1 + ( $i % 3 ), $expires );
	$keys[ $i ] = array(
		'id'  => $license->id,
		'key' => $license->license_key,
	);
}

foreach ( array( 3, 13, 23, 33 ) as $i ) {
	$service->revoke_license( $keys[ $i ]['id'] );
}
foreach ( array( 7, 37 ) as $i ) {
	$service->revoke_license( $keys[ $i ]['id'], \Devllo\WPLicenseIt\Licenses\Status::REFUNDED );
}
foreach ( array( 11, 12, 41 ) as $i ) {
	$wpdb->update(
		"{$wpdb->prefix}wplit_licenses",
		array(
			'status'     => 'expired',
			'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
		),
		array( 'id' => $keys[ $i ]['id'] )
	);
}

echo wp_json_encode(
	array(
		'alpha' => $alpha,
		'beta'  => $beta,
		'keys'  => $keys,
	)
);
