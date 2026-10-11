<?php
/**
 * A plan.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * One way to buy a software product, for example "5 sites, 1 year, $99". Each plan is sold as one simple
 * WooCommerce product. The plan is the source of truth for price and terms.
 */
final class Plan {

	/**
	 * Constructor.
	 *
	 * @param string      $id              Stable short ID, so renaming a plan does not break its link.
	 * @param string      $name            Plan name shown in the shop.
	 * @param string      $price           Price as a plain decimal string in the store currency, such as "49" or "49.50".
	 * @param int         $sites           Sites per license, 0 for unlimited.
	 * @param string      $period          "lifetime", or P1M, P3M, P6M, P1Y, P2Y.
	 * @param int         $woo_product_id  Linked WooCommerce product, 0 if none yet.
	 * @param int|null    $synced_minor    Price in minor units the last time the two sides agreed.
	 * @param string      $synced_currency Currency of that price.
	 * @param int|null    $conflict_minor  Price found in WooCommerce when it differed from the plan, null if none.
	 * @param string|null $tested_at       When a test purchase of this plan last passed (UTC, Y-m-d H:i:s), null if never.
	 * @param string|null $tested_hash     Fingerprint of what was tested when it passed (see TestPurchase\Fingerprint), null if it has not passed.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $price,
		public int $sites,
		public string $period,
		public int $woo_product_id = 0,
		public ?int $synced_minor = null,
		public string $synced_currency = '',
		public ?int $conflict_minor = null,
		public ?string $tested_at = null,
		public ?string $tested_hash = null
	) {
	}

	/**
	 * Storage form.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'name'            => $this->name,
			'price'           => $this->price,
			'sites'           => $this->sites,
			'period'          => $this->period,
			'woo_product_id'  => $this->woo_product_id,
			'synced_minor'    => $this->synced_minor,
			'synced_currency' => $this->synced_currency,
			'conflict_minor'  => $this->conflict_minor,
			'tested_at'       => $this->tested_at,
			'tested_hash'     => $this->tested_hash,
		);
	}

	/**
	 * Builds a plan from stored data. Returns null if the data is not a plan, so a damaged row is skipped.
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || empty( $data['id'] ) || ! isset( $data['name'], $data['price'], $data['sites'], $data['period'] ) ) {
			return null;
		}

		return new self(
			(string) $data['id'],
			(string) $data['name'],
			(string) $data['price'],
			(int) $data['sites'],
			(string) $data['period'],
			(int) ( $data['woo_product_id'] ?? 0 ),
			isset( $data['synced_minor'] ) ? (int) $data['synced_minor'] : null,
			(string) ( $data['synced_currency'] ?? '' ),
			isset( $data['conflict_minor'] ) ? (int) $data['conflict_minor'] : null,
			isset( $data['tested_at'] ) ? (string) $data['tested_at'] : null,
			isset( $data['tested_hash'] ) ? (string) $data['tested_hash'] : null
		);
	}
}
