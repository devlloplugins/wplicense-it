<?php
/**
 * Settings screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Api\ClientIp;
use Devllo\WPLicenseIt\Api\LegacyGate;
use Devllo\WPLicenseIt\Api\LegacyUsage;
use Devllo\WPLicenseIt\Api\RestController;
use Devllo\WPLicenseIt\Migration\MigrationState;
use Devllo\WPLicenseIt\Migration\OptionStateStore;
use Devllo\WPLicenseIt\Api\WpProductCatalog;
use Devllo\WPLicenseIt\Reminders\OptOut;
use Devllo\WPLicenseIt\Reminders\ReminderService;
use Devllo\WPLicenseIt\Reminders\ReminderSettings;
use Devllo\WPLicenseIt\Reminders\TestReminder;
use Devllo\WPLicenseIt\Reminders\WpReminderMailer;
use Devllo\WPLicenseIt\Renewals\RenewalSettings;
use Devllo\WPLicenseIt\WooCommerce\WooAdapter;

/**
 * Licensing settings, built on the WordPress Settings API.
 */
final class SettingsScreen {

	private const GROUP = 'wplit_licensing';
	private const PAGE  = 'wplit-licensing';

	public const TEST_REMINDER_ACTION = 'wplit_test_reminder';
	public const RESUME_ACTION        = 'wplit_resume_reminders';
	private const OPTOUTS_SHOWN       = 100;

	public const OPTION_FAILURES            = 'wplit_rate_limit_failures';
	public const OPTION_WINDOW              = 'wplit_rate_limit_minutes';
	public const OPTION_RETENTION           = 'wplit_event_retention_years';
	public const OPTION_DELETE_ON_UNINSTALL = 'wplit_delete_data_on_uninstall';

	/**
	 * Registers the settings.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::TEST_REMINDER_ACTION, array( $this, 'send_test_reminder' ) );
		add_action( 'admin_post_' . self::RESUME_ACTION, array( $this, 'resume_reminders' ) );
	}

	/**
	 * Registers options, sections and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			WooAdapter::OPTION_ISSUE_ON,
			array(
				'type'              => 'string',
				'default'           => 'completed',
				'sanitize_callback' => array( self::class, 'sanitize_issue_on' ),
			)
		);
		register_setting(
			self::GROUP,
			'wplit_keep_legacy_billing',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION_FAILURES,
			array(
				'type'              => 'integer',
				'default'           => 20,
				'sanitize_callback' => fn( $v ): int => self::clamp( $v, 1, 1000, 20 ),
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION_WINDOW,
			array(
				'type'              => 'integer',
				'default'           => 15,
				'sanitize_callback' => fn( $v ): int => self::clamp( $v, 1, 1440, 15 ),
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION_RETENTION,
			array(
				'type'              => 'integer',
				'default'           => 2,
				'sanitize_callback' => fn( $v ): int => self::clamp( $v, 0, 20, 2 ),
			)
		);

		register_setting(
			self::GROUP,
			ReminderSettings::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
		register_setting(
			self::GROUP,
			ReminderSettings::OPTION_DAYS,
			array(
				'type'              => 'string',
				'default'           => ReminderSettings::DEFAULT_DAYS,
				'sanitize_callback' => array( ReminderSettings::class, 'sanitize_days' ),
			)
		);
		register_setting(
			self::GROUP,
			ReminderSettings::OPTION_AFTER_ENABLED,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
		register_setting(
			self::GROUP,
			ReminderSettings::OPTION_AFTER_DAYS,
			array(
				'type'              => 'integer',
				'default'           => ReminderSettings::DEFAULT_AFTER_DAYS,
				'sanitize_callback' => array( ReminderSettings::class, 'clean_after_days' ),
			)
		);
		register_setting(
			self::GROUP,
			ReminderSettings::OPTION_URL,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		register_setting(
			self::GROUP,
			RenewalSettings::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
		register_setting(
			self::GROUP,
			RenewalSettings::OPTION_DAYS,
			array(
				'type'              => 'integer',
				'default'           => RenewalSettings::DEFAULT_DAYS,
				'sanitize_callback' => array( RenewalSettings::class, 'clamp_days' ),
			)
		);

		register_setting(
			self::GROUP,
			ClientIp::OPTION_SOURCE,
			array(
				'type'              => 'string',
				'default'           => ClientIp::DIRECT,
				'sanitize_callback' => array( ClientIp::class, 'sanitize_source' ),
			)
		);
		register_setting(
			self::GROUP,
			ClientIp::OPTION_PROXIES,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( ClientIp::class, 'sanitize_proxies' ),
			)
		);
		register_setting(
			self::GROUP,
			LegacyGate::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
		register_setting(
			self::GROUP,
			self::OPTION_DELETE_ON_UNINSTALL,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		add_settings_section( 'wplit_sales', __( 'Selling with WooCommerce', 'wplicense-it' ), '__return_false', self::PAGE );
		add_settings_field( WooAdapter::OPTION_ISSUE_ON, __( 'Issue licenses when an order is', 'wplicense-it' ), array( $this, 'field_issue_on' ), self::PAGE, 'wplit_sales', array( 'label_for' => WooAdapter::OPTION_ISSUE_ON ) );

		add_settings_section( 'wplit_invoices', __( 'Renewal invoices', 'wplicense-it' ), array( $this, 'section_invoices' ), self::PAGE );
		add_settings_field( RenewalSettings::OPTION_ENABLED, __( 'Invoice customers', 'wplicense-it' ), array( $this, 'field_invoices_enabled' ), self::PAGE, 'wplit_invoices' );
		add_settings_field( RenewalSettings::OPTION_DAYS, __( 'Days before expiry', 'wplicense-it' ), array( $this, 'field_invoice_days' ), self::PAGE, 'wplit_invoices', array( 'label_for' => RenewalSettings::OPTION_DAYS ) );

		add_settings_section( 'wplit_reminders', __( 'Expiry reminders', 'wplicense-it' ), array( $this, 'section_reminders' ), self::PAGE );
		add_settings_field( ReminderSettings::OPTION_ENABLED, __( 'Email customers', 'wplicense-it' ), array( $this, 'field_reminders_enabled' ), self::PAGE, 'wplit_reminders' );
		add_settings_field( ReminderSettings::OPTION_DAYS, __( 'Days before expiry', 'wplicense-it' ), array( $this, 'field_reminder_days' ), self::PAGE, 'wplit_reminders', array( 'label_for' => ReminderSettings::OPTION_DAYS ) );
		add_settings_field( ReminderSettings::OPTION_AFTER_ENABLED, __( 'After expiry', 'wplicense-it' ), array( $this, 'field_reminder_after' ), self::PAGE, 'wplit_reminders', array( 'label_for' => ReminderSettings::OPTION_AFTER_DAYS ) );
		add_settings_field( ReminderSettings::OPTION_URL, __( 'Renewal address', 'wplicense-it' ), array( $this, 'field_reminder_url' ), self::PAGE, 'wplit_reminders', array( 'label_for' => ReminderSettings::OPTION_URL ) );
		add_settings_field( 'wplit_reminder_optouts', __( 'Stopped by customers', 'wplicense-it' ), array( $this, 'field_optouts' ), self::PAGE, 'wplit_reminders' );
		add_settings_field( 'wplit_test_reminder', __( 'Test email', 'wplicense-it' ), array( $this, 'field_test_reminder' ), self::PAGE, 'wplit_reminders' );

		add_settings_section( 'wplit_api', __( 'API protection', 'wplicense-it' ), array( $this, 'section_api' ), self::PAGE );
		add_settings_field( self::OPTION_FAILURES, __( 'Failed lookups allowed', 'wplicense-it' ), array( $this, 'field_failures' ), self::PAGE, 'wplit_api', array( 'label_for' => self::OPTION_FAILURES ) );
		add_settings_field( self::OPTION_WINDOW, __( 'Time window (minutes)', 'wplicense-it' ), array( $this, 'field_window' ), self::PAGE, 'wplit_api', array( 'label_for' => self::OPTION_WINDOW ) );
		add_settings_field( ClientIp::OPTION_SOURCE, __( 'Visitors reach this site', 'wplicense-it' ), array( $this, 'field_client_ip' ), self::PAGE, 'wplit_api' );
		add_settings_field( LegacyGate::OPTION_ENABLED, __( 'Old 1.x API addresses', 'wplicense-it' ), array( $this, 'field_legacy_api' ), self::PAGE, 'wplit_api' );

		add_settings_section( 'wplit_data', __( 'Data', 'wplicense-it' ), '__return_false', self::PAGE );
		add_settings_field( self::OPTION_RETENTION, __( 'Keep license history for (years)', 'wplicense-it' ), array( $this, 'field_retention' ), self::PAGE, 'wplit_data', array( 'label_for' => self::OPTION_RETENTION ) );
		add_settings_field( 'wplit_keep_legacy_billing', __( 'Billing details from 1.x orders', 'wplicense-it' ), array( $this, 'field_billing' ), self::PAGE, 'wplit_data' );
		add_settings_field( self::OPTION_DELETE_ON_UNINSTALL, __( 'When the plugin is deleted', 'wplicense-it' ), array( $this, 'field_delete_on_uninstall' ), self::PAGE, 'wplit_data' );
	}

	/**
	 * Accepts only the two valid values.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_issue_on( $value ): string {
		return 'processing' === $value ? 'processing' : 'completed';
	}

	/**
	 * Keeps a number inside a range, with a default for junk.
	 *
	 * @param mixed $value   Submitted value.
	 * @param int   $min     Smallest allowed.
	 * @param int   $max     Largest allowed.
	 * @param int   $default Value for non-numbers.
	 */
	public static function clamp( $value, int $min, int $max, int $default ): int {
		return is_numeric( $value ) ? max( $min, min( $max, (int) $value ) ) : $default;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		Capabilities::require_manage();

		echo '<div class="wrap"><h1>' . esc_html__( 'Licensing settings', 'wplicense-it' ) . '</h1>';
		settings_errors();
		$this->test_reminder_notice();

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';

		$this->status_panel();

		echo '</div>';
	}

	/**
	 * Issue-on field.
	 */
	public function field_issue_on(): void {
		$value = (string) get_option( WooAdapter::OPTION_ISSUE_ON, 'completed' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<p class="description">' . esc_html__( 'WooCommerce is not active. Install it to sell licenses from your store.', 'wplicense-it' ) . '</p>';
		}

		echo '<select id="' . esc_attr( WooAdapter::OPTION_ISSUE_ON ) . '" name="' . esc_attr( WooAdapter::OPTION_ISSUE_ON ) . '">';
		echo '<option value="completed"' . selected( $value, 'completed', false ) . '>' . esc_html__( 'Completed (after you or WooCommerce complete it)', 'wplicense-it' ) . '</option>';
		echo '<option value="processing"' . selected( $value, 'processing', false ) . '>' . esc_html__( 'Processing (as soon as payment is received)', 'wplicense-it' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Orders paid online (by card or PayPal, for example) that hold only licenses and other virtual and downloadable products are completed automatically, so "Completed" is right for most stores. Orders paid by bank transfer, check or cash on delivery wait until you complete them, and so do orders with something to ship.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Renewal invoices section text.
	 */
	public function section_invoices(): void {
		echo '<p>' . esc_html__( 'Some days before a license expires, create an order for its renewal and email the customer WooCommerce\'s invoice with a link to pay it. The customer pays with any payment method your store offers, and the license is renewed when the order is paid and completed. Needs WooCommerce and a product that sells the license.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Renewal invoices on or off.
	 */
	public function field_invoices_enabled(): void {
		echo '<label><input type="checkbox" name="' . esc_attr( RenewalSettings::OPTION_ENABLED ) . '" value="1"' . checked( RenewalSettings::enabled(), true, false ) . '> ' . esc_html__( 'Send renewal invoices', 'wplicense-it' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off until you turn it on. One invoice is made per expiry date and recorded in the license history. An unpaid invoice is cancelled if the license is renewed another way. Licenses that never expire, or whose product has no price, are skipped.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Renewal invoice days field.
	 */
	public function field_invoice_days(): void {
		echo '<input type="number" min="1" max="' . esc_attr( (string) RenewalSettings::MAX_DAYS ) . '" class="small-text" id="' . esc_attr( RenewalSettings::OPTION_DAYS ) . '" name="' . esc_attr( RenewalSettings::OPTION_DAYS ) . '" value="' . esc_attr( (string) RenewalSettings::days() ) . '">';
	}

	/**
	 * Reminders section text.
	 */
	public function section_reminders(): void {
		echo '<p>' . esc_html__( 'Email a customer before their license expires, with a link to renew. The check runs once a day. Each reminder is sent once per expiry date, and a renewal starts over. It is recorded in the license history.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Reminders on or off.
	 */
	public function field_reminders_enabled(): void {
		echo '<label><input type="checkbox" name="' . esc_attr( ReminderSettings::OPTION_ENABLED ) . '" value="1"' . checked( ReminderSettings::enabled(), true, false ) . '> ' . esc_html__( 'Send expiry reminders', 'wplicense-it' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off until you turn it on, so nobody is emailed by surprise. Licenses that never expire, and revoked or refunded licenses, get nothing.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Reminder days field.
	 */
	public function field_reminder_days(): void {
		echo '<input type="text" class="regular-text" id="' . esc_attr( ReminderSettings::OPTION_DAYS ) . '" name="' . esc_attr( ReminderSettings::OPTION_DAYS ) . '" value="' . esc_attr( implode( ', ', ReminderSettings::days() ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Up to five numbers of days, separated by commas. 30, 7 sends one email within 30 days of expiry and another within 7 days.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * The email after expiry: on or off, and how many days after.
	 */
	public function field_reminder_after(): void {
		echo '<label><input type="checkbox" name="' . esc_attr( ReminderSettings::OPTION_AFTER_ENABLED ) . '" value="1"' . checked( ReminderSettings::after_enabled(), true, false ) . '> ' . esc_html__( 'Also email once after a license has expired', 'wplicense-it' ) . '</label><br>';
		printf(
			/* translators: %s: number field. */
			esc_html__( '%s days after the expiry date', 'wplicense-it' ),
			'<input type="number" min="1" max="' . esc_attr( (string) ReminderSettings::MAX_AFTER_DAYS ) . '" class="small-text" id="' . esc_attr( ReminderSettings::OPTION_AFTER_DAYS ) . '" name="' . esc_attr( ReminderSettings::OPTION_AFTER_DAYS ) . '" value="' . esc_attr( (string) ReminderSettings::after_days() ) . '">'
		);
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: number of days. */
				__( 'Says the license expired, and links to renew. Off until you turn it on, separately from the reminders before expiry. Each expiry date gets it once, recorded in the license history. Only licenses that reached that day in the last %d days are emailed, so turning it on does not write to customers whose license ran out long ago.', 'wplicense-it' ),
				ReminderService::LATE_DAYS
			)
		) . '</p>';
	}

	/**
	 * Renewal address field.
	 */
	public function field_reminder_url(): void {
		echo '<input type="url" class="regular-text" id="' . esc_attr( ReminderSettings::OPTION_URL ) . '" name="' . esc_attr( ReminderSettings::OPTION_URL ) . '" value="' . esc_attr( ReminderSettings::custom_url() ) . '" placeholder="https://">';
		echo '<p class="description">' . esc_html__( 'Where the email sends customers. Leave empty to use the Licenses page of My Account in your store (the customer logs in and presses Renew), or your home page when there is no store.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * The addresses that stopped the reminder emails, newest first, each with a way to turn them back on.
	 */
	public function field_optouts(): void {
		$list = OptOut::all();

		echo '<p class="description">' . esc_html__( 'Every reminder email has a link to stop them, and customers with an account have a switch on the Licenses page of My Account. It covers every license with that address, before and after expiry.', 'wplicense-it' ) . '</p>';

		if ( array() === $list ) {
			echo '<p id="wplit-optouts-none">' . esc_html__( 'No customer has stopped them.', 'wplicense-it' ) . '</p>';

			return;
		}

		/* translators: %d: number of email addresses. */
		echo '<p>' . esc_html( sprintf( _n( '%d address gets no reminder emails.', '%d addresses get no reminder emails.', count( $list ), 'wplicense-it' ), count( $list ) ) ) . '</p>';
		echo '<table class="widefat striped" id="wplit-optouts" style="max-width:760px"><thead><tr><th>' . esc_html__( 'Email address', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Since', 'wplicense-it' ) . '</th><th>' . esc_html__( 'How', 'wplicense-it' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( array_slice( $list, 0, self::OPTOUTS_SHOWN, true ) as $email => $entry ) {
			$since  = strtotime( (string) $entry['at'] . ' UTC' );
			$resume = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::RESUME_ACTION,
						'email'  => rawurlencode( (string) $email ),
					),
					admin_url( 'admin-post.php' )
				),
				self::RESUME_ACTION . '_' . $email
			);

			echo '<tr><td>' . esc_html( (string) $email ) . '</td>';
			echo '<td>' . esc_html( false === $since ? '' : wp_date( (string) get_option( 'date_format' ), $since ) ) . '</td>';
			echo '<td>' . esc_html( OptOut::VIA_ACCOUNT === $entry['via'] ? __( 'In My Account', 'wplicense-it' ) : __( 'From an email', 'wplicense-it' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $resume ) . '">' . esc_html__( 'Turn back on', 'wplicense-it' ) . '</a></td></tr>';
		}

		echo '</tbody></table>';

		if ( count( $list ) > self::OPTOUTS_SHOWN ) {
			/* translators: %d: number of addresses shown. */
			echo '<p class="description">' . esc_html( sprintf( __( 'The newest %d are shown.', 'wplicense-it' ), self::OPTOUTS_SHOWN ) ) . '</p>';
		}
	}

	/**
	 * Turns the reminder emails back on for an address, at the owner's request.
	 */
	public function resume_reminders(): void {
		Capabilities::require_manage();

		$email = isset( $_GET['email'] ) ? OptOut::key( sanitize_email( wp_unslash( $_GET['email'] ) ) ) : '';
		check_admin_referer( self::RESUME_ACTION . '_' . $email );

		OptOut::resume( $email );

		wp_safe_redirect( add_query_arg( 'wplit_test_mail', 'resumed', Urls::settings() ) );
		exit;
	}

	/**
	 * The test email button. A link, because this whole section sits inside the settings form.
	 */
	public function field_test_reminder(): void {
		$url = wp_nonce_url( add_query_arg( 'action', self::TEST_REMINDER_ACTION, admin_url( 'admin-post.php' ) ), self::TEST_REMINDER_ACTION );

		echo '<a class="button" id="wplit-test-reminder" href="' . esc_url( $url ) . '">' . esc_html__( 'Send me a test email', 'wplicense-it' ) . '</a> ';
		echo '<a class="button" id="wplit-test-reminder-after" href="' . esc_url( add_query_arg( 'kind', 'after', $url ) ) . '">' . esc_html__( 'Send me the email after expiry', 'wplicense-it' ) . '</a>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: the administrator's email address. */
				__( 'Sends the reminder a customer would get to %s, for your first product and the first number of days above (or the email after expiry), with [Test] in the subject. It uses the saved settings, so save your changes first. It works while reminders are off, and nothing is recorded on any license.', 'wplicense-it' ),
				wp_get_current_user()->user_email
			)
		) . '</p>';
	}

	/**
	 * Sends the test reminder to the administrator and comes back.
	 */
	public function send_test_reminder(): void {
		Capabilities::require_manage();
		check_admin_referer( self::TEST_REMINDER_ACTION );

		$user     = wp_get_current_user();
		$products = get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above; only picks one of two emails.
		$days    = isset( $_GET['kind'] ) && 'after' === $_GET['kind'] ? -ReminderSettings::after_days() : ( ReminderSettings::days()[0] ?? 30 );
		$license = TestReminder::sample( (int) ( $products[0] ?? 0 ), (string) $user->user_email, $user->ID > 0 ? (int) $user->ID : null, $days, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		$sent    = ( new TestReminder( new WpReminderMailer( new WpProductCatalog() ) ) )->send( $license, $days );

		wp_safe_redirect( add_query_arg( 'wplit_test_mail', $sent ? 'sent' : 'failed', Urls::settings() ) );
		exit;
	}

	/**
	 * What happened to the test email.
	 */
	private function test_reminder_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects one of two fixed messages.
		$result = isset( $_GET['wplit_test_mail'] ) ? sanitize_key( wp_unslash( $_GET['wplit_test_mail'] ) ) : '';
		$email  = wp_get_current_user()->user_email;

		if ( 'resumed' === $result ) {
			printf( '<div class="notice notice-success is-dismissible" role="status"><p>%s</p></div>', esc_html__( 'Reminder emails go to that address again.', 'wplicense-it' ) );
		} elseif ( 'sent' === $result ) {
			/* translators: %s: email address. */
			printf( '<div class="notice notice-success is-dismissible" role="status"><p>%s</p></div>', esc_html( sprintf( __( 'A test reminder was sent to %s. If it does not arrive, check the spam folder and your site\'s email setup.', 'wplicense-it' ), $email ) ) );
		} elseif ( 'failed' === $result ) {
			/* translators: %s: email address. */
			printf( '<div class="notice notice-error" role="alert"><p>%s</p></div>', esc_html( sprintf( __( 'WordPress could not send the test reminder to %s. Check your site\'s email setup (an SMTP plugin, for example).', 'wplicense-it' ), $email ) ) );
		}
	}

	/**
	 * API section text.
	 */
	public function section_api(): void {
		echo '<p>' . esc_html__( 'Clients that send many wrong license keys are blocked for the rest of the time window. Right keys that are expired or revoked do not count.', 'wplicense-it' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Clients are told apart by their address. If this site is behind Cloudflare or another proxy, choose so below; otherwise all customers would share the proxy\'s address and one counter, and one client sending wrong keys could block everyone.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Failures field.
	 */
	public function field_failures(): void {
		echo '<input type="number" min="1" max="1000" class="small-text" id="' . esc_attr( self::OPTION_FAILURES ) . '" name="' . esc_attr( self::OPTION_FAILURES ) . '" value="' . esc_attr( (string) get_option( self::OPTION_FAILURES, 20 ) ) . '">';
	}

	/**
	 * Window field.
	 */
	public function field_window(): void {
		echo '<input type="number" min="1" max="1440" class="small-text" id="' . esc_attr( self::OPTION_WINDOW ) . '" name="' . esc_attr( self::OPTION_WINDOW ) . '" value="' . esc_attr( (string) get_option( self::OPTION_WINDOW, 15 ) ) . '">';
	}

	/**
	 * Retention field.
	 */
	public function field_retention(): void {
		echo '<input type="number" min="0" max="20" class="small-text" id="' . esc_attr( self::OPTION_RETENTION ) . '" name="' . esc_attr( self::OPTION_RETENTION ) . '" value="' . esc_attr( (string) get_option( self::OPTION_RETENTION, 2 ) ) . '"> <span class="description">' . esc_html__( '0 keeps it forever.', 'wplicense-it' ) . '</span>';
	}

	/**
	 * How the visitor's address is found.
	 */
	public function field_client_ip(): void {
		$source  = ClientIp::sanitize_source( get_option( ClientIp::OPTION_SOURCE, ClientIp::DIRECT ) );
		$choices = array(
			ClientIp::DIRECT     => __( 'Directly (no proxy or CDN in front of the site)', 'wplicense-it' ),
			ClientIp::CLOUDFLARE => __( 'Through Cloudflare (the CF-Connecting-IP header is used, only on requests that come from Cloudflare\'s addresses)', 'wplicense-it' ),
			ClientIp::PROXY      => __( 'Through another proxy or load balancer (the X-Forwarded-For header is used, only on requests from the proxies listed below)', 'wplicense-it' ),
		);

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Visitors reach this site', 'wplicense-it' ) . '</legend>';
		foreach ( $choices as $value => $label ) {
			echo '<label style="display:block;margin-bottom:4px"><input type="radio" name="' . esc_attr( ClientIp::OPTION_SOURCE ) . '" value="' . esc_attr( $value ) . '"' . checked( $source, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset>';

		echo '<p><label for="' . esc_attr( ClientIp::OPTION_PROXIES ) . '">' . esc_html__( 'Addresses of your proxies (for the last choice), one address or range per line, for example 10.0.0.0/8', 'wplicense-it' ) . '</label><br>';
		echo '<textarea class="large-text code" rows="3" id="' . esc_attr( ClientIp::OPTION_PROXIES ) . '" name="' . esc_attr( ClientIp::OPTION_PROXIES ) . '">' . esc_textarea( (string) get_option( ClientIp::OPTION_PROXIES, '' ) ) . '</textarea></p>';

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: the address the request came from, 2: the address counted for it. */
				__( 'This page was requested from %1$s, and the plugin counts it as %2$s. If the second is not your own address, the choice above is not right for this site.', 'wplicense-it' ),
				'' !== $remote ? $remote : '?',
				RestController::client_id()
			)
		) . '</p>';
	}

	/**
	 * Whether the 1.x API addresses are answered.
	 */
	public function field_legacy_api(): void {
		echo '<label><input type="checkbox" name="' . esc_attr( LegacyGate::OPTION_ENABLED ) . '" value="1"' . checked( LegacyGate::enabled(), true, false ) . '> ' . esc_html__( 'Answer the old 1.x API addresses (/api/wplicense-it-api/v1/...)', 'wplicense-it' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Plugins and themes released with WPLicense It 1.x check licenses and updates there, with the license key in the address, where web server logs can keep it. Switch this off when no more calls come (see Status below): those addresses then answer that they are gone, and copies of your plugins that still use them stop getting updates until they are updated by hand.', 'wplicense-it' ) . '</p>';
		echo '<p class="description">' . esc_html( self::legacy_api_text() ) . '</p>';
	}

	/**
	 * How much the 1.x API addresses are used, in one sentence.
	 */
	public static function legacy_api_text(): string {
		$state   = get_option( LegacyUsage::OPTION, array() );
		$summary = LegacyUsage::summary( is_array( $state ) ? $state : array(), time() );
		$prefix  = LegacyGate::enabled() ? __( 'On.', 'wplicense-it' ) : __( 'Switched off: they answer that they are gone.', 'wplicense-it' );

		if ( 0 === $summary['calls'] ) {
			return $prefix . ' ' . sprintf(
				/* translators: %d: number of days. */
				__( 'No calls in the last %d days.', 'wplicense-it' ),
				LegacyUsage::DAYS
			);
		}

		$names = array();
		foreach ( array_slice( array_keys( $summary['products'] ), 0, 3 ) as $product_id ) {
			// Calls can name any number; only a license product is named by its title.
			$title   = $product_id > 0 && 'wplit_product' === get_post_type( $product_id ) ? get_the_title( $product_id ) : '';
			$names[] = '' !== $title ? $title : '#' . $product_id;
		}

		return $prefix . ' ' . sprintf(
			/* translators: 1: number of calls, 2: number of days, 3: date of the last call, 4: product names. */
			_n( '%1$s call in the last %2$d days, the last on %3$s (%4$s).', '%1$s calls in the last %2$d days, the last on %3$s (%4$s).', $summary['calls'], 'wplicense-it' ),
			number_format_i18n( $summary['calls'] ),
			LegacyUsage::DAYS,
			wp_date( get_option( 'date_format' ), (int) $summary['last'] ),
			implode( ', ', $names )
		);
	}

	/**
	 * Whether deleting the plugin deletes its data.
	 */
	public function field_delete_on_uninstall(): void {
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_DELETE_ON_UNINSTALL ) . '" value="1"' . checked( (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, false ), true, false ) . '> ' . esc_html__( 'Delete all license data when the plugin is deleted', 'wplicense-it' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default: deleting the plugin from the Plugins page keeps every license, activation, order record and setting, so installing it again picks up where you left off. Turn this on only if you want them gone for good; this cannot be undone. Products and uploaded release files are WordPress content and stay either way.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Billing field.
	 */
	public function field_billing(): void {
		echo '<label><input type="checkbox" name="wplit_keep_legacy_billing" value="1"' . checked( (bool) get_option( 'wplit_keep_legacy_billing', true ), true, false ) . '> ' . esc_html__( 'Keep the address and phone number from migrated 1.x orders', 'wplicense-it' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Only used when the 1.x data is migrated. They contain personal data, and are covered by the WordPress personal data export and erase tools.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Read-only information: API address and migration state.
	 */
	private function status_panel(): void {
		$state = ( new OptionStateStore() )->load();

		echo '<h2>' . esc_html__( 'Status', 'wplicense-it' ) . '</h2><table class="widefat striped" style="max-width: 760px;"><tbody>';
		echo '<tr><th style="width: 200px;">' . esc_html__( 'API address', 'wplicense-it' ) . '</th><td><code>' . esc_html( rest_url( RestController::ROUTE_NAMESPACE ) ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( '1.x data migration', 'wplicense-it' ) . '</th><td>' . esc_html( $this->migration_text( $state ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Old 1.x API addresses', 'wplicense-it' ) . '</th><td>' . esc_html( self::legacy_api_text() ) . '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Migration state in words.
	 *
	 * @param MigrationState $state Migration state.
	 */
	private function migration_text( MigrationState $state ): string {
		switch ( $state->status ) {
			case MigrationState::DONE:
				/* translators: 1: licenses, 2: orders. */
				return sprintf( __( 'Finished. %1$d licenses and %2$d orders migrated.', 'wplicense-it' ), $state->licenses_migrated, $state->orders_migrated );

			case MigrationState::NEEDS_ATTENTION:
				return __( 'Needs attention. See the notice at the top of the admin screens.', 'wplicense-it' );

			default:
				/* translators: 1: licenses, 2: orders. */
				return sprintf( __( 'In progress. %1$d licenses and %2$d orders so far.', 'wplicense-it' ), $state->licenses_migrated, $state->orders_migrated );
		}
	}
}
