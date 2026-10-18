<?php
/**
 * Helpers for the expiry reminder browser test.
 *
 *   php tests/e2e/php/reminders.php seed USER_ID EMAIL   licenses that expire at different times, and the product they are for
 *   php tests/e2e/php/reminders.php run            the daily maintenance, as WordPress cron would run it
 *   php tests/e2e/php/reminders.php due            makes the daily maintenance event due now (for wp-cron.php to run)
 *   php tests/e2e/php/reminders.php state          what WordPress holds about the job and the reminders (for a failure message)
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB -- A test helper that builds rows on purpose.

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';
define( 'WP_ADMIN', true );
require $root . '/wordpress/wp-load.php';

global $wpdb;

$op = $argv[1] ?? '';

if ( 'run' === $op ) {
	\Devllo\WPLicenseIt\Admin\Maintenance::run();
	echo wp_json_encode( array( 'ok' => true ) );
	exit( 0 );
}

if ( 'state' === $op ) {
	echo wp_json_encode(
		array(
			'enabled'     => get_option( 'wplit_reminders_enabled' ),
			'days'        => get_option( 'wplit_reminder_days' ),
			'next_run'    => wp_next_scheduled( \Devllo\WPLicenseIt\Admin\Maintenance::HOOK ),
			'now'         => time(),
			'doing_cron'  => get_transient( 'doing_cron' ),
			'expiring'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wplit_licenses WHERE status='active' AND expires_at > UTC_TIMESTAMP() AND expires_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY)" ),
			'db_now'      => $wpdb->get_var( 'SELECT UTC_TIMESTAMP()' ),
			'sent_events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wplit_license_events WHERE type='reminder_sent'" ),
		)
	);
	exit( 0 );
}

if ( 'due' === $op ) {
	wp_clear_scheduled_hook( \Devllo\WPLicenseIt\Admin\Maintenance::HOOK );
	wp_schedule_event( time() - 60, 'daily', \Devllo\WPLicenseIt\Admin\Maintenance::HOOK );
	echo wp_json_encode( array( 'ok' => true ) );
	exit( 0 );
}

if ( 'seed' !== $op ) {
	echo wp_json_encode( array( 'error' => 'unknown op' ) );
	exit( 1 );
}

foreach ( array( 'licenses', 'activations', 'license_orders', 'license_events' ) as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wplit_{$table}" );
}

$service = \Devllo\WPLicenseIt\Plugin::instance()->licenses();
$user_id = (int) ( $argv[2] ?? 0 );
$product = (int) wp_insert_post(
	array(
		'post_type'   => 'wplit_product',
		'post_status' => 'publish',
		'post_title'  => 'Reminder Product',
		'post_author' => 1,
	)
);
$utc     = new DateTimeZone( 'UTC' );
$in      = static fn( string $when ): DateTimeImmutable => new DateTimeImmutable( $when, $utc );
$ids     = array();

$issue = static function ( string $name, string $email, ?int $user, ?DateTimeImmutable $expires ) use ( $service, $product, &$ids ): \Devllo\WPLicenseIt\Licenses\License {
	$license      = $service->issue_license( $product, $email, $user, null, 1, $expires );
	$ids[ $name ] = array(
		'id'  => $license->id,
		'key' => $license->license_key,
	);

	return $license;
};

$issue( 'account', (string) ( $argv[3] ?? 'account-customer@example.com' ), $user_id > 0 ? $user_id : null, $in( '+25 days 06:00' ) );
$issue( 'guest', 'guest-customer@example.com', null, $in( '+5 days 06:00' ) );
$issue( 'far', 'far-customer@example.com', null, $in( '+100 days' ) );
$issue( 'lifetime', 'lifetime-customer@example.com', null, null );
$revoked = $issue( 'revoked', 'revoked-customer@example.com', null, $in( '+10 days' ) );
$service->revoke_license( $revoked->id );
$issue( 'later', 'later-customer@example.com', null, $in( '+20 days 06:00' ) );

echo wp_json_encode(
	array(
		'product'  => $product,
		'licenses' => $ids,
	)
);
