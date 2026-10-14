<?php
/**
 * Checkout snapshot and renewal links.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Two things that happen between "add to cart" and the order:
 *
 * 1. Renewal: a link from My Account (…?add-to-cart=ID&wplit_renew=LICENSE&_wplit_nonce=…) puts a
 *    product in the cart marked as a renewal of that license. The link only works for the license's
 *    owner, for the product the license belongs to, and with a valid nonce.
 * 2. Snapshot: when the order is created, the licensing terms are copied onto the order line, so
 *    editing a product later does not change what customers already bought.
 */
final class CartRenewal {

	private const CART_KEY = 'wplit_renew_license_id';

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 * @param OrderReader    $reader   Order reader, for the current terms of a product.
	 */
	public function __construct( private LicenseService $licenses, private OrderReader $reader ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_renewal_to_cart_item' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'show_renewal_in_cart' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'snapshot_line' ), 10, 4 );
	}

	/**
	 * The URL that renews a license by buying a product again.
	 *
	 * @param int $woo_product_id WooCommerce product ID.
	 * @param int $license_id     License ID.
	 */
	public static function renewal_url( int $woo_product_id, int $license_id ): string {
		return add_query_arg(
			array(
				'add-to-cart'  => $woo_product_id,
				'wplit_renew'  => $license_id,
				'_wplit_nonce' => wp_create_nonce( 'wplit_renew_' . $license_id ),
			),
			wc_get_cart_url()
		);
	}

	/**
	 * Marks a cart item as a renewal when the request carries a valid renewal link.
	 *
	 * @param array<string, mixed> $cart_item_data Cart item data.
	 * @param int                  $product_id     Product ID.
	 * @param int                  $variation_id   Variation ID.
	 * @return array<string, mixed>
	 */
	public function add_renewal_to_cart_item( array $cart_item_data, int $product_id, int $variation_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below with a nonce tied to the license.
		$license_id = isset( $_REQUEST['wplit_renew'] ) ? absint( wp_unslash( $_REQUEST['wplit_renew'] ) ) : 0;
		$nonce      = isset( $_REQUEST['_wplit_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wplit_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $license_id <= 0 || ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'wplit_renew_' . $license_id ) ) {
			return $cart_item_data;
		}

		$license = $this->licenses->find( $license_id );
		$user    = wp_get_current_user();
		$owned   = $license && ( $license->user_id === $user->ID || strtolower( $user->user_email ) === $license->email );

		if ( ! $owned || in_array( $license->status, Status::terminal(), true ) ) {
			return $cart_item_data;
		}

		$terms = $this->reader->current_terms( wc_get_product( $variation_id > 0 ? $variation_id : $product_id ), $variation_id > 0 ? $product_id : 0 );
		if ( $terms['product'] !== $license->product_id ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::CART_KEY ] = $license_id;

		return $cart_item_data;
	}

	/**
	 * Shows which license is being renewed in the cart and at checkout.
	 *
	 * @param array<int, array<string, mixed>> $item_data Display data.
	 * @param array<string, mixed>             $cart_item Cart item.
	 * @return array<int, array<string, mixed>>
	 */
	public function show_renewal_in_cart( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}

		$license = $this->licenses->find( (int) $cart_item[ self::CART_KEY ] );

		if ( $license ) {
			$item_data[] = array(
				'key'   => __( 'Renews license', 'wplicense-it' ),
				'value' => $license->license_key,
			);
		}

		return $item_data;
	}

	/**
	 * Copies the licensing terms (and a renewal request) onto the order line.
	 *
	 * @param WC_Order_Item_Product $item          Order line.
	 * @param string                $cart_item_key Cart item key.
	 * @param array<string, mixed>  $values        Cart item.
	 * @param WC_Order              $order         Order.
	 */
	public function snapshot_line( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
		$variation_id = (int) $item->get_variation_id();
		$terms        = $this->reader->current_terms( $item->get_product(), $variation_id > 0 ? (int) $item->get_product_id() : 0 );

		if ( $terms['product'] <= 0 ) {
			return;
		}

		$item->update_meta_data( OrderReader::META_PRODUCT, $terms['product'] );
		$item->update_meta_data( OrderReader::META_LIMIT, $terms['limit'] );
		$item->update_meta_data( OrderReader::META_PERIOD, $terms['period'] );

		if ( ! empty( $values[ self::CART_KEY ] ) ) {
			$item->update_meta_data( OrderReader::META_RENEW, (int) $values[ self::CART_KEY ] );
		}
	}
}
