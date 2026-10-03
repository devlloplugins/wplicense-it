<?php
/**
 * Licenses screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Licenses\LicenseException;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;

/**
 * The Licenses admin page: the list, and one license when ?license=ID is set.
 */
final class LicensesScreen {

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
	}

	/**
	 * Runs before the page renders: screen options and bulk actions (which redirect, so they cannot wait for output).
	 */
	public function load(): void {
		Capabilities::require_manage();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the view.
		if ( isset( $_GET['license'] ) ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Licenses per page', 'wplicense-it' ),
				'default' => 20,
				'option'  => LicensesTable::PER_PAGE_OPTION,
			)
		);

		$this->maybe_handle_bulk_action();
	}

	/**
	 * Saves the "licenses per page" screen option.
	 *
	 * @param mixed  $status Current value (false to ignore).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return LicensesTable::PER_PAGE_OPTION === $option ? max( 1, min( 200, (int) $value ) ) : $status;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		Capabilities::require_manage();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the view.
		$license_id = isset( $_GET['license'] ) ? absint( wp_unslash( $_GET['license'] ) ) : 0;

		if ( $license_id > 0 ) {
			( new LicenseView( $this->licenses ) )->render( $license_id );

			return;
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new LicensesTable( $this->licenses );
		$table->prepare_items();

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Licenses', 'wplicense-it' ) . '</h1> ';
		echo '<a href="' . esc_url( Urls::add() ) . '" class="page-title-action">' . esc_html__( 'Add license', 'wplicense-it' ) . '</a><hr class="wp-header-end">';

		Notices::render();
		$table->views();

		echo '<form method="get"><input type="hidden" name="post_type" value="wplit_product"><input type="hidden" name="page" value="' . esc_attr( Urls::PAGE_LICENSES ) . '">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Keeps the current filter while searching.
		if ( isset( $_GET['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( __( 'Search key or email', 'wplicense-it' ), 'wplit-license' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( Urls::list() ) . '">';
		wp_nonce_field( 'bulk-licenses' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Revokes or reinstates the selected licenses.
	 */
	private function maybe_handle_bulk_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below.
		$action = isset( $_POST['action'] ) && '-1' !== $_POST['action'] ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ( isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '' );
		$ids    = isset( $_POST['license'] ) && is_array( $_POST['license'] ) ? array_map( 'absint', wp_unslash( $_POST['license'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $action, array( 'revoke', 'reinstate' ), true ) || array() === $ids ) {
			return;
		}

		check_admin_referer( 'bulk-licenses' );

		$done = 0;

		foreach ( array_unique( $ids ) as $id ) {
			try {
				if ( 'revoke' === $action ) {
					$this->licenses->revoke_license( $id, Status::REVOKED );
				} else {
					$this->licenses->reinstate_license( $id );
				}
				++$done;
			} catch ( LicenseException $e ) {
				continue; // Already in that state, or gone. Skip it.
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'wplit_notice' => 'bulk',
					'wplit_count'  => $done,
				),
				Urls::list()
			)
		);
		exit;
	}
}
