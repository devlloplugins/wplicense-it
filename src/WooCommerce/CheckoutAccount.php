<?php
/**
 * An account is needed to buy a license.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

/**
 * When the cart holds a licensed product, checkout makes the buyer create an account (or log in), whatever the store's
 * guest checkout setting: licenses are downloaded, renewed and managed in My Account (decision 85). Carts without a
 * licensed product follow the store's settings. Both the classic and the block checkout ask WooCommerce the same two
 * questions, answered here. Sellers who want guests can return false from the wplicense_it_require_account filter.
 */
final class CheckoutAccount {

	/**
	 * Constructor.
	 *
	 * @param OrderReader $reader Knows which products sell a license.
	 */
	public function __construct( private OrderReader $reader ) {
	}

	/**
	 * Registers the filters.
	 */
	public function register(): void {
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'required' ), 20 );
		add_filter( 'woocommerce_checkout_registration_enabled', array( $this, 'required' ), 20 );
	}

	/**
	 * True when the cart sells a license; otherwise WooCommerce's own answer.
	 *
	 * @param mixed $answer WooCommerce's answer.
	 * @return mixed
	 */
	public function required( $answer ) {
		if ( is_user_logged_in() || ! $this->cart_sells_a_license() ) {
			return $answer;
		}

		/**
		 * Whether buying a license needs an account. Return false to allow guest checkout for licenses.
		 *
		 * @param bool $required Default true.
		 */
		return (bool) apply_filters( 'wplicense_it_require_account', true ) ? true : $answer;
	}

	/**
	 * Whether a product in the cart sells a license.
	 */
	private function cart_sells_a_license(): bool {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $line ) {
			$product = $line['data'] ?? null;

			if ( $product instanceof \WC_Product && $this->reader->current_terms( $product, $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0 )['product'] > 0 ) {
				return true;
			}
		}

		return false;
	}
}
