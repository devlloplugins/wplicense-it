<?php
/**
 * Seeds two customers for the privacy tools test: Ann, whose data is exported and erased, and Bob, who must be left alone. Each
 * has a WordPress account, licenses, activations, history, orders (one with 1.x billing details) and rows in the 1.x tables.
 *
 *   php tests/e2e/php/privacy.php seed
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB -- A test helper that builds rows on purpose.

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';
define( 'WP_ADMIN', true );
require $root . '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

if ( 'seed' !== ( $argv[1] ?? '' ) ) {
	echo wp_json_encode( array( 'error' => 'unknown op' ) );
	exit( 1 );
}

$service = \Devllo\WPLicenseIt\Plugin::instance()->licenses();

foreach ( array( 'licenses', 'activations', 'license_orders', 'license_events' ) as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wplit_{$table}" );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wplit_product_licenses, {$wpdb->prefix}wplit_orders" );

// The 1.x tables, as 1.0.2 made them.
dbDelta( "CREATE TABLE {$wpdb->prefix}wplit_product_licenses(id mediumint(9) NOT NULL auto_increment,user_id mediumint(9) NOT NULL,product_id mediumint(9) DEFAULT 0 NOT NULL,license_key varchar(48) NOT NULL, product_api_key varchar(48) NOT NULL, email varchar(48) NOT NULL, license_status varchar(48) NOT NULL, valid_until DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, UNIQUE KEY id (id));" );
dbDelta( "CREATE TABLE {$wpdb->prefix}wplit_orders(id mediumint(9) NOT NULL auto_increment,user_id mediumint(9) NOT NULL,product_id mediumint(9) DEFAULT 0 NOT NULL,order_number varchar(48) NOT NULL, order_sub_total varchar(16) NOT NULL, order_total varchar(16) NOT NULL, order_email varchar(100) NOT NULL, first_name varchar(50) NOT NULL, last_name varchar(50) NOT NULL, billing_company varchar(50) NOT NULL, billing_address varchar(255) NOT NULL, billing_state varchar(50) NOT NULL, billing_city varchar(50) NOT NULL, billing_country varchar(50) NOT NULL, billing_phone varchar(32) NOT NULL, postal_code varchar(16) NOT NULL,order_status varchar(16) NOT NULL, discount_code varchar(16) NOT NULL, created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, UNIQUE KEY id (id));" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wplit_product_licenses" );
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wplit_orders" );

$product = (int) wp_insert_post(
	array(
		'post_type'   => 'wplit_product',
		'post_status' => 'publish',
		'post_title'  => 'Privacy Test Product',
	)
);

$person = static function ( string $login, string $email ): int {
	$user = get_user_by( 'email', $email );

	if ( $user ) {
		return (int) $user->ID;
	}

	return (int) wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => 'pw-privacy-test-1',
			'user_email' => $email,
			'role'       => 'subscriber',
		)
	);
};

$ann_user = $person( 'privacy_ann', 'ann.private@example.com' );
$bob_user = $person( 'privacy_bob', 'bob.control@example.com' );
$utc      = new DateTimeZone( 'UTC' );
$out      = array(
	'product'  => $product,
	'ann'      => array(
		'user'  => $ann_user,
		'email' => 'ann.private@example.com',
		'keys'  => array(),
	),
	'bob'      => array(
		'user'  => $bob_user,
		'email' => 'bob.control@example.com',
		'keys'  => array(),
	),
	'work_key' => '',
);

// Ann: 60 licenses on her email (more than the 25 an export page holds), and 1 issued to her work address but linked to her account.
for ( $i = 1; $i <= 60; $i++ ) {
	$license                  = $service->issue_license( $product, 'ann.private@example.com', $ann_user, null, 2, new DateTimeImmutable( '+200 days', $utc ) );
	$out['ann']['keys'][ $i ] = $license->license_key;
	$out['ann']['ids'][ $i ]  = $license->id;
}
$work            = $service->issue_license( $product, 'ann.work@example.org', $ann_user, null, 1, null );
$out['work_key'] = $work->license_key;
$out['work_id']  = $work->id;

// Activations on two sites, and an edit that puts her email in the history.
$service->activate( $out['ann']['keys'][1], 'https://ann-shop.example.net' );
$service->activate( $out['ann']['keys'][1], 'https://ann-blog.example.net' );
$changes        = new \Devllo\WPLicenseIt\Licenses\LicenseChanges();
$changes->email = 'ann.renamed@example.com';
$service->update_license( $out['ann']['ids'][2], $changes );
$out['ann']['renamed'] = $out['ann']['ids'][2];

// Orders: a WooCommerce one, and one migrated from 1.x with billing details.
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert(
	"{$wpdb->prefix}wplit_license_orders",
	array(
		'source'       => 'woocommerce',
		'external_id'  => '9001',
		'order_number' => 'WC-9001',
		'user_id'      => $ann_user,
		'product_id'   => $product,
		'currency'     => 'USD',
		'total_minor'  => 4900,
		'status'       => 'completed',
		'created_at'   => $now,
		'updated_at'   => $now,
	)
);
$wc_order = (int) $wpdb->insert_id;
$wpdb->insert(
	"{$wpdb->prefix}wplit_license_orders",
	array(
		'source'         => 'legacy_stripe',
		'order_number'   => 'WLI-ANN-1',
		'user_id'        => $ann_user,
		'product_id'     => $product,
		'currency'       => 'USD',
		'total_minor'    => 2500,
		'status'         => 'completed',
		'legacy_id'      => 1,
		'legacy_billing' => wp_json_encode(
			array(
				'first_name'      => 'Ann',
				'last_name'       => 'Private',
				'billing_company' => 'Ann Private Ltd',
				'billing_address' => '12 Secret Lane',
				'billing_city'    => 'Hidden Town',
				'billing_phone'   => '555-0123',
				'order_email'     => 'ann.private@example.com',
			)
		),
		'created_at'     => $now,
		'updated_at'     => $now,
	)
);
$legacy_order = (int) $wpdb->insert_id;
$wpdb->update( "{$wpdb->prefix}wplit_licenses", array( 'order_id' => $wc_order ), array( 'id' => $out['ann']['ids'][3] ) );
$wpdb->update( "{$wpdb->prefix}wplit_licenses", array( 'order_id' => $legacy_order ), array( 'id' => $out['ann']['ids'][4] ) );
$out['ann']['orders'] = array( $wc_order, $legacy_order );

// The 1.x tables.
$wpdb->insert(
	"{$wpdb->prefix}wplit_product_licenses",
	array(
		'user_id'         => $ann_user,
		'product_id'      => $product,
		'license_key'     => 'OLD1X-ANN-KEY-0001',
		'product_api_key' => 'k',
		'email'           => 'ann.private@example.com',
		'license_status'  => 'active',
		'valid_until'     => '0000-00-00 00:00:00',
		'created_at'      => $now,
		'updated_at'      => $now,
	)
);
$wpdb->insert(
	"{$wpdb->prefix}wplit_orders",
	array(
		'user_id'         => $ann_user,
		'product_id'      => $product,
		'order_number'    => 'OLD-ANN-1',
		'order_sub_total' => '25.00',
		'order_total'     => '25.00',
		'order_email'     => 'ann.private@example.com',
		'first_name'      => 'Ann',
		'last_name'       => 'Private',
		'billing_company' => 'Ann Private Ltd',
		'billing_address' => '12 Secret Lane',
		'billing_state'   => 'NY',
		'billing_city'    => 'Hidden Town',
		'billing_country' => 'US',
		'billing_phone'   => '555-0123',
		'postal_code'     => '10001',
		'order_status'    => 'completed',
		'discount_code'   => '',
		'created_at'      => $now,
		'updated_at'      => $now,
	)
);

// Bob, the control: 3 licenses, an activation, a legacy-billing order and 1.x rows. None of it may change.
for ( $i = 1; $i <= 3; $i++ ) {
	$license                  = $service->issue_license( $product, 'bob.control@example.com', $bob_user, null, 1, null );
	$out['bob']['keys'][ $i ] = $license->license_key;
	$out['bob']['ids'][ $i ]  = $license->id;
}
$service->activate( $out['bob']['keys'][1], 'https://bob-site.example.net' );
$wpdb->insert(
	"{$wpdb->prefix}wplit_license_orders",
	array(
		'source'         => 'legacy_stripe',
		'order_number'   => 'WLI-BOB-1',
		'user_id'        => $bob_user,
		'product_id'     => $product,
		'currency'       => 'USD',
		'total_minor'    => 1000,
		'status'         => 'completed',
		'legacy_id'      => 2,
		'legacy_billing' => wp_json_encode(
			array(
				'first_name'      => 'Bob',
				'billing_address' => '7 Control Road',
			)
		),
		'created_at'     => $now,
		'updated_at'     => $now,
	)
);
$bob_order = (int) $wpdb->insert_id;
$wpdb->update( "{$wpdb->prefix}wplit_licenses", array( 'order_id' => $bob_order ), array( 'id' => $out['bob']['ids'][1] ) );
$wpdb->insert(
	"{$wpdb->prefix}wplit_product_licenses",
	array(
		'user_id'         => $bob_user,
		'product_id'      => $product,
		'license_key'     => 'OLD1X-BOB-KEY-0001',
		'product_api_key' => 'k',
		'email'           => 'bob.control@example.com',
		'license_status'  => 'active',
		'valid_until'     => '0000-00-00 00:00:00',
		'created_at'      => $now,
		'updated_at'      => $now,
	)
);
$wpdb->insert(
	"{$wpdb->prefix}wplit_orders",
	array(
		'user_id'         => $bob_user,
		'product_id'      => $product,
		'order_number'    => 'OLD-BOB-1',
		'order_sub_total' => '10.00',
		'order_total'     => '10.00',
		'order_email'     => 'bob.control@example.com',
		'first_name'      => 'Bob',
		'last_name'       => 'Control',
		'billing_company' => '',
		'billing_address' => '7 Control Road',
		'billing_state'   => '',
		'billing_city'    => 'Controlville',
		'billing_country' => 'US',
		'billing_phone'   => '555-9999',
		'postal_code'     => '20002',
		'order_status'    => 'completed',
		'discount_code'   => '',
		'created_at'      => $now,
		'updated_at'      => $now,
	)
);

echo wp_json_encode( $out );
