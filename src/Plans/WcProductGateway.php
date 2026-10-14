<?php
/**
 * WooCommerce implementation of the plan gateway.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

use Devllo\WPLicenseIt\WooCommerce\Money;
use Devllo\WPLicenseIt\WooCommerce\OrderReader;

/**
 * Creates and updates simple WooCommerce products through the WooCommerce product API.
 */
final class WcProductGateway implements ProductGateway {

	public const META_PLAN = '_wplit_plan_id';

	/**
	 * {@inheritdoc}
	 */
	public function currency(): string {
		return (string) get_woocommerce_currency();
	}

	/**
	 * {@inheritdoc}
	 */
	public function find( int $id ): ?RemoteProduct {
		$product = $id > 0 ? wc_get_product( $id ) : false;
		if ( ! $product ) {
			return null;
		}

		$price = (string) $product->get_regular_price( 'edit' );

		return new RemoteProduct(
			$id,
			(string) $product->get_status( 'edit' ),
			'' === $price ? null : Money::to_minor( $price, $this->currency() ),
			(int) $product->get_meta( OrderReader::META_PRODUCT, true ),
			(string) $product->get_meta( OrderReader::META_LIMIT, true ),
			(string) $product->get_meta( OrderReader::META_PERIOD, true ),
			(string) $product->get_type()
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws PlanException If WooCommerce cannot do it.
	 */
	public function create( string $plan_id, string $title, int $price_minor, int $software_id, int $sites, string $period ): int {
		try {
			$product = new \WC_Product_Simple();
			$product->set_name( $title );
			$product->set_status( 'draft' );
			$product->set_virtual( true );
			$product->set_regular_price( Money::from_minor( $price_minor, $this->currency() ) );
			$product->update_meta_data( self::META_PLAN, $plan_id );
			$product->update_meta_data( OrderReader::META_PRODUCT, $software_id );
			$product->update_meta_data( OrderReader::META_LIMIT, (string) $sites );
			$product->update_meta_data( OrderReader::META_PERIOD, $period );
			$id = (int) $product->save();
		} catch ( \Exception $e ) {
			throw new PlanException( 'WooCommerce could not create the product: ' . $e->getMessage() );
		}

		if ( $id <= 0 ) {
			throw new PlanException( 'WooCommerce could not create the product.' );
		}

		return $id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws PlanException If WooCommerce cannot do it.
	 */
	public function update_price( int $id, int $price_minor ): void {
		$product = $this->load( $id );

		try {
			$product->set_regular_price( Money::from_minor( $price_minor, $this->currency() ) );
			$product->save();
		} catch ( \Exception $e ) {
			throw new PlanException( 'WooCommerce could not change the price: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws PlanException If WooCommerce cannot do it.
	 */
	public function update_terms( int $id, int $software_id, int $sites, string $period ): void {
		$product = $this->load( $id );

		try {
			$product->update_meta_data( OrderReader::META_PRODUCT, $software_id );
			$product->update_meta_data( OrderReader::META_LIMIT, (string) $sites );
			$product->update_meta_data( OrderReader::META_PERIOD, $period );
			$product->save();
		} catch ( \Exception $e ) {
			throw new PlanException( 'WooCommerce could not change the license terms: ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws PlanException If WooCommerce cannot do it.
	 */
	public function set_status( int $id, string $status ): void {
		if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
			throw new PlanException( 'Unsupported product status.' );
		}

		$product = $this->load( $id );

		try {
			$product->set_status( $status );
			$product->save();
		} catch ( \Exception $e ) {
			throw new PlanException( 'WooCommerce could not change the product status: ' . $e->getMessage() );
		}
	}

	/**
	 * Loads a product or fails with a message for the seller.
	 *
	 * @param int $id Product ID.
	 * @return \WC_Product
	 * @throws PlanException If the product does not exist.
	 */
	private function load( int $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			throw new PlanException( 'The WooCommerce product no longer exists.' );
		}

		return $product;
	}
}
