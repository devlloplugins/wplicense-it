<?php
/**
 * The Plans box on the product edit screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Plans\PlanException;
use Devllo\WPLicenseIt\Plans\PlanInput;
use Devllo\WPLicenseIt\Plans\PlanResult;
use Devllo\WPLicenseIt\Plans\PlanRepository;
use Devllo\WPLicenseIt\Plans\PlanSync;
use Devllo\WPLicenseIt\Plans\ProductGateway;
use Devllo\WPLicenseIt\TestPurchase\CheckResult;
use Devllo\WPLicenseIt\TestPurchase\Fingerprint;
use Devllo\WPLicenseIt\TestPurchase\TestPurchase;
use Devllo\WPLicenseIt\TestPurchase\TestReport;
use Devllo\WPLicenseIt\WooCommerce\Money;
use WP_Post;

/**
 * A table of plans for a software product. Saving the product saves the plans and creates or updates one
 * WooCommerce product per plan. The row actions (keep a price, create again) are nonce-protected links.
 */
final class PlansBox {

	private const NONCE_FIELD   = 'wplit_plans_nonce';
	private const NONCE_ACTION  = 'wplit_save_plans';
	private const POST_FIELD    = 'wplit_plans';
	private const ACTION        = 'wplit_plan_action';
	private const REPORT_PREFIX = 'wplit_plans_report_';
	private const TEST_PREFIX   = 'wplit_plans_test_';
	private const SPARE_ROWS    = 3;

	/**
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * @var ProductGateway
	 */
	private $woo;

	/**
	 * @var PlanSync
	 */
	private $sync;

	/**
	 * @var TestPurchase
	 */
	private $tester;

	/**
	 * @var ProductCatalog
	 */
	private $catalog;

	/**
	 * Constructor.
	 *
	 * @param PlanRepository $plans   Plan storage.
	 * @param ProductGateway $woo     WooCommerce.
	 * @param TestPurchase   $tester  Runs the test purchase.
	 * @param ProductCatalog $catalog Software products and their zip files.
	 */
	public function __construct( PlanRepository $plans, ProductGateway $woo, TestPurchase $tester, ProductCatalog $catalog ) {
		$this->plans   = $plans;
		$this->woo     = $woo;
		$this->sync    = new PlanSync( $plans, $woo );
		$this->tester  = $tester;
		$this->catalog = $catalog;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_wplit_product', array( $this, 'add_box' ) );
		add_action( 'save_post_wplit_product', array( $this, 'save' ), 20, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * Adds the box.
	 */
	public function add_box(): void {
		add_meta_box( 'wplit_plans', __( 'Plans (what customers can buy)', 'wplicense-it' ), array( $this, 'render' ), 'wplit_product', 'normal', 'high' );
	}

	/**
	 * Draws the box.
	 *
	 * @param WP_Post $post The license product.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$this->render_report( $post->ID );
		$this->render_test_report( $post->ID );

		$currency = $this->woo->currency();
		$plans    = $this->plans->all( $post->ID );
		$rows     = $plans;
		for ( $i = 0, $room = PlanInput::MAX_PLANS - count( $rows ); $i < self::SPARE_ROWS && $i < $room; $i++ ) {
			// A spare row has no sites value either, so that an untouched row is submitted completely empty and skipped.
			$rows[] = new Plan( '', '', '', -1, 'P1Y' );
		}

		echo '<p class="description">' . esc_html__( 'Each plan becomes one WooCommerce product, created as a draft. The price here is the one that counts: if someone edits a price in WooCommerce, you will be asked which to keep.', 'wplicense-it' ) . '</p>';
		echo '<table class="widefat striped" style="margin-top:8px;width:100%"><thead><tr>';
		/* translators: %s: currency code. */
		$price_heading = sprintf( __( 'Price (%s)', 'wplicense-it' ), $currency );
		foreach ( array( __( 'Name', 'wplicense-it' ), $price_heading, __( 'Sites (0 = unlimited)', 'wplicense-it' ), __( 'Lasts', 'wplicense-it' ), __( 'In WooCommerce', 'wplicense-it' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $index => $plan ) {
			$this->render_row( $post->ID, (int) $index, $plan, $currency );
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Clear a row to remove the plan. Its WooCommerce product is set back to draft so it can no longer be bought; existing licenses keep working.', 'wplicense-it' ) . '</p>';
	}

	/**
	 * One row of the table.
	 *
	 * @param int    $software_id License product ID.
	 * @param int    $index       Row number.
	 * @param Plan   $plan        Plan, or an empty spare row.
	 * @param string $currency    Store currency.
	 */
	private function render_row( int $software_id, int $index, Plan $plan, string $currency ): void {
		$field = self::POST_FIELD . '[' . $index . ']';
		// Each field is named for the row it is in, so a screen reader says "Plan 2: Price" and not just "edit text".
		/* translators: 1: row number, 2: column name such as Price. */
		$label = static fn( string $column ): string => sprintf( __( 'Plan %1$d: %2$s', 'wplicense-it' ), $index + 1, $column );

		echo '<tr>';
		echo '<td><input type="hidden" name="' . esc_attr( $field ) . '[id]" value="' . esc_attr( $plan->id ) . '">';
		echo '<input type="text" aria-label="' . esc_attr( $label( __( 'Name', 'wplicense-it' ) ) ) . '" style="width:100%;min-width:9em" maxlength="' . esc_attr( (string) PlanInput::MAX_NAME ) . '" name="' . esc_attr( $field ) . '[name]" value="' . esc_attr( $plan->name ) . '" placeholder="' . esc_attr__( 'For example: 5 sites', 'wplicense-it' ) . '"></td>';
		echo '<td><input type="text" aria-label="' . esc_attr( $label( __( 'Price', 'wplicense-it' ) ) ) . '" inputmode="decimal" size="8" name="' . esc_attr( $field ) . '[price]" value="' . esc_attr( $plan->price ) . '"></td>';
		echo '<td><input type="number" aria-label="' . esc_attr( $label( __( 'Sites (0 = unlimited)', 'wplicense-it' ) ) ) . '" min="0" max="' . esc_attr( (string) PlanInput::MAX_SITES ) . '" style="width:6em" name="' . esc_attr( $field ) . '[sites]" value="' . esc_attr( $plan->sites < 0 ? '' : (string) $plan->sites ) . '"></td>';
		echo '<td><select aria-label="' . esc_attr( $label( __( 'Lasts', 'wplicense-it' ) ) ) . '" name="' . esc_attr( $field ) . '[period]">';
		foreach ( self::period_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $plan->period, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';
		echo '<td>';
		if ( '' !== $plan->id ) {
			$this->render_status( $software_id, $plan, $currency );
		} else {
			echo '<span class="description">' . esc_html__( 'Created when you save.', 'wplicense-it' ) . '</span>';
		}
		echo '</td></tr>';
	}

	/**
	 * What the seller needs to know about the WooCommerce product of a plan.
	 *
	 * @param int    $software_id License product ID.
	 * @param Plan   $plan        Saved plan.
	 * @param string $currency    Store currency.
	 */
	private function render_status( int $software_id, Plan $plan, string $currency ): void {
		if ( 0 === $plan->woo_product_id ) {
			echo '<span class="description">' . esc_html__( 'Not created yet. Save to retry.', 'wplicense-it' ) . '</span>';

			return;
		}

		$remote = $this->woo->find( $plan->woo_product_id );
		if ( null === $remote || $remote->is_trashed() ) {
			echo '<strong>' . esc_html__( 'Deleted in WooCommerce.', 'wplicense-it' ) . '</strong> ';
			echo '<a href="' . esc_url( $this->action_url( 'forget', $software_id, $plan->id ) ) . '">' . esc_html__( 'Create it again', 'wplicense-it' ) . '</a>';

			return;
		}

		$edit = get_edit_post_link( $plan->woo_product_id );
		$name = 'publish' === $remote->status ? __( 'On sale', 'wplicense-it' ) : ( 'draft' === $remote->status ? __( 'Draft', 'wplicense-it' ) : ucfirst( $remote->status ) );
		echo esc_html( $name );
		if ( $edit ) {
			echo ' &middot; <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit in WooCommerce', 'wplicense-it' ) . '</a>';
		}
		echo ' &middot; <a href="' . esc_url( $this->action_url( 'test', $software_id, $plan->id ) ) . '">' . esc_html__( 'Test this plan', 'wplicense-it' ) . '</a>';

		if ( null !== $plan->conflict_minor ) {
			echo '<div style="margin-top:6px;padding:6px 8px;border-left:4px solid #dba617;background:#fcf9e8">';
			printf(
				'<strong>%s</strong><br>',
				/* translators: 1: price in WooCommerce, 2: price in the plan. */
				esc_html( sprintf( __( 'WooCommerce price is %1$s, plan price is %2$s.', 'wplicense-it' ), Money::from_minor( $plan->conflict_minor, $currency ), $plan->price ) )
			);
			echo '<a href="' . esc_url( $this->action_url( 'restore', $software_id, $plan->id ) ) . '">' . esc_html__( 'Keep the plan price', 'wplicense-it' ) . '</a> &middot; ';
			echo '<a href="' . esc_url( $this->action_url( 'accept', $software_id, $plan->id ) ) . '">' . esc_html__( 'Use the WooCommerce price', 'wplicense-it' ) . '</a>';
			echo '</div>';
		}

		if ( 'draft' === $remote->status ) {
			echo '<div class="description" style="margin-top:4px">';
			if ( $this->has_current_pass( $software_id, $plan ) ) {
				esc_html_e( 'The test passed. ', 'wplicense-it' );
				echo '<a href="' . esc_url( $this->action_url( 'publish', $software_id, $plan->id ) ) . '"><strong>' . esc_html__( 'Publish in the shop', 'wplicense-it' ) . '</strong></a>';
			} elseif ( null !== $plan->tested_at ) {
				esc_html_e( 'The plan, its product or its zip changed since the test passed. Test it again to publish.', 'wplicense-it' );
			} else {
				esc_html_e( 'Not for sale yet. It can be published once a test of this plan passes.', 'wplicense-it' );
			}
			echo '</div>';
		}
	}

	/**
	 * Saves the plans with the product.
	 *
	 * @param int     $post_id License product ID.
	 * @param WP_Post $post    The post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'auto-draft' === $post->post_status || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_products' ) ) {
			$this->queue_report( $post_id, array( __( 'Plans were not saved: your account cannot edit WooCommerce products.', 'wplicense-it' ) ), array() );

			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is checked and cleaned by PlanInput.
		$rows   = isset( $_POST[ self::POST_FIELD ] ) && is_array( $_POST[ self::POST_FIELD ] ) ? wp_unslash( $_POST[ self::POST_FIELD ] ) : array();
		$parsed = PlanInput::parse( $rows, $this->plans->all( $post_id ) );

		if ( $parsed['errors'] ) {
			$this->queue_report( $post_id, array_merge( array( __( 'The plans were not saved. Fix these and save again:', 'wplicense-it' ) ), $parsed['errors'] ), array() );

			return;
		}

		$title = '' !== $post->post_title ? $post->post_title : __( 'Product', 'wplicense-it' );
		$this->queue_report( $post_id, array(), $this->sync->save( $post_id, $title, $parsed['plans'] ) );
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- PlanException is thrown and caught inside.
	/**
	 * Runs one of the row actions and goes back to the product.
	 */
	public function handle_action(): void {
		// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below, against the specific plan and action.
		$do      = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$plan_id = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : '';
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! wp_verify_nonce( $nonce, self::nonce_action( $do, $post_id, $plan_id ) ) ) {
			wp_die( esc_html__( 'This link has expired. Go back and try again.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}
		if ( 'wplit_product' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}

		$errors  = array();
		$results = array();
		$done    = '';

		try {
			switch ( $do ) {
				case 'accept':
					$this->sync->accept_woo_price( $post_id, $plan_id );
					$done = __( 'The plan now uses the WooCommerce price.', 'wplicense-it' );
					break;
				case 'restore':
					$this->sync->restore_plan_price( $post_id, $plan_id );
					$done = __( 'The plan price was put back on the WooCommerce product.', 'wplicense-it' );
					break;
				case 'test':
					$this->run_test( $post_id, $plan_id );
					break;
				case 'publish':
					$plan = $this->plan_by_id( $post_id, $plan_id );
					if ( null === $plan ) {
						throw new PlanException( 'That plan no longer exists.' );
					}
					$this->sync->publish( $post_id, $plan_id, Fingerprint::of( $post_id, $plan, $this->catalog->package_file( $post_id ) ) );
					$done = __( 'Published in the shop. Customers can now buy this plan.', 'wplicense-it' );
					break;
				case 'forget':
					$this->sync->forget_product( $post_id, $plan_id );
					$results = $this->sync->sync( $post_id, get_the_title( $post_id ) );
					break;
				default:
					$errors[] = __( 'Unknown action.', 'wplicense-it' );
			}//end switch
		} catch ( PlanException $e ) {
			$errors[] = $e->getMessage();
		}//end try

		$this->queue_report( $post_id, $errors, $results, $done );
		// Back to the Plans tab of the product.
		wp_safe_redirect( (string) get_edit_post_link( $post_id, 'raw' ) . '#wplit-plans' );
		exit;
	}

	/**
	 * Runs the test purchase for a plan, remembers the outcome and keeps the report for the next page load.
	 *
	 * @param int    $post_id License product ID.
	 * @param string $plan_id Plan ID.
	 * @throws PlanException If the plan does not exist.
	 */
	private function run_test( int $post_id, string $plan_id ): void {
		$plan = $this->plan_by_id( $post_id, $plan_id );
		if ( null === $plan ) {
			throw new PlanException( 'That plan no longer exists.' );
		}

		$report = $this->tester->run( $post_id, $plan );
		$print  = Fingerprint::of( $post_id, $plan, $this->catalog->package_file( $post_id ) );

		$this->sync->record_test( $post_id, $plan_id, $report->passed(), $print, gmdate( 'Y-m-d H:i:s' ) );

		set_transient(
			self::TEST_PREFIX . get_current_user_id() . '_' . $post_id,
			array(
				'plan'   => $plan->name,
				'passed' => $report->passed(),
				'rows'   => $report->to_array(),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Whether a plan has a passed test that still matches the plan, its product and its zip.
	 *
	 * @param int  $software_id License product ID.
	 * @param Plan $plan        Saved plan.
	 */
	private function has_current_pass( int $software_id, Plan $plan ): bool {
		return Fingerprint::is_current( $plan, Fingerprint::of( $software_id, $plan, $this->catalog->package_file( $software_id ) ) );
	}

	/**
	 * A saved plan by ID.
	 *
	 * @param int    $post_id License product ID.
	 * @param string $plan_id Plan ID.
	 */
	private function plan_by_id( int $post_id, string $plan_id ): ?Plan {
		foreach ( $this->plans->all( $post_id ) as $plan ) {
			if ( $plan->id === $plan_id ) {
				return $plan;
			}
		}

		return null;
	}

	/**
	 * Prints and clears the result of the last test purchase.
	 *
	 * @param int $post_id License product ID.
	 */
	private function render_test_report( int $post_id ): void {
		$key  = self::TEST_PREFIX . get_current_user_id() . '_' . $post_id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! isset( $data['rows'] ) ) {
			return;
		}
		delete_transient( $key );

		$report = TestReport::from_array( $data['rows'] );
		$passed = ! empty( $data['passed'] );
		$icons  = array(
			CheckResult::PASS => array( '&#10003;', '#00a32a', __( 'Passed:', 'wplicense-it' ) ),
			CheckResult::FAIL => array( '&#10007;', '#d63638', __( 'Failed:', 'wplicense-it' ) ),
			CheckResult::WARN => array( '!', '#996800', __( 'Warning:', 'wplicense-it' ) ),
			CheckResult::SKIP => array( '&ndash;', '#646970', __( 'Not tried:', 'wplicense-it' ) ),
		);

		printf(
			'<div class="notice notice-%1$s inline"><p><strong>%2$s</strong></p><ul style="margin:0 0 8px">',
			$passed ? 'success' : 'error',
			esc_html(
				$passed
					/* translators: %s: plan name. */
					? sprintf( __( 'Test of "%s": passed. Nothing was saved or sent.', 'wplicense-it' ), (string) ( $data['plan'] ?? '' ) )
					/* translators: %s: plan name. */
					: sprintf( __( 'Test of "%s": did not pass. Fix the items below and test again.', 'wplicense-it' ), (string) ( $data['plan'] ?? '' ) )
			)
		);

		foreach ( $report->results as $result ) {
			list( $symbol, $color, $word ) = $icons[ $result->status ];
			printf(
				'<li style="margin:4px 0"><span aria-hidden="true" style="display:inline-block;width:1.4em;font-weight:bold;color:%1$s">%2$s</span><span class="screen-reader-text">%3$s </span><strong>%4$s</strong> &mdash; %5$s</li>',
				esc_attr( $color ),
				$symbol, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed entity from the list above.
				// Fixed entity from the list above.
				esc_html( $word ),
				esc_html( $result->label ),
				esc_html( $result->message )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * A nonce-protected link for a row action.
	 *
	 * @param string $do          Action.
	 * @param int    $software_id License product ID.
	 * @param string $plan_id     Plan ID.
	 */
	private function action_url( string $do, int $software_id, string $plan_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'do'     => $do,
					'post'   => $software_id,
					'plan'   => $plan_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $do, $software_id, $plan_id )
		);
	}

	/**
	 * Nonce action for one row action.
	 *
	 * @param string $do          Action.
	 * @param int    $software_id License product ID.
	 * @param string $plan_id     Plan ID.
	 */
	private static function nonce_action( string $do, int $software_id, string $plan_id ): string {
		return 'wplit_plan_' . $do . '_' . $software_id . '_' . $plan_id;
	}

	/**
	 * Keeps messages for the next page load.
	 *
	 * @param int          $post_id License product ID.
	 * @param string[]     $errors  Error lines.
	 * @param PlanResult[] $results Results of the sync.
	 * @param string|null  $done    Line to show when nothing else needs saying. Null for the usual "Plans saved".
	 */
	private function queue_report( int $post_id, array $errors, array $results, ?string $done = null ): void {
		$lines = array();
		foreach ( $results as $result ) {
			if ( PlanResult::IN_SYNC === $result->status ) {
				continue;
			}
			$lines[] = array(
				'attention' => $result->needs_attention(),
				'text'      => $result->name . ': ' . $result->message,
			);
		}

		$done = $done ?? __( 'Plans saved. WooCommerce is up to date.', 'wplicense-it' );
		if ( ! $errors && ! $lines && '' !== $done ) {
			$lines[] = array(
				'attention' => false,
				'text'      => $done,
			);
		}

		set_transient(
			self::REPORT_PREFIX . get_current_user_id() . '_' . $post_id,
			array(
				'errors' => $errors,
				'lines'  => $lines,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints and clears the queued messages.
	 *
	 * @param int $post_id License product ID.
	 */
	private function render_report( int $post_id ): void {
		$key    = self::REPORT_PREFIX . get_current_user_id() . '_' . $post_id;
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );

		if ( ! empty( $report['errors'] ) ) {
			echo '<div class="notice notice-error inline"><ul style="list-style:disc;margin-left:20px">';
			foreach ( (array) $report['errors'] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul></div>';
		}

		foreach ( (array) ( $report['lines'] ?? array() ) as $line ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				! empty( $line['attention'] ) ? 'warning' : 'success',
				esc_html( (string) ( $line['text'] ?? '' ) )
			);
		}
	}

	/**
	 * The "Lasts" choices.
	 *
	 * @return array<string, string>
	 */
	private static function period_labels(): array {
		return array(
			'lifetime' => __( 'Lifetime (never expires)', 'wplicense-it' ),
			'P1M'      => __( '1 month', 'wplicense-it' ),
			'P3M'      => __( '3 months', 'wplicense-it' ),
			'P6M'      => __( '6 months', 'wplicense-it' ),
			'P1Y'      => __( '1 year', 'wplicense-it' ),
			'P2Y'      => __( '2 years', 'wplicense-it' ),
		);
	}
}
