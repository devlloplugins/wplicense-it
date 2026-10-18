<?php
/**
 * Orders that could not get their license.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Admin\Capabilities;
use WC_Order;

/**
 * When a paid order could not be given its license, the customer is waiting and nothing says so except an order note. This
 * remembers the problem on the order, shows it on the order's own screen with a button to try again, tells the site's
 * administrator once by email, and lets the Overview screen list the orders.
 */
final class IssueProblems {

	public const META   = '_wplit_issue_problem';
	public const ACTION = 'wplit_retry_order';

	/**
	 * Constructor.
	 *
	 * @param WooAdapter $adapter Processes an order again on Try again.
	 */
	public function __construct( private WooAdapter $adapter ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'order_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_retry' ) );
	}

	/**
	 * Remembers what went wrong with an order.
	 *
	 * @param WC_Order $order    The order.
	 * @param string[] $problems What went wrong, one line each.
	 * @return bool True when the order was not flagged before, so the administrator should be told.
	 */
	public static function flag( WC_Order $order, array $problems ): bool {
		$text = implode( "\n", $problems );
		$new  = '' === (string) $order->get_meta( self::META, true );

		$order->update_meta_data( self::META, $text );
		$order->save();

		return $new;
	}

	/**
	 * Forgets the problem, because the order has what it is owed now.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function clear( WC_Order $order ): void {
		if ( '' !== (string) $order->get_meta( self::META, true ) ) {
			$order->delete_meta_data( self::META );
			$order->save();
		}
	}

	/**
	 * What is wrong with an order, or an empty string.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function problem_of( WC_Order $order ): string {
		return (string) $order->get_meta( self::META, true );
	}

	/**
	 * Emails the site's administrator about an order that could not get its license.
	 *
	 * @param WC_Order $order    The order.
	 * @param string[] $problems What went wrong.
	 */
	public static function notify_admin( WC_Order $order, array $problems ): void {
		/**
		 * Filters who is told about an order that could not get its license. Return an empty string to tell nobody.
		 *
		 * @param string   $recipient The site's administrator email address.
		 * @param WC_Order $order     The order.
		 */
		$to = (string) apply_filters( 'wplicense_it_issue_problem_recipient', (string) get_option( 'admin_email' ), $order );

		if ( '' === $to ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: order number. */
			__( 'Order %s was paid but its license could not be issued', 'wplicense-it' ),
			$order->get_order_number()
		);
		$message = sprintf(
			/* translators: 1: order number, 2: what went wrong, 3: address of the order. */
			__( "Order %1\$s could not be given its license, so the customer has not received it.\n\nWhat went wrong:\n%2\$s\n\nOpen the order, fix the cause and press Try again:\n%3\$s\n", 'wplicense-it' ),
			$order->get_order_number(),
			implode( "\n", $problems ),
			$order->get_edit_order_url()
		);

		wp_mail( $to, $subject, $message );
	}

	/**
	 * The orders with a problem, newest first, for the Overview screen.
	 *
	 * @param int $limit Most orders to list.
	 * @return array{total:int, orders:list<array{id:int,number:string,problem:string,url:string}>}
	 */
	public static function recent( int $limit ): array {
		$found = wc_get_orders(
			array(
				'limit'      => $limit,
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Few orders carry the flag, and it is shown on an admin screen.
					array(
						'key'     => self::META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$orders = array();

		foreach ( $found->orders as $order ) {
			if ( $order instanceof WC_Order && '' !== self::problem_of( $order ) ) {
				$orders[] = array(
					'id'      => (int) $order->get_id(),
					'number'  => (string) $order->get_order_number(),
					'problem' => self::problem_of( $order ),
					'url'     => (string) $order->get_edit_order_url(),
				);
			}
		}

		return array(
			'total'  => (int) $found->total,
			'orders' => $orders,
		);
	}

	/**
	 * The address that processes an order again.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function retry_url( WC_Order $order ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $order->get_id()
		);
	}

	/**
	 * On the order's own screen: what went wrong, and a button to try again.
	 */
	public function order_notice(): void {
		$order = $this->current_order();

		if ( ! $order || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses which message to show.
		$retried = isset( $_GET['wplit_retried'] );
		$problem = self::problem_of( $order );

		if ( '' === $problem ) {
			if ( $retried ) {
				echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'The license was issued.', 'wplicense-it' ) . '</p></div>';
			}

			return;
		}

		echo '<div class="notice notice-error" role="alert"><p><strong>' . esc_html__( 'WPLicense It could not issue the license for this order.', 'wplicense-it' ) . '</strong> ' . esc_html__( 'The customer has not received it.', 'wplicense-it' ) . '</p><p>' . nl2br( esc_html( $problem ) ) . '</p><p>' . esc_html__( 'Fix the cause, then try again. Changing the order status to Completed again does the same.', 'wplicense-it' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::retry_url( $order ) ) . '">' . esc_html__( 'Try again', 'wplicense-it' ) . '</a></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above.
	}

	/**
	 * Processes the order again.
	 */
	public function handle_retry(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION . '_' . $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'That order does not exist.', 'wplicense-it' ), '', array( 'response' => 404 ) );
		}

		$this->adapter->retry( $order );

		wp_safe_redirect( add_query_arg( 'wplit_retried', 1, $order->get_edit_order_url() ) );
		exit;
	}

	/**
	 * The order whose edit screen is open, if one is.
	 */
	private function current_order(): ?WC_Order {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only reads which screen is open.
		$id = 0;

		if ( isset( $_GET['page'], $_GET['action'], $_GET['id'] ) && 'wc-orders' === $_GET['page'] && 'edit' === $_GET['action'] ) {
			$id = absint( wp_unslash( $_GET['id'] ) );
		} elseif ( isset( $_GET['post'], $_GET['action'] ) && 'edit' === $_GET['action'] ) {
			$id = absint( wp_unslash( $_GET['post'] ) );
		}
		// phpcs:enable

		$order = $id > 0 ? wc_get_order( $id ) : false;

		return $order instanceof WC_Order ? $order : null;
	}
}
