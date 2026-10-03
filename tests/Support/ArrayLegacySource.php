<?php
/**
 * In-memory 1.x source for tests.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

use Devllo\WPLicenseIt\Migration\LegacySource;

/**
 * 1.x rows held in arrays.
 */
final class ArrayLegacySource implements LegacySource {

	/**
	 * 1.x license rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $licenses = array();

	/**
	 * 1.x order rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $orders = array();

	/**
	 * Adds a 1.x license row with sensible defaults.
	 *
	 * @param int                  $id        Row ID.
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	public function add_license( int $id, array $overrides = array() ): void {
		$this->licenses[ $id ] = $overrides + array(
			'id'              => (string) $id,
			'user_id'         => '5',
			'product_id'      => '7',
			'license_key'     => 'KEY' . $id,
			'product_api_key' => 'prod-key',
			'email'           => "Buyer{$id}@Example.com",
			'license_status'  => 'active',
			'valid_until'     => '0000-00-00 00:00:00',
			'created_at'      => '2025-03-01 10:00:00',
			'updated_at'      => '2025-03-01 10:00:00',
		);
	}

	/**
	 * Adds a 1.x order row with sensible defaults.
	 *
	 * @param int                  $id        Row ID.
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	public function add_order( int $id, array $overrides = array() ): void {
		$this->orders[ $id ] = $overrides + array(
			'id'              => (string) $id,
			'user_id'         => '5',
			'product_id'      => '7',
			'order_number'    => '#20250301-AB' . $id,
			'order_sub_total' => '29.99',
			'order_total'     => '29.99',
			'order_email'     => 'buyer@example.com',
			'first_name'      => 'Ada',
			'last_name'       => 'Lovelace',
			'billing_company' => '',
			'billing_address' => '1 Main St',
			'billing_state'   => 'CA',
			'billing_city'    => 'LA',
			'billing_country' => 'US',
			'billing_phone'   => '555',
			'postal_code'     => '90001',
			'order_status'    => '',
			'discount_code'   => '',
			'created_at'      => '2025-03-01 10:00:05',
			'updated_at'      => '2025-03-01 10:00:05',
		);
	}

	public function licenses_after( int $after_id, int $limit ): array {
		return $this->after( $this->licenses, $after_id, $limit );
	}

	public function orders_after( int $after_id, int $limit ): array {
		return $this->after( $this->orders, $after_id, $limit );
	}

	public function count_licenses(): int {
		return count( $this->licenses );
	}

	public function count_orders(): int {
		return count( $this->orders );
	}

	public function sample_usable_licenses( int $limit ): array {
		$usable = array_filter(
			$this->licenses,
			static fn( array $row ): bool => 'active' === $row['license_status'] && '0000-00-00 00:00:00' === $row['valid_until']
		);

		return array_slice( array_reverse( array_values( $usable ) ), 0, $limit );
	}

	/**
	 * Rows with an ID above the cursor.
	 *
	 * @param array<int, array<string, mixed>> $rows     Rows by ID.
	 * @param int                              $after_id Cursor.
	 * @param int                              $limit    Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function after( array $rows, int $after_id, int $limit ): array {
		ksort( $rows );

		$out = array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['id'] > $after_id ) );

		return array_slice( $out, 0, $limit );
	}
}
