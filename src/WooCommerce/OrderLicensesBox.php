<?php
/**
 * The licenses of an order, on the order's own admin screen.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\WooCommerce;

use Devllo\WPLicenseIt\Admin\Capabilities;
use Devllo\WPLicenseIt\Admin\LicensesTable;
use Devllo\WPLicenseIt\Admin\Urls;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use WC_Order;
use WC_Order_Item_Product;
use WP_Post;

/**
 * A box on the WooCommerce order screen (classic and high-performance order storage) that lists the licenses the order
 * produced: key, status, sites in use, expiry and a link to the license. It shows the order's lines that have licenses,
 * so a refunded or revoked license is still listed, with its status. Only people who may manage licenses see it.
 */
final class OrderLicensesBox {

	public const ID = 'wplit_order_licenses';

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 * @param OrderReader    $reader   Tells which lines of an order are licensed.
	 */
	public function __construct( private LicenseService $licenses, private OrderReader $reader ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 10, 2 );
	}

	/**
	 * Adds the box to the order screen, for orders that have licensed lines or licenses. Other orders of the shop get no box.
	 *
	 * @param string                $screen        Screen or post type being set up.
	 * @param WC_Order|WP_Post|null $order_or_post The order, or its post on classic storage.
	 */
	public function add_box( $screen = '', $order_or_post = null ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$order = $this->order_of( $order_or_post );

		if ( ! $order instanceof WC_Order || ! $this->concerns( $order ) ) {
			return;
		}

		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box( self::ID, __( 'WPLicense It licenses', 'wplicense-it' ), array( $this, 'render' ), $order_screen, 'normal', 'default' );
	}

	/**
	 * Draws the box.
	 *
	 * @param WC_Order|WP_Post $order_or_post The order, or its post on classic storage.
	 */
	public function render( $order_or_post ): void {
		$order = $this->order_of( $order_or_post );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$rows = $this->rows( $order );

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No license has been issued for this order. Licenses are issued when the order reaches the status chosen in Licensing settings.', 'wplicense-it' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Product', 'wplicense-it' ), __( 'License key', 'wplicense-it' ), __( 'Status', 'wplicense-it' ), __( 'Sites', 'wplicense-it' ), __( 'Expires', 'wplicense-it' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['product'] ) . '</td><td><code>' . esc_html( $row['key'] ) . '</code></td><td>' . $row['badge'] . '</td><td>' . esc_html( $row['sites'] ) . '</td><td>' . esc_html( $row['expires'] ) . '</td><td><a href="' . esc_url( $row['url'] ) . '">' . esc_html__( 'View', 'wplicense-it' ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The badge is built and escaped by LicensesTable.
		}

		echo '</tbody></table>';
	}

	/**
	 * The order behind what WordPress passes to a meta box.
	 *
	 * @param WC_Order|WP_Post|null $order_or_post The order, or its post on classic storage.
	 */
	private function order_of( $order_or_post ): ?WC_Order {
		if ( $order_or_post instanceof WC_Order ) {
			return $order_or_post;
		}

		$order = $order_or_post instanceof WP_Post ? wc_get_order( $order_or_post->ID ) : false;

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Whether the order has a licensed line, or already has licenses.
	 *
	 * @param WC_Order $order Order.
	 */
	private function concerns( WC_Order $order ): bool {
		foreach ( $this->reader->read( $order )->items as $item ) {
			if ( $item->wplit_product_id > 0 || array() !== $item->license_ids ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The licenses of an order's lines, in the order they were issued.
	 *
	 * @param WC_Order $order Order.
	 * @return list<array{product:string,key:string,badge:string,sites:string,expires:string,url:string}>
	 */
	private function rows( WC_Order $order ): array {
		$rows = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			foreach ( WooItemLicenseStore::ids( $item ) as $license_id ) {
				$license = $this->licenses->find( $license_id );

				if ( null === $license ) {
					continue;
				}

				$used = $this->licenses->activations_used( $license );

				$rows[] = array(
					'product' => $item->get_name(),
					'key'     => $license->license_key,
					'badge'   => LicensesTable::status_badge( $license ),
					'sites'   => 0 === $license->activation_limit
						? sprintf(
							/* translators: %d: sites in use. */
							__( '%d (no limit)', 'wplicense-it' ),
							$used
						)
						: sprintf(
							/* translators: 1: sites in use, 2: sites allowed. */
							__( '%1$d of %2$d', 'wplicense-it' ),
							$used,
							$license->activation_limit
						),
					'expires' => null === $license->expires_at
						? __( 'Never', 'wplicense-it' )
						: wp_date( get_option( 'date_format' ), $license->expires_at->getTimestamp() ),
					'url'     => Urls::view( $license->id ),
				);
			}//end foreach
		}//end foreach

		return $rows;
	}
}
