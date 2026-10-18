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

		// The table lays its columns out at equal widths, which wrapped every license key onto three lines. Give the key room.
		echo '<style>.wp-list-table .column-license{width:29%}.wp-list-table .column-product{width:12%}.wp-list-table .column-email{width:15%}.wp-list-table .column-status{width:9%}.wp-list-table .column-sites{width:6%}.wp-list-table .column-expires,.wp-list-table .column-created{width:13%}.wp-list-table .column-license code{word-break:break-all;background:transparent;padding:0}@media screen and (max-width:782px){.wp-list-table .column-license{width:auto}}</style>';

		Notices::render();
		$table->views();

		echo '<form method="get"><input type="hidden" name="post_type" value="wplit_product"><input type="hidden" name="page" value="' . esc_attr( Urls::PAGE_LICENSES ) . '">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Keeps the current filter while searching.
		if ( isset( $_GET['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( __( 'Search key or email', 'wplicense-it' ), 'wplit-license' );
		echo '</form>';

		echo '<form method="post" id="wplit-licenses-form" action="' . esc_url( add_query_arg( $this->current_view_args(), Urls::list() ) ) . '">';
		wp_nonce_field( 'bulk-licenses' );
		$table->display();
		echo '</form></div>';

		// Revoking many licenses with one click on Apply asks first, as revoking one does.
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A few lines that only serve this screen.
		?>
		<script>
		( function () {
			var form = document.getElementById( 'wplit-licenses-form' );
			if ( ! form ) { return; }
			form.addEventListener( 'submit', function ( event ) {
				if ( event.submitter && 'filter_action' === event.submitter.name ) { return; }
				var action = form.querySelector( '[name="action"]' );
				var value = action ? action.value : '-1';
				if ( '-1' === value ) {
					var second = form.querySelector( '[name="action2"]' );
					value = second ? second.value : '-1';
				}
				var count = form.querySelectorAll( 'input[name="license[]"]:checked' ).length;
				if ( 'revoke' === value && count > 0 && ! window.confirm( <?php echo wp_json_encode( __( 'Revoke the selected licenses? They stop working immediately.', 'wplicense-it' ) ); ?> ) ) {
					event.preventDefault();
				}
			} );
		}() );
		</script>
		<?php
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * Revokes or reinstates the selected licenses.
	 */
	private function maybe_handle_bulk_action(): void {
		// The product filter sits inside the table's form, which posts. Turn it into a link, so it can be shared and bookmarked
		// and works like the search box and the status links do.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below.
		if ( isset( $_POST['filter_action'] ) ) {
			check_admin_referer( 'bulk-licenses' );

			$args = $this->current_view_args();
			unset( $args['paged'], $args['product_id'] );
			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified just above.

			if ( $product_id > 0 ) {
				$args['product_id'] = $product_id;
			}

			wp_safe_redirect( add_query_arg( $args, Urls::list() ) );
			exit;
		}

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
				continue;
				// Already in that state, or gone. Skip it.
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array_merge(
					$this->current_view_args(),
					array(
						'wplit_notice' => 'bulk',
						'wplit_count'  => $done,
					)
				),
				Urls::list()
			)
		);
		exit;
	}

	/**
	 * The parts of the address that say which view of the list this is: status, product, search, sorting and page.
	 *
	 * @return array<string, string>
	 */
	private function current_view_args(): array {
		$args = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, and only used to build a link back to the same view.
		foreach ( array( 'status', 'product_id', 's', 'orderby', 'order', 'paged' ) as $name ) {
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) && '' !== $_GET[ $name ] ) {
				$args[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $args;
	}
}
