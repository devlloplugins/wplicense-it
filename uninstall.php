<?php

// if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

// Delete the table
global $wpdb;

$table_name = $wpdb->prefix . 'wplit_product_licenses';
$order_table_name = $wpdb->prefix . 'wplit_orders';

$sql = "DROP TABLE IF EXISTS $table_name";
$ordertable = "DROP TABLE IF EXISTS $order_table_name";

$wpdb->query($sql);
$wpdb->query($ordertable);

// 2.0 tables
foreach ( array( 'licenses', 'activations', 'license_orders', 'license_events' ) as $name ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wplit_{$name}" );
}

// Settings of the removed 1.x checkout
foreach ( array( 'wplit-checkout-page', 'wplit-licenses-page', 'wplit-stripe-settings-test-mode', 'wplit-stripe-settings-live-pk', 'wplit-stripe-settings-live-sk', 'wplit-stripe-settings-test-pk', 'wplit-stripe-settings-test-sk', 'wplit_checkout_notice_dismissed', 'wplit_storage_version' ) as $option ) {
    delete_option( $option );
}

delete_option('wplit_schema_version');
delete_option('wplit_migration_state');
delete_option('wplit_keep_legacy_billing');
delete_option('wplit_legacy_rewrite_version');

delete_option("wplit_db_version");