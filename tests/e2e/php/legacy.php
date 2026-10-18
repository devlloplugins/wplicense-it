<?php
/**
 * Builds a 1.x database on the test site: the two 1.x tables (with the exact columns that 1.0.2 created), two published
 * products with their 1.x settings, and licenses and orders that include the awkward cases a real 1.x site has. Prints the
 * IDs the browser test needs as JSON.
 *
 *   php tests/e2e/php/legacy.php seed
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB -- A test helper that builds 1.x tables and rows on purpose.

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';
define( 'WP_ADMIN', true );
require $root . '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

$op = $argv[1] ?? '';

if ( 'restore' === $op ) {
	update_option( 'timezone_string', '' );
	echo wp_json_encode( array( 'ok' => true ) );
	exit;
}

if ( 'seed' !== $op ) {
	echo wp_json_encode( array( 'error' => 'unknown op' ) );
	exit( 1 );
}

// Start from a site that has never migrated: no 2.0 rows, no migration state, no 1.x tables yet.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wplit_product_licenses, {$wpdb->prefix}wplit_orders" );
foreach ( array( 'licenses', 'activations', 'license_orders', 'license_events' ) as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wplit_{$table}" );
}
delete_option( 'wplit_migration_state' );
delete_option( 'wplit_db_version' );
delete_transient( 'wplit_migration_lock' );
wp_clear_scheduled_hook( 'wplit_migration_tick' );
update_option( 'timezone_string', 'America/New_York' );

// The 1.x tables, as 1.0.2's activator made them.
$charset = ! empty( $wpdb->charset ) ? "DEFAULT CHARACTER SET {$wpdb->charset}" : '';
dbDelta( "CREATE TABLE {$wpdb->prefix}wplit_product_licenses(id mediumint(9) NOT NULL auto_increment,user_id mediumint(9) NOT NULL,product_id mediumint(9) DEFAULT 0 NOT NULL,license_key varchar(48) NOT NULL, product_api_key varchar(48) NOT NULL, email varchar(48) NOT NULL, license_status varchar(48) NOT NULL, valid_until DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, created_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, updated_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, UNIQUE KEY id (id)) {$charset};" );
dbDelta( "CREATE TABLE {$wpdb->prefix}wplit_orders(id mediumint(9) NOT NULL auto_increment,user_id mediumint(9) NOT NULL,product_id mediumint(9) DEFAULT 0 NOT NULL,order_number varchar(48) NOT NULL, order_sub_total varchar(16) NOT NULL, order_total varchar(16) NOT NULL, order_email varchar(100) NOT NULL, first_name varchar(50) NOT NULL, last_name varchar(50) NOT NULL, billing_company varchar(50) NOT NULL, billing_address varchar(255) NOT NULL, billing_state varchar(50) NOT NULL, billing_city varchar(50) NOT NULL, billing_country varchar(50) NOT NULL, billing_phone varchar(32) NOT NULL, postal_code varchar(16) NOT NULL,order_status varchar(16) NOT NULL, discount_code varchar(16) NOT NULL, created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, UNIQUE KEY id (id)) {$charset};" );

// Two 1.x products: one that sells yearly licenses, one that never expires.
$make_product = static function ( string $title, array $meta ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => 'wplit_product',
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);

	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}

	return (int) $id;
};

$yearly       = $make_product(
	'Legacy Pro',
	array(
		'wplit_product_api_key' => 'apikey-yearly-' . wp_generate_password( 8, false ),
		'wplit_product_price'   => '49.99',
		'wplit_expire'          => 'yes',
		'wplit_expire_time'     => '1-year',
		'wplit_product_version' => '2.3.0',
	)
);
$lifetime     = $make_product(
	'Legacy Free',
	array(
		'wplit_product_api_key' => 'apikey-free-' . wp_generate_password( 8, false ),
		'wplit_product_price'   => '0',
		'wplit_expire'          => 'no',
		'wplit_product_version' => '1.0.0',
	)
);
$yearly_key   = (string) get_post_meta( $yearly, 'wplit_product_api_key', true );
$lifetime_key = (string) get_post_meta( $lifetime, 'wplit_product_api_key', true );

$user = get_user_by( 'login', 'admin' );
$uid  = $user ? (int) $user->ID : 1;

// 1.x stored times in the site's own time zone.
$tz    = wp_timezone();
$local = static fn( string $when ): string => ( new DateTimeImmutable( $when, $tz ) )->format( 'Y-m-d H:i:s' );
$stamp = $local( '-60 days' );

$license = static function ( array $row ) use ( $wpdb, $stamp, $uid ): int {
	$wpdb->insert(
		"{$wpdb->prefix}wplit_product_licenses",
		array_merge(
			array(
				'user_id'        => $uid,
				'license_status' => 'active',
				'created_at'     => $stamp,
				'updated_at'     => $stamp,
			),
			$row
		)
	);

	return (int) $wpdb->insert_id;
};

$ids = array();

$ids['good']      = $license(
	array(
		'user_id'         => 2001,
		'product_id'      => $yearly,
		'license_key'     => 'LEG-GOOD-0001-AAAA-BBBB',
		'product_api_key' => $yearly_key,
		'email'           => 'Customer@Example.com',
		'valid_until'     => $local( '+200 days 12:00' ),
	)
);
$ids['expired']   = $license(
	array(
		'user_id'         => 2002,
		'product_id'      => $yearly,
		'license_key'     => 'LEG-OLD0-0002-AAAA-BBBB',
		'product_api_key' => $yearly_key,
		'email'           => 'old@example.com',
		'valid_until'     => $local( '-30 days 12:00' ),
	)
);
$ids['inactive']  = $license(
	array(
		'user_id'         => 2003,
		'product_id'      => $yearly,
		'license_key'     => 'LEG-INAC-0003-AAAA-BBBB',
		'product_api_key' => $yearly_key,
		'email'           => 'inactive@example.com',
		'license_status'  => 'inactive',
		'valid_until'     => $local( '+100 days 12:00' ),
	)
);
$ids['lifetime']  = $license(
	array(
		'user_id'         => 2004,
		'product_id'      => $lifetime,
		'license_key'     => 'LEG-LIFE-0004-AAAA-BBBB',
		'product_api_key' => $lifetime_key,
		'email'           => 'forever@example.com',
		'valid_until'     => '0000-00-00 00:00:00',
	)
);
$ids['duplicate'] = $license(
	array(
		'user_id'         => 2005,
		'product_id'      => $yearly,
		'license_key'     => 'LEG-GOOD-0001-AAAA-BBBB',
		'product_api_key' => $yearly_key,
		'email'           => 'copy@example.com',
		'valid_until'     => $local( '+10 days 12:00' ),
	)
);
$ids['nokey']     = $license(
	array(
		'user_id'         => 2006,
		'product_id'      => $yearly,
		'license_key'     => '',
		'product_api_key' => $yearly_key,
		'email'           => 'nokey@example.com',
		'valid_until'     => $local( '+10 days 12:00' ),
	)
);
$ids['noproduct'] = $license(
	array(
		'user_id'         => 2007,
		'product_id'      => 0,
		'license_key'     => 'LEG-NOPR-0007-AAAA-BBBB',
		'product_api_key' => $yearly_key,
		'email'           => 'noproduct@example.com',
		'valid_until'     => $local( '+10 days 12:00' ),
	)
);
$ids['oldkey']    = $license(
	array(
		'user_id'         => 2008,
		'product_id'      => $yearly,
		'license_key'     => 'LEG-OKEY-0008-AAAA-BBBB',
		'product_api_key' => 'a-key-the-product-no-longer-has',
		'email'           => 'oldkey@example.com',
		'valid_until'     => $local( '+100 days 12:00' ),
	)
);

// A busy shop: enough licenses to need more than one batch (the migration works in batches of 200).
for ( $i = 1; $i <= 440; $i++ ) {
	$license(
		array(
			'user_id'         => 3000 + $i,
			'product_id'      => 0 === $i % 2 ? $yearly : $lifetime,
			'license_key'     => sprintf( 'LEG-BULK-%05d-AAAA-BBBB', $i ),
			'product_api_key' => 0 === $i % 2 ? $yearly_key : $lifetime_key,
			'email'           => "bulk{$i}@example.com",
			'valid_until'     => 0 === $i % 2 ? $local( '+150 days 12:00' ) : '0000-00-00 00:00:00',
		)
	);
}

$order = static function ( array $row ) use ( $wpdb, $stamp, $uid ): int {
	$wpdb->insert(
		"{$wpdb->prefix}wplit_orders",
		array_merge(
			array(
				'user_id'         => $uid,
				'order_sub_total' => '49.99',
				'order_total'     => '49.99',
				'first_name'      => 'Cora',
				'last_name'       => 'Customer',
				'billing_company' => 'Example Ltd',
				'billing_address' => '1 Main Street',
				'billing_state'   => 'NY',
				'billing_city'    => 'New York',
				'billing_country' => 'US',
				'billing_phone'   => '555-0100',
				'postal_code'     => '10001',
				'order_status'    => 'completed',
				'discount_code'   => '',
				'created_at'      => $stamp,
				'updated_at'      => $stamp,
			),
			$row
		)
	);

	return (int) $wpdb->insert_id;
};

$ids['order_good']      = $order(
	array(
		'user_id'      => 2001,
		'product_id'   => $yearly,
		'order_number' => 'WLI-1001',
		'order_email'  => 'Customer@Example.com',
	)
);
$ids['order_collision'] = $order(
	array(
		'user_id'         => 2002,
		'product_id'      => $yearly,
		'order_number'    => 'WLI-1001',
		'order_email'     => 'old@example.com',
		'order_total'     => '19.5',
		'order_sub_total' => '19.5',
	)
);
$ids['order_noproduct'] = $order(
	array(
		'user_id'      => 2007,
		'product_id'   => 0,
		'order_number' => 'WLI-1003',
		'order_email'  => 'noproduct@example.com',
	)
);
for ( $i = 1; $i <= 9; $i++ ) {
	$order(
		array(
			'user_id'      => 3000 + ( $i * 2 ),
			'product_id'   => $yearly,
			'order_number' => 'WLI-20' . $i,
			'order_email'  => 'bulk' . ( $i * 2 ) . '@example.com',
		)
	);
}

echo wp_json_encode(
	array(
		'yearly'       => $yearly,
		'lifetime'     => $lifetime,
		'yearly_key'   => $yearly_key,
		'lifetime_key' => $lifetime_key,
		'ids'          => $ids,
		'good_local'   => $local( '+200 days 12:00' ),
		'good_utc'     => ( new DateTimeImmutable( '+200 days 12:00', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
	)
);
