<?php
/**
 * In-memory license order records.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\DryRun;

use Devllo\WPLicenseIt\WooCommerce\LicenseOrderRepository;
use Devllo\WPLicenseIt\WooCommerce\OrderData;

/**
 * Keeps one record per WooCommerce order. Nothing is written to the database.
 */
final class MemoryLicenseOrderRepository implements LicenseOrderRepository {

	/**
	 * Records by WooCommerce order ID: id, number, total and status.
	 *
	 * @var array<int, array{id:int,number:string,total_minor:int,currency:string,status:string}>
	 */
	public array $records = array();

	public function record( OrderData $order ): int {
		if ( ! isset( $this->records[ $order->id ] ) ) {
			$this->records[ $order->id ] = array(
				'id'          => count( $this->records ) + 1,
				'number'      => 'WC-' . $order->number,
				'total_minor' => $order->total_minor,
				'currency'    => $order->currency,
				'status'      => 'completed',
			);
		}

		return $this->records[ $order->id ]['id'];
	}

	public function set_status( int $woo_order_id, string $status ): void {
		if ( isset( $this->records[ $woo_order_id ] ) ) {
			$this->records[ $woo_order_id ]['status'] = $status;
		}
	}
}
