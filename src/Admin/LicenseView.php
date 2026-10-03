<?php
/**
 * Single license screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\WooCommerce\ProductFields;

/**
 * Shows one license: details, an edit form, status actions, renewal, active sites and history.
 */
final class LicenseView {

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
	}

	/**
	 * Renders the screen.
	 *
	 * @param int $license_id License ID.
	 */
	public function render( int $license_id ): void {
		$license = $this->licenses->find( $license_id );

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'License', 'wplicense-it' ) . '</h1> ';
		echo '<a href="' . esc_url( Urls::list() ) . '" class="page-title-action">' . esc_html__( 'All licenses', 'wplicense-it' ) . '</a><hr class="wp-header-end">';

		Notices::render();

		if ( null === $license ) {
			echo '<p>' . esc_html( Notices::errors()['not_found'] ) . '</p></div>';

			return;
		}

		$this->summary( $license );
		$this->status_actions( $license );
		$this->edit_form( $license );
		$this->sites( $license );
		$this->history( $license );

		echo '</div>';
	}

	/**
	 * Key, status, product, customer.
	 *
	 * @param License $license License.
	 */
	private function summary( License $license ): void {
		$title = get_the_title( $license->product_id );
		$user  = null !== $license->user_id ? get_userdata( $license->user_id ) : false;

		echo '<table class="widefat striped" style="max-width: 760px; margin-top: 1em;"><tbody>';
		$this->row( __( 'License key', 'wplicense-it' ), '<code style="font-size: 14px;">' . esc_html( $license->license_key ) . '</code>' );
		$this->row( __( 'Status', 'wplicense-it' ), LicensesTable::status_badge( $license ) );
		$this->row( __( 'Product', 'wplicense-it' ), esc_html( '' !== $title ? $title : '#' . $license->product_id ) );
		$this->row(
			__( 'Customer', 'wplicense-it' ),
			esc_html( $license->email ) . ( $user && current_user_can( 'edit_user', $user->ID ) ? ' · <a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->user_login ) . '</a>' : '' )
		);
		$this->row( __( 'Expires', 'wplicense-it' ), esc_html( null === $license->expires_at ? __( 'Never', 'wplicense-it' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $license->expires_at->getTimestamp() ) ) );
		$this->row( __( 'Sites', 'wplicense-it' ), esc_html( $this->licenses->activations_used( $license ) . ' / ' . ( 0 === $license->activation_limit ? '∞' : $license->activation_limit ) ) );
		$this->row( __( 'Created', 'wplicense-it' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $license->created_at->getTimestamp() ) ) );
		echo '</tbody></table>';
	}

	/**
	 * One summary row.
	 *
	 * @param string $label Label.
	 * @param string $html  Already escaped HTML.
	 */
	private function row( string $label, string $html ): void {
		echo '<tr><th style="width: 160px;">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers escape.
	}

	/**
	 * Revoke, refund, reinstate and renew.
	 *
	 * @param License $license License.
	 */
	private function status_actions( License $license ): void {
		echo '<h2>' . esc_html__( 'Actions', 'wplicense-it' ) . '</h2><p>';

		if ( in_array( $license->status, Status::terminal(), true ) ) {
			echo '<a class="button" href="' . esc_url( Urls::action( 'reinstate', $license->id ) ) . '">' . esc_html__( 'Reinstate', 'wplicense-it' ) . '</a> ';
		} else {
			echo '<a class="button" href="' . esc_url( Urls::action( 'revoke', $license->id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Revoke this license? It stops working immediately.', 'wplicense-it' ) ) . '\');">' . esc_html__( 'Revoke', 'wplicense-it' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( Urls::action( 'refund', $license->id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Mark as refunded? It stops working immediately.', 'wplicense-it' ) ) . '\');">' . esc_html__( 'Mark as refunded', 'wplicense-it' ) . '</a> ';
		}
		echo '</p>';

		if ( ! in_array( $license->status, Status::terminal(), true ) && null !== $license->expires_at ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Urls::form_fields( 'renew', $license->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in form_fields().
			echo '<label for="wplit-renew-period">' . esc_html__( 'Renew for', 'wplicense-it' ) . '</label> <select id="wplit-renew-period" name="period">';
			foreach ( ProductFields::periods() as $value => $label ) {
				if ( '' !== $value && 'lifetime' !== $value ) {
					echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
				}
			}
			echo '</select> <button type="submit" class="button">' . esc_html__( 'Renew', 'wplicense-it' ) . '</button> ';
			echo '<span class="description">' . esc_html__( 'Extends from the current expiry, or from today if it has expired.', 'wplicense-it' ) . '</span></form>';
		}
	}

	/**
	 * Edit form.
	 *
	 * @param License $license License.
	 */
	private function edit_form( License $license ): void {
		$date = DateInput::to_field( $license->expires_at, wp_timezone() );

		echo '<h2>' . esc_html__( 'Edit', 'wplicense-it' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Urls::form_fields( 'update', $license->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in form_fields().
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wplit-email">' . esc_html__( 'Customer email', 'wplicense-it' ) . '</label></th><td><input type="email" id="wplit-email" name="email" class="regular-text" value="' . esc_attr( $license->email ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="wplit-limit">' . esc_html__( 'Sites per license', 'wplicense-it' ) . '</label></th><td><input type="number" min="0" step="1" id="wplit-limit" name="activation_limit" class="small-text" value="' . esc_attr( (string) $license->activation_limit ) . '"> <span class="description">' . esc_html__( '0 is unlimited. Lowering it does not deactivate sites that are already active.', 'wplicense-it' ) . '</span></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Expiry', 'wplicense-it' ) . '</th><td>';
		echo '<label><input type="radio" name="expiry_mode" value="keep" checked> ' . esc_html__( 'Keep as it is', 'wplicense-it' ) . '</label><br>';
		echo '<label><input type="radio" name="expiry_mode" value="lifetime"> ' . esc_html__( 'Never expires', 'wplicense-it' ) . '</label><br>';
		echo '<label><input type="radio" name="expiry_mode" value="date"> ' . esc_html__( 'Valid through', 'wplicense-it' ) . ' <input type="date" name="expiry_date" value="' . esc_attr( $date ) . '"></label>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Save changes', 'wplicense-it' ) );
		echo '</form>';
	}

	/**
	 * Active sites, each with a Deactivate button.
	 *
	 * @param License $license License.
	 */
	private function sites( License $license ): void {
		$sites = $this->licenses->active_sites( $license );

		echo '<h2>' . esc_html__( 'Active sites', 'wplicense-it' ) . '</h2>';

		if ( array() === $sites ) {
			echo '<p>' . esc_html__( 'This license is not active on any site.', 'wplicense-it' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" style="max-width: 760px;"><thead><tr><th>' . esc_html__( 'Site', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Version', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Activated', 'wplicense-it' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $sites as $site ) {
			echo '<tr><td>' . esc_html( $site->site ) . ( $site->is_local ? ' <em>(' . esc_html__( 'local or staging, not counted', 'wplicense-it' ) . ')</em>' : '' ) . '</td>';
			echo '<td>' . esc_html( $site->product_version ?? '' ) . '</td>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ), $site->activated_at->getTimestamp() ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . Urls::form_fields( 'deactivate_site', $license->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in form_fields().
			echo '<input type="hidden" name="site" value="' . esc_attr( $site->site ) . '"><button type="submit" class="button button-small">' . esc_html__( 'Deactivate', 'wplicense-it' ) . '</button></form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Audit history.
	 *
	 * @param License $license License.
	 */
	private function history( License $license ): void {
		$events = $this->licenses->events( $license, 50 );

		echo '<h2>' . esc_html__( 'History', 'wplicense-it' ) . '</h2>';

		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No history yet.', 'wplicense-it' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" style="max-width: 760px;"><thead><tr><th style="width: 190px;">' . esc_html__( 'When', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Event', 'wplicense-it' ) . '</th><th>' . esc_html__( 'Details', 'wplicense-it' ) . '</th></tr></thead><tbody>';

		foreach ( $events as $event ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $event['created_at']->getTimestamp() ) ) . '</td>';
			echo '<td>' . esc_html( self::event_label( $event['type'] ) ) . '</td>';
			echo '<td>' . esc_html( self::event_details( $event['data'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Readable name of an event type.
	 *
	 * @param string $type Event type.
	 */
	public static function event_label( string $type ): string {
		$labels = array(
			EventLog::ISSUED      => __( 'Issued', 'wplicense-it' ),
			EventLog::ACTIVATED   => __( 'Site activated', 'wplicense-it' ),
			EventLog::DEACTIVATED => __( 'Site deactivated', 'wplicense-it' ),
			EventLog::RENEWED     => __( 'Renewed', 'wplicense-it' ),
			EventLog::EXPIRED     => __( 'Expired', 'wplicense-it' ),
			EventLog::REVOKED     => __( 'Revoked', 'wplicense-it' ),
			EventLog::REFUNDED    => __( 'Refunded', 'wplicense-it' ),
			EventLog::REINSTATED  => __( 'Reinstated', 'wplicense-it' ),
			EventLog::UPDATED     => __( 'Edited', 'wplicense-it' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * One-line summary of an event's data, such as "email: a@x.com → b@x.com".
	 *
	 * @param array<string, mixed> $data Event data.
	 */
	public static function event_details( array $data ): string {
		$parts = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) && 2 === count( $value ) ) {
				$parts[] = $key . ': ' . self::scalar( $value[0] ) . ' → ' . self::scalar( $value[1] );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$parts[] = $key . ': ' . self::scalar( $value );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Text for a value in the history.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( $value ): string {
		return null === $value || '' === $value ? '—' : (string) $value;
	}
}
