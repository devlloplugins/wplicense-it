<?php
/**
 * Settings screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Api\RestController;
use Devllo\WPLicenseIt\Migration\MigrationState;
use Devllo\WPLicenseIt\Migration\OptionStateStore;
use Devllo\WPLicenseIt\WooCommerce\WooAdapter;

/**
 * Licensing settings, built on the WordPress Settings API.
 */
final class SettingsScreen {

	private const GROUP = 'wplit_licensing';
	private const PAGE  = 'wplit-licensing';

	public const OPTION_FAILURES  = 'wplit_rate_limit_failures';
	public const OPTION_WINDOW    = 'wplit_rate_limit_minutes';
	public const OPTION_RETENTION = 'wplit_event_retention_years';

	/**
	 * Registers the settings.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
		register_setting( self::GROUP, self::OPTION_FAILURES, array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => fn( $v ): int => self::clamp( $v, 1, 1000, 20 ) ) );
		register_setting( self::GROUP, self::OPTION_WINDOW, array( 'type' => 'integer', 'default' => 15, 'sanitize_callback' => fn( $v ): int => self::clamp( $v, 1, 1440, 15 ) ) );
		register_setting( self::GROUP, self::OPTION_RETENTION, array( 'type' => 'integer', 'default' => 2, 'sanitize_callback' => fn( $v ): int => self::clamp( $v, 0, 20, 2 ) ) );

		add_settings_section( 'wplit_sales', __( 'Selling with WooCommerce', 'wplicense-it' ), '__return_false', self::PAGE );
		add_settings_field( WooAdapter::OPTION_ISSUE_ON, __( 'Issue licenses when an order is', 'wplicense-it' ), array( $this, 'field_issue_on' ), self::PAGE, 'wplit_sales' );

		add_settings_section( 'wplit_api', __( 'API protection', 'wplicense-it' ), array( $this, 'section_api' ), self::PAGE );
		add_settings_field( self::OPTION_FAILURES, __( 'Failed lookups allowed', 'wplicense-it' ), array( $this, 'field_failures' ), self::PAGE, 'wplit_api' );
		add_settings_field( self::OPTION_WINDOW, __( 'Time window (minutes)', 'wplicense-it' ), array( $this, 'field_window' ), self::PAGE, 'wplit_api' );

		add_settings_section( 'wplit_data', __( 'Data', 'wplicense-it' ), '__return_false', self::PAGE );
		add_settings_field( self::OPTION_RETENTION, __( 'Keep license history for (years)', 'wplicense-it' ), array( $this, 'field_retention' ), self::PAGE, 'wplit_data' );
		add_settings_field( 'wplit_keep_legacy_billing', __( 'Billing details from 1.x orders', 'wplicense-it' ), array( $this, 'field_billing' ), self::PAGE, 'wplit_data' );
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

		echo '<select name="' . esc_attr( WooAdapter::OPTION_ISSUE_ON ) . '">';
		echo '<option value="completed"' . selected( $value, 'completed', false ) . '>' . esc_html__( 'Completed (after you or WooCommerce complete it)', 'wplicense-it' ) . '</option>';
		echo '<option value="processing"' . selected( $value, 'processing', false ) . '>' . esc_html__( 'Processing (as soon as payment is received)', 'wplicense-it' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Virtual and downloadable products are completed automatically, so "Completed" is right for most stores.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * API section text.
	 */
	public function section_api(): void {
		echo '<p>' . esc_html__( 'Clients that send many wrong license keys are blocked for the rest of the time window. Right keys that are expired or revoked do not count.', 'wplicense-it' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'If this site is behind a proxy or CDN (for example Cloudflare), WordPress sees the proxy\'s address, so all customers would share one counter. Return the visitor\'s real address with the wplicense_it_client_ip filter.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Failures field.
	 */
	public function field_failures(): void {
		echo '<input type="number" min="1" max="1000" class="small-text" name="' . esc_attr( self::OPTION_FAILURES ) . '" value="' . esc_attr( (string) get_option( self::OPTION_FAILURES, 20 ) ) . '">';
	}

	/**
	 * Window field.
	 */
	public function field_window(): void {
		echo '<input type="number" min="1" max="1440" class="small-text" name="' . esc_attr( self::OPTION_WINDOW ) . '" value="' . esc_attr( (string) get_option( self::OPTION_WINDOW, 15 ) ) . '">';
	}

	/**
	 * Retention field.
	 */
	public function field_retention(): void {
		echo '<input type="number" min="0" max="20" class="small-text" name="' . esc_attr( self::OPTION_RETENTION ) . '" value="' . esc_attr( (string) get_option( self::OPTION_RETENTION, 2 ) ) . '"> <span class="description">' . esc_html__( '0 keeps it forever.', 'wplicense-it' ) . '</span>';
	}

	/**
	 * Billing field.
	 */
	public function field_billing(): void {
		echo '<label><input type="checkbox" name="wplit_keep_legacy_billing" value="1"' . checked( (bool) get_option( 'wplit_keep_legacy_billing', true ), true, false ) . '> ' . esc_html__( 'Keep the address and phone number from migrated 1.x orders', 'wplicense-it' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Only used when the 1.x data is migrated. They contain personal data. Delete them if a customer asks.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * Read-only information: API address and migration state.
	 */
	private function status_panel(): void {
		$state = ( new OptionStateStore() )->load();

		echo '<h2>' . esc_html__( 'Status', 'wplicense-it' ) . '</h2><table class="widefat striped" style="max-width: 760px;"><tbody>';
		echo '<tr><th style="width: 200px;">' . esc_html__( 'API address', 'wplicense-it' ) . '</th><td><code>' . esc_html( rest_url( RestController::ROUTE_NAMESPACE ) ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( '1.x data migration', 'wplicense-it' ) . '</th><td>' . esc_html( $this->migration_text( $state ) ) . '</td></tr>';
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
