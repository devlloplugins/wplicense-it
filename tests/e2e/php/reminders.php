<?php
/**
 * Helpers for the expiry reminder browser test.
 *
 *   php tests/e2e/php/reminders.php seed USER_ID EMAIL   licenses that expire at different times, and the product they are for
 *   php tests/e2e/php/reminders.php run            the daily maintenance, as WordPress cron would run it
 *   php tests/e2e/php/reminders.php due            makes the daily maintenance event due now (for wp-cron.php to run)
 *   php tests/e2e/php/reminders.php state          what WordPress holds about the job and the reminders (for a failure message)
 *   php tests/e2e/php/reminders.php lapsed         two licenses that ran out: 4 days ago, and 40 days ago
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

// The hooks of every cron event that is due now, other than the daily maintenance.
$overdue_hooks = static function (): array {
	$hooks = array();

	foreach ( (array) _get_cron_array() as $timestamp => $events ) {
		if ( $timestamp > time() ) {
			continue;
		}

		foreach ( array_keys( (array) $events ) as $hook ) {
			if ( \Devllo\WPLicenseIt\Admin\Maintenance::HOOK !== $hook ) {
				$hooks[] = $hook . '@' . ( time() - $timestamp ) . 's';
			}
		}
	}

	return $hooks;
};

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
			'overdue'     => $overdue_hooks(),
			'sent_events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wplit_license_events WHERE type='reminder_sent'" ),
		)
	);
	exit( 0 );
}

if ( 'due' === $op ) {
	// Only the daily maintenance is due, so the request below does not depend on what else WordPress or WooCommerce left waiting.
	$cron = (array) _get_cron_array();

	foreach ( $cron as $timestamp => $events ) {
		if ( $timestamp <= time() ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( \Devllo\WPLicenseIt\Admin\Maintenance::HOOK !== $hook ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
	}

	wp_clear_scheduled_hook( \Devllo\WPLicenseIt\Admin\Maintenance::HOOK );
	wp_schedule_event( time() - 60, 'daily', \Devllo\WPLicenseIt\Admin\Maintenance::HOOK );
	echo wp_json_encode( array( 'ok' => true ) );
	exit( 0 );
}

if ( 'lapsed' === $op ) {
	$service = \Devllo\WPLicenseIt\Plugin::instance()->licenses();
	$product = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='wplit_product' AND post_title='Reminder Product' ORDER BY ID DESC LIMIT 1" );
	$out     = array();

	foreach ( array(
		'recent' => '-4 days',
		'old'    => '-40 days',
	) as $name => $when ) {
		$license      = $service->issue_license( $product, $name . '-lapsed@example.com', null, null, 1, new DateTimeImmutable( '+1 year', new DateTimeZone( 'UTC' ) ) );
		$out[ $name ] = $license->id;
		// Issued a year before it ran out; the daily job marks it expired.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wplit_licenses SET expires_at = %s, created_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', strtotime( $when ) ), gmdate( 'Y-m-d H:i:s', strtotime( $when . ' -1 year' ) ), $license->id ) );
	}

	echo wp_json_encode( $out );
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
