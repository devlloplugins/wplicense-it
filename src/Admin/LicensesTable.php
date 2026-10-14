<?php
/**
 * License list table.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseQuery;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use WP_List_Table;

/**
 * The searchable, filterable, sortable list of licenses.
 */
final class LicensesTable extends WP_List_Table {

	public const PER_PAGE_OPTION = 'wplit_licenses_per_page';

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 */
	public function __construct( private LicenseService $licenses ) {
		parent::__construct(
			array(
				'singular' => 'license',
				'plural'   => 'licenses',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Reads the request, runs the search and sets up pagination.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'license' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$input = array(
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'product_id' => isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0,
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'    => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
			'order'      => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '',
			'page'       => $this->get_pagenum(),
			'per_page'   => $this->get_items_per_page( self::PER_PAGE_OPTION, 20 ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = LicenseQuery::from_input( $input );
		$page  = $this->licenses->search( $query );

		$this->items = $page->items;

		$this->set_pagination_args(
			array(
				'total_items' => $page->total,
				'per_page'    => $query->per_page,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox" />',
			'license' => __( 'License', 'wplicense-it' ),
			'product' => __( 'Product', 'wplicense-it' ),
			'email'   => __( 'Customer', 'wplicense-it' ),
			'status'  => __( 'Status', 'wplicense-it' ),
			'expires' => __( 'Expires', 'wplicense-it' ),
			'sites'   => __( 'Sites', 'wplicense-it' ),
			'created' => __( 'Created', 'wplicense-it' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'email'   => array( 'email', false ),
			'status'  => array( 'status', false ),
			'expires' => array( 'expires_at', false ),
			'created' => array( 'created_at', true ),
		);
	}

	/**
	 * Status links with counts.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts = $this->licenses->status_counts();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$active = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$labels = array(
			Status::ACTIVE   => __( 'Active', 'wplicense-it' ),
			Status::EXPIRED  => __( 'Expired', 'wplicense-it' ),
			Status::REVOKED  => __( 'Revoked', 'wplicense-it' ),
			Status::REFUNDED => __( 'Refunded', 'wplicense-it' ),
		);

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( Urls::list() ),
				'' === $active ? ' class="current"' : '',
				esc_html__( 'All', 'wplicense-it' ),
				array_sum( $counts )
			),
		);

		foreach ( $labels as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( Urls::list( array( 'status' => $status ) ) ),
				$active === $status ? ' class="current"' : '',
				esc_html( $label ),
				$counts[ $status ]
			);
		}

		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'revoke'    => __( 'Revoke', 'wplicense-it' ),
			'reinstate' => __( 'Reinstate', 'wplicense-it' ),
		);
	}

	/**
	 * Product filter above the table.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$selected = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		$products = get_posts(
			array(
				'post_type'      => 'wplit_product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<div class="alignleft actions"><select name="product_id"><option value="0">' . esc_html__( 'All products', 'wplicense-it' ) . '</option>';
		foreach ( $products as $product ) {
			printf( '<option value="%d"%s>%s</option>', (int) $product->ID, selected( $selected, $product->ID, false ), esc_html( $product->post_title ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'wplicense-it' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param License $item License.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="license[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Key column with row actions.
	 *
	 * @param License $item License.
	 */
	protected function column_license( $item ): string {
		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( Urls::view( $item->id ) ), esc_html__( 'Manage', 'wplicense-it' ) ),
		);

		if ( in_array( $item->status, Status::terminal(), true ) ) {
			$actions['reinstate'] = sprintf( '<a href="%s">%s</a>', esc_url( Urls::action( 'reinstate', $item->id ) ), esc_html__( 'Reinstate', 'wplicense-it' ) );
		} else {
			$actions['revoke'] = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( Urls::action( 'revoke', $item->id ) ), esc_html__( 'Revoke', 'wplicense-it' ) );
		}

		return sprintf( '<strong><a class="row-title" href="%s"><code>%s</code></a></strong>', esc_url( Urls::view( $item->id ) ), esc_html( $item->license_key ) ) . $this->row_actions( $actions );
	}

	/**
	 * Product column.
	 *
	 * @param License $item License.
	 */
	protected function column_product( $item ): string {
		$title = get_the_title( $item->product_id );

		return esc_html( '' !== $title ? $title : '#' . $item->product_id );
	}

	/**
	 * Customer column.
	 *
	 * @param License $item License.
	 */
	protected function column_email( $item ): string {
		$html = esc_html( $item->email );

		if ( null !== $item->user_id && current_user_can( 'edit_user', $item->user_id ) ) {
			$html .= '<br><a href="' . esc_url( get_edit_user_link( $item->user_id ) ) . '">' . esc_html__( 'User profile', 'wplicense-it' ) . '</a>';
		}

		return $html;
	}

	/**
	 * Status column.
	 *
	 * @param License $item License.
	 */
	protected function column_status( $item ): string {
		return self::status_badge( $item );
	}

	/**
	 * Expiry column.
	 *
	 * @param License $item License.
	 */
	protected function column_expires( $item ): string {
		return null === $item->expires_at ? esc_html__( 'Never', 'wplicense-it' ) : esc_html( wp_date( get_option( 'date_format' ), $item->expires_at->getTimestamp() ) );
	}

	/**
	 * Sites column.
	 *
	 * @param License $item License.
	 */
	protected function column_sites( $item ): string {
		$limit = 0 === $item->activation_limit ? '∞' : (string) $item->activation_limit;

		return esc_html( $this->licenses->activations_used( $item ) . ' / ' . $limit );
	}

	/**
	 * Created column.
	 *
	 * @param License $item License.
	 */
	protected function column_created( $item ): string {
		return esc_html( wp_date( get_option( 'date_format' ), $item->created_at->getTimestamp() ) );
	}

	/**
	 * Message when there are no licenses.
	 */
	public function no_items(): void {
		esc_html_e( 'No licenses found.', 'wplicense-it' );
	}

	/**
	 * A coloured status label.
	 *
	 * @param License $license License.
	 */
	public static function status_badge( License $license ): string {
		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$state = $license->status;

		// A license past its expiry date is expired even before the daily job updates the stored status.
		if ( Status::ACTIVE === $state && $license->is_past_expiry( $now ) ) {
			$state = Status::EXPIRED;
		}

		$labels = array(
			Status::ACTIVE   => __( 'Active', 'wplicense-it' ),
			Status::EXPIRED  => __( 'Expired', 'wplicense-it' ),
			Status::REVOKED  => __( 'Revoked', 'wplicense-it' ),
			Status::REFUNDED => __( 'Refunded', 'wplicense-it' ),
		);

		return sprintf( '<span class="wplit-badge wplit-badge-%s">%s</span>', esc_attr( $state ), esc_html( $labels[ $state ] ?? $state ) );
	}
}
