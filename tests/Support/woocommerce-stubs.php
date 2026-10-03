<?php
/**
 * Minimal stand-ins for the WooCommerce classes and WordPress functions the adapter touches,
 * so its glue code can run in unit tests. Only defined when the real ones are not loaded.
 *
 * @package Devllo\WPLicenseIt
 */

// phpcs:disable

if ( ! class_exists( 'WC_Order_Item' ) ) {
	class WC_Order_Item {
		public array $meta = array();
		public int $id = 0;

		public function get_meta( $key, $single = true ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function save() {
			WC_Order_Factory::$items[ $this->id ] = $this;
		}
	}

	class WC_Order_Item_Product extends WC_Order_Item {
		public string $name = 'Great Plugin';
		public int $quantity = 1;
		public int $product_id = 55;
		public int $variation_id = 0;
		public ?WC_Product $product = null;

		public function get_name() { return $this->name; }
		public function get_quantity() { return $this->quantity; }
		public function get_product_id() { return $this->product_id; }
		public function get_variation_id() { return $this->variation_id; }
		public function get_product() { return $this->product; }
	}

	class WC_Order_Factory {
		public static array $items = array();

		public static function get_order_item( $id ) {
			return self::$items[ $id ] ?? false;
		}
	}

	class WC_Product {
		public array $meta = array();
		public int $id = 55;

		public function get_id() { return $this->id; }
		public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	}

	class WC_Order {
		public int $id = 100;
		public array $items = array();
		public array $notes = array();
		public array $refunded = array();
		public int $user_id = 5;
		public string $email = 'Buyer@Example.com';

		public function get_items() { return $this->items; }
		public function get_id() { return $this->id; }
		public function get_order_number() { return (string) $this->id; }
		public function get_user_id() { return $this->user_id; }
		public function get_billing_email() { return $this->email; }
		public function get_currency() { return 'USD'; }
		public function get_total( $context = 'view' ) { return '29.99'; }
		public function get_qty_refunded_for_item( $item_id ) { return -1 * ( $this->refunded[ $item_id ] ?? 0 ); }
		public function add_order_note( $note ) { $this->notes[] = $note; }
	}

	class WC_Email {
		public string $id = '';
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) { return $GLOBALS['wplit_test_orders'][ $id ] ?? false; }
	function wc_get_product( $id ) { return $GLOBALS['wplit_test_products'][ $id ] ?? false; }
	function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['wplit_test_post_meta'][ $id ][ $key ] ?? ''; }
	function get_option( $name, $default = false ) { return $GLOBALS['wplit_test_options'][ $name ] ?? $default; }
	function apply_filters( $hook, $value ) { return $value; }
	function __( $text ) { return $text; }
	function _n( $single, $plural, $number ) { return 1 === $number ? $single : $plural; }
	function delete_transient( $name ) { $store = &wplit_test_transients(); unset( $store[ $name ] ); return true; }
}
