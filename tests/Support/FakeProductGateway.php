<?php
/**
 * WooCommerce stand-in for plan sync tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Plans\PlanException;
use Devllo\WPLicenseIt\Plans\ProductGateway;
use Devllo\WPLicenseIt\Plans\RemoteProduct;

/**
 * Keeps products in an array and records what was done to them.
 */
final class FakeProductGateway implements ProductGateway {

	/**
	 * @var string
	 */
	public $currency = 'USD';

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public $products = array();

	/**
	 * @var string[] Log of calls, such as "create:5" or "price:5".
	 */
	public $calls = array();

	/**
	 * @var bool Makes every write fail.
	 */
	public $fail = false;

	/**
	 * @var int
	 */
	private $next_id = 500;

	public function currency(): string {
		return $this->currency;
	}

	public function find( int $id ): ?RemoteProduct {
		if ( ! isset( $this->products[ $id ] ) ) {
			return null;
		}
		$p = $this->products[ $id ];

		return new RemoteProduct( $id, $p['status'], $p['price'], $p['software'], (string) $p['sites'], $p['period'] );
	}

	public function create( string $plan_id, string $title, int $price_minor, int $software_id, int $sites, string $period ): int {
		$this->guard();
		$id                    = $this->next_id++;
		$this->products[ $id ] = array(
			'plan'     => $plan_id,
			'title'    => $title,
			'status'   => 'draft',
			'price'    => $price_minor,
			'software' => $software_id,
			'sites'    => $sites,
			'period'   => $period,
		);
		$this->calls[]         = 'create:' . $id;

		return $id;
	}

	public function update_price( int $id, int $price_minor ): void {
		$this->guard();
		$this->products[ $id ]['price'] = $price_minor;
		$this->calls[]                  = 'price:' . $id;
	}

	public function update_terms( int $id, int $software_id, int $sites, string $period ): void {
		$this->guard();
		$this->products[ $id ]['software'] = $software_id;
		$this->products[ $id ]['sites']    = $sites;
		$this->products[ $id ]['period']   = $period;
		$this->calls[]                     = 'terms:' . $id;
	}

	public function set_status( int $id, string $status ): void {
		$this->guard();
		$this->products[ $id ]['status'] = $status;
		$this->calls[]                   = 'status:' . $id . ':' . $status;
	}

	/**
	 * Simulates a seller editing the price in WooCommerce.
	 *
	 * @param int $id    Product ID.
	 * @param int $minor New price in minor units.
	 */
	public function edit_price_in_woo( int $id, int $minor ): void {
		$this->products[ $id ]['price'] = $minor;
	}

	/**
	 * Calls of one kind.
	 *
	 * @param string $kind create, price, terms or status.
	 */
	public function count( string $kind ): int {
		return count(
			array_filter(
				$this->calls,
				static function ( string $call ) use ( $kind ): bool {
					return 0 === strpos( $call, $kind . ':' );
				}
			)
		);
	}

	private function guard(): void {
		if ( $this->fail ) {
			throw new PlanException( 'WooCommerce said no.' );
		}
	}
}
