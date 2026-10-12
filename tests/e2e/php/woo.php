<?php
/**
 * Runs one WooCommerce operation on the test site and prints the result as JSON. Used by the browser tests to do what a
 * shop owner or a payment gateway would do, with real WooCommerce objects.
 *
 *   php tests/e2e/php/woo.php '{"op":"create_order","product_id":12,"email":"a@example.com","status":"completed"}'
 */

declare( strict_types=1 );

$root = getenv( 'WPLIT_ENV_ROOT' ) ?: '/opt/wp-test';
define( 'WP_ADMIN', true );
require $root . '/wordpress/wp-load.php';

$in  = json_decode( $argv[1] ?? '{}', true ) ?: array();
$op  = $in['op'] ?? '';
$out = array();

switch ( $op ) {
	case 'product_info':
		$p   = wc_get_product( (int) $in['product_id'] );
		$out = $p ? array(
			'status'   => $p->get_status(),
			'type'     => $p->get_type(),
			'price'    => $p->get_regular_price(),
			'virtual'  => $p->is_virtual(),
			'name'     => $p->get_name(),
			'software' => $p->get_meta( '_wplit_product_id' ),
			'limit'    => $p->get_meta( '_wplit_activation_limit' ),
			'period'   => $p->get_meta( '_wplit_period' ),
			'plan'     => $p->get_meta( '_wplit_plan_id' ),
		) : array( 'missing' => true );
		break;

	case 'edit_price':
		$p = wc_get_product( (int) $in['product_id'] );
		$p->set_regular_price( (string) $in['price'] );
		$p->save();
		$out = array( 'ok' => true );
		break;

	case 'trash_product':
		wp_trash_post( (int) $in['product_id'] );
		$out = array( 'ok' => true );
		break;

	case 'set_product_status':
		$p = wc_get_product( (int) $in['product_id'] );
		$p->set_status( $in['status'] );
		$p->save();
		$out = array( 'ok' => true );
		break;

	case 'create_user':
		$email = $in['email'];
		$id    = email_exists( $email );
		if ( ! $id ) {
			$id = wp_insert_user( array( 'user_login' => $in['login'], 'user_email' => $email, 'user_pass' => $in['password'], 'role' => 'customer' ) );
		}
		$out = array( 'user_id' => (int) $id );
		break;

	case 'create_order':
		$order = wc_create_order( array( 'customer_id' => (int) ( $in['user_id'] ?? 0 ), 'status' => 'pending' ) );
		$item  = $order->add_product( wc_get_product( (int) $in['product_id'] ), (int) ( $in['qty'] ?? 1 ) );
		if ( ! empty( $in['renew_license_id'] ) ) {
			wc_add_order_item_meta( $item, '_wplit_renew_license_id', (int) $in['renew_license_id'] );
		}
		$order->set_billing_email( $in['email'] );
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_payment_method( 'cheque' );
		$order->calculate_totals();
		$order->save();
		if ( ! empty( $in['status'] ) ) {
			$order->update_status( $in['status'], 'Test setup' );
		}
		$out = array( 'order_id' => $order->get_id(), 'total' => $order->get_total(), 'item_id' => $item );
		break;

	case 'set_order_status':
		$order = wc_get_order( (int) $in['order_id'] );
		$order->update_status( $in['status'], 'Test' );
		$out = array( 'status' => $order->get_status() );
		break;

	case 'refund':
		$order  = wc_get_order( (int) $in['order_id'] );
		$lines  = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$lines[ $item_id ] = array( 'qty' => (int) ( $in['qty'] ?? $item->get_quantity() ), 'refund_total' => (float) ( $in['amount'] ?? $item->get_total() ) );
		}
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => (float) ( $in['amount'] ?? $order->get_total() ), 'reason' => 'Test', 'line_items' => $lines ) );
		if ( is_wp_error( $refund ) ) {
			$out = array( 'error' => $refund->get_error_message() );
			break;
		}
		if ( ! empty( $in['full'] ) ) {
			$order->update_status( 'refunded', 'Test' );
		}
		$out = array( 'refund_id' => $refund->get_id(), 'status' => wc_get_order( $order->get_id() )->get_status() );
		break;

	case 'order_notes':
		$notes = wc_get_order_notes( array( 'order_id' => (int) $in['order_id'] ) );
		$out   = array( 'notes' => array_map( static fn( $n ) => wp_strip_all_tags( $n->content ), $notes ) );
		break;

	case 'clear_mail':
		@unlink( WP_CONTENT_DIR . '/mail-log.jsonl' );
		$out = array( 'ok' => true );
		break;

	case 'mail':
		$file = WP_CONTENT_DIR . '/mail-log.jsonl';
		$out  = array( 'mail' => is_file( $file ) ? array_map( static fn( $l ) => json_decode( $l, true ), array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) ) : array() );
		break;

	default:
		$out = array( 'error' => 'unknown op ' . $op );
}

echo wp_json_encode( $out );
