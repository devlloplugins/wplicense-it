<?php
/**
 * Runs one WooCommerce operation on the test site and prints the result as JSON. Used by the browser tests to do what a
 * shop owner or a payment gateway would do, with real WooCommerce objects.
 *
 *   php tests/e2e/php/woo.php '{"op":"create_order","product_id":12,"email":"a@example.com","status":"completed"}'
 */

declare( strict_types=1 );

$root = getenv( 'WPLIT_ENV_ROOT' ) ? getenv( 'WPLIT_ENV_ROOT' ) : '/opt/wp-test';
define( 'WP_ADMIN', true );
require $root . '/wordpress/wp-load.php';

$in  = (array) json_decode( $argv[1] ?? '{}', true );
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
			$id = wp_insert_user(
				array(
					'user_login' => $in['login'],
					'user_email' => $email,
					'user_pass'  => $in['password'],
					'role'       => 'customer',
				)
			);
		}
		$out = array( 'user_id' => (int) $id );
		break;

	case 'create_simple_product':
		// A WooCommerce product that has nothing to do with licensing.
		$plain = new WC_Product_Simple();
		$plain->set_name( $in['name'] );
		$plain->set_regular_price( (string) ( $in['price'] ?? '10' ) );
		$plain->set_status( 'publish' );
		$out = array( 'product_id' => (int) $plain->save() );
		break;

	case 'create_order':
		$order = wc_create_order(
			array(
				'customer_id' => (int) ( $in['user_id'] ?? 0 ),
				'status'      => 'pending',
			)
		);
		$item  = $order->add_product( wc_get_product( (int) $in['product_id'] ), (int) ( $in['qty'] ?? 1 ) );
		if ( ! empty( $in['renew_license_id'] ) ) {
			wc_add_order_item_meta( $item, '_wplit_renew_license_id', (int) $in['renew_license_id'] );
		}
		foreach ( (array) ( $in['more_product_ids'] ?? array() ) as $more ) {
			$order->add_product( wc_get_product( (int) $more ), 1 );
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
		$out = array(
			'order_id' => $order->get_id(),
			'total'    => $order->get_total(),
			'item_id'  => $item,
		);
		break;

	case 'order_products':
		// The products of an order as the plugin reads them from the licenses (OrderProducts), by the order's row in the license orders table.
		global $wpdb;
		$out = array( 'ids' => ( new \Devllo\WPLicenseIt\WooCommerce\OrderProducts( new \Devllo\WPLicenseIt\Database\WpdbLicenseRepository( $wpdb ) ) )->ids( (int) $in['order_row_id'] ) );
		break;

	case 'pay_order':
		// What a payment service that takes the money online (Stripe, PayPal) does when the payment goes through.
		$order = wc_get_order( (int) $in['order_id'] );
		$order->payment_complete( 'test-' . $order->get_id() );
		$out = array( 'status' => wc_get_order( (int) $in['order_id'] )->get_status() );
		break;

	case 'set_order_status':
		$order = wc_get_order( (int) $in['order_id'] );
		$order->update_status( $in['status'], 'Test' );
		$out = array( 'status' => $order->get_status() );
		break;

	case 'refund':
		$order = wc_get_order( (int) $in['order_id'] );
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$lines[ $item_id ] = array(
				'qty'          => (int) ( $in['qty'] ?? $item->get_quantity() ),
				'refund_total' => (float) ( $in['amount'] ?? $item->get_total() ),
			);
		}
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => (float) ( $in['amount'] ?? $order->get_total() ),
				'reason'     => 'Test',
				'line_items' => $lines,
			)
		);
		if ( is_wp_error( $refund ) ) {
			$out = array( 'error' => $refund->get_error_message() );
			break;
		}
		if ( ! empty( $in['full'] ) ) {
			$order->update_status( 'refunded', 'Test' );
		}
		$out = array(
			'refund_id' => $refund->get_id(),
			'status'    => wc_get_order( $order->get_id() )->get_status(),
		);
		break;

	case 'set_product_meta':
		$p = wc_get_product( (int) $in['product_id'] );
		$p->update_meta_data( (string) $in['key'], (string) $in['value'] );
		$p->save();
		$out = array( 'ok' => true );
		break;

	case 'find_invoices':
		// The renewal invoice orders made for a license, newest first.
		// Not a meta query: classic order storage ignores one and would return every order.
		$ids = array();
		foreach ( wc_get_orders(
			array(
				'limit'       => 100,
				'orderby'     => 'ID',
				'order'       => 'DESC',
				'status'      => array_keys( wc_get_order_statuses() ),
				'created_via' => 'wplicense_renewal',
			)
		) as $found ) {
			if ( (int) $found->get_meta( '_wplit_invoice_license_id', true ) === (int) $in['license_id'] ) {
				$ids[] = $found->get_id();
			}
		}
		$min = (int) ( $in['min_id'] ?? 0 );
		$out = array( 'orders' => array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > $min ) ) );
		break;

	case 'order_info':
		$order = wc_get_order( (int) $in['order_id'] );
		$lines = array();
		foreach ( $order->get_items() as $line ) {
			$lines[] = array(
				'name'    => $line->get_name(),
				'renew'   => (int) $line->get_meta( '_wplit_renew_license_id', true ),
				'product' => (int) $line->get_meta( '_wplit_product_id', true ),
				'period'  => (string) $line->get_meta( '_wplit_period', true ),
				'ids'     => array_map( 'intval', array_filter( (array) $line->get_meta( '_wplit_license_ids', true ) ) ),
			);
		}
		$out = array(
			'status'      => $order->get_status(),
			'total'       => $order->get_total(),
			'created_via' => $order->get_created_via(),
			'user_id'     => (int) $order->get_user_id(),
			'email'       => $order->get_billing_email(),
			'pay_url'     => $order->get_checkout_payment_url(),
			'edit_url'    => $order->get_edit_order_url(),
			'number'      => $order->get_order_number(),
			'problem'     => (string) $order->get_meta( '_wplit_issue_problem', true ),
			'lines'       => $lines,
		);
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

	case 'enable_checkout':
		// Cash on delivery, so a customer can place an order without a payment service, and the classic checkout page, because
		// the checkout block needs JavaScript this site does not build.
		update_option(
			'woocommerce_cod_settings',
			array(
				'enabled'            => 'yes',
				'title'              => 'Cash on delivery',
				'enable_for_methods' => array(),
				'enable_for_virtual' => 'yes',
			)
		);
		$page = (int) get_option( 'woocommerce_checkout_page_id' );
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '[woocommerce_checkout]',
			)
		);
		$cart = (int) get_option( 'woocommerce_cart_page_id' );
		wp_update_post(
			array(
				'ID'           => $cart,
				'post_content' => '[woocommerce_cart]',
			)
		);
		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		$out = array(
			'checkout' => $page,
			'cart'     => $cart,
		);
		break;

	default:
		$out = array( 'error' => 'unknown op ' . $op );
}

echo wp_json_encode( $out );
