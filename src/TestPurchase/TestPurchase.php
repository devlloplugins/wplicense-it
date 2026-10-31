<?php
/**
 * The test purchase.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\TestPurchase;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Api\ApiService;
use Devllo\WPLicenseIt\Api\ProductCatalog;
use Devllo\WPLicenseIt\DryRun\Sandbox;
use Devllo\WPLicenseIt\Packages\PackageException;
use Devllo\WPLicenseIt\Packages\PackageReader;
use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Plans\ProductGateway;
use Devllo\WPLicenseIt\WooCommerce\OrderData;
use Devllo\WPLicenseIt\WooCommerce\PeriodParser;

/**
 * Tries one plan the way a customer would, without leaving anything behind.
 *
 * The real order processor and the real license API logic run over in-memory storage (see Sandbox), so no
 * order, license or email is created and nothing shows in sales reports. Seven checks run in order. A check
 * whose earlier steps failed is skipped. Only the last one (can this site reach its own API) is a warning
 * instead of a failure.
 */
final class TestPurchase {

	private const CLIENT        = 'test-purchase';
	private const MAX_TRY_SITES = 10;

	/**
	 * @var ProductGateway
	 */
	private $woo;

	/**
	 * @var ProductCatalog
	 */
	private $catalog;

	/**
	 * @var OrderBuilder
	 */
	private $orders;

	/**
	 * @var ReachabilityProbe
	 */
	private $probe;

	/**
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param ProductGateway    $woo     WooCommerce.
	 * @param ProductCatalog    $catalog Software products and their zip files.
	 * @param OrderBuilder      $orders  Builds the order to try.
	 * @param ReachabilityProbe $probe   Asks whether the site can reach its own API.
	 * @param callable|null     $clock   Returns the current UTC DateTimeImmutable.
	 */
	public function __construct( ProductGateway $woo, ProductCatalog $catalog, OrderBuilder $orders, ReachabilityProbe $probe, ?callable $clock = null ) {
		$this->woo     = $woo;
		$this->catalog = $catalog;
		$this->orders  = $orders;
		$this->probe   = $probe;
		$this->clock   = $clock ?? static function (): DateTimeImmutable {
			return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		};
	}

	/**
	 * Runs every check for one plan.
	 *
	 * @param int  $software_id License product ID.
	 * @param Plan $plan        The plan to try.
	 */
	public function run( int $software_id, Plan $plan ): TestReport {
		$results = array();

		$product    = $this->check_product( $software_id, $plan );
		$results[]  = $product;
		$product_ok = CheckResult::PASS === $product->status;

		$order     = null;
		$terms     = $product_ok ? $this->check_terms( $software_id, $plan, $order ) : $this->skipped( 'terms', __( 'The license terms', 'wplicense-it' ) );
		$results[] = $terms;

		$package    = $this->check_package( $software_id );
		$results[]  = $package;
		$package_ok = CheckResult::PASS === $package->status;

		$sandbox     = new Sandbox( $this->clock );
		$license     = null;
		$purchase    = CheckResult::PASS === $terms->status && null !== $order ? $this->check_purchase( $software_id, $plan, $order, $sandbox, $license ) : $this->skipped( 'purchase', __( 'A purchase issues a license', 'wplicense-it' ) );
		$results[]   = $purchase;
		$purchase_ok = CheckResult::PASS === $purchase->status && null !== $license;

		$api = $sandbox->api( $this->catalog );

		$results[] = $purchase_ok ? $this->check_activation( $software_id, $plan, $license, $api ) : $this->skipped( 'activation', __( 'The license can be activated', 'wplicense-it' ) );
		$results[] = $purchase_ok && $package_ok ? $this->check_download( $software_id, $license, $api ) : $this->skipped( 'download', __( 'The download works', 'wplicense-it' ) );
		$results[] = $this->check_reachable();

		return new TestReport( $results );
	}

	/**
	 * Check 1: the WooCommerce product.
	 *
	 * @param int  $software_id License product ID.
	 * @param Plan $plan        Plan.
	 */
	private function check_product( int $software_id, Plan $plan ): CheckResult {
		$label = __( 'The WooCommerce product', 'wplicense-it' );

		if ( 0 === $plan->woo_product_id ) {
			return new CheckResult( 'product', $label, CheckResult::FAIL, __( 'This plan has no WooCommerce product yet. Save the plans to create it.', 'wplicense-it' ) );
		}

		$remote = $this->woo->find( $plan->woo_product_id );
		if ( null === $remote || $remote->is_trashed() ) {
			return new CheckResult( 'product', $label, CheckResult::FAIL, __( 'The WooCommerce product was deleted. Use "Create it again" on the plan.', 'wplicense-it' ) );
		}

		if ( 'simple' !== $remote->type ) {
			return new CheckResult(
				'product',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: %s: WooCommerce product type, such as variable. */
					__( 'The WooCommerce product is a %s product. Plans are sold as simple products. Change its type back to Simple product.', 'wplicense-it' ),
					$remote->type
				)
			);
		}

		if ( $remote->software_id !== $software_id ) {
			return new CheckResult( 'product', $label, CheckResult::FAIL, 0 === $remote->software_id ? __( 'The WooCommerce product is not linked to this software, so buying it would issue no license. Save the plans to link it.', 'wplicense-it' ) : __( 'The WooCommerce product is linked to a different software product. Save the plans to correct it.', 'wplicense-it' ) );
		}

		return new CheckResult(
			'product',
			$label,
			CheckResult::PASS,
			sprintf(
			/* translators: %d: WooCommerce product ID. */
				__( 'WooCommerce product #%d exists and is linked to this software.', 'wplicense-it' ),
				$remote->id
			)
		);
	}

	/**
	 * Check 2: the terms an order would carry.
	 *
	 * @param int            $software_id License product ID.
	 * @param Plan           $plan        Plan.
	 * @param OrderData|null $order       Receives the order that was built.
	 */
	private function check_terms( int $software_id, Plan $plan, ?OrderData &$order ): CheckResult {
		$label = __( 'The license terms', 'wplicense-it' );

		try {
			$order = $this->orders->build( $plan->woo_product_id, 1 );
		} catch ( \Exception $e ) {
			return new CheckResult( 'terms', $label, CheckResult::FAIL, $e->getMessage() );
		}

		$item = $order->items[0] ?? null;
		if ( null === $item || $item->wplit_product_id !== $software_id ) {
			$order = null;

			return new CheckResult( 'terms', $label, CheckResult::FAIL, __( 'An order for this product would not issue a license for this software. Save the plans to link it.', 'wplicense-it' ) );
		}

		if ( $item->activation_limit !== $plan->sites || $item->period !== $plan->period ) {
			$order = null;

			return new CheckResult(
				'terms',
				$label,
				CheckResult::FAIL,
				sprintf(
					/* translators: 1: sites in the plan, 2: period in the plan, 3: sites an order would carry, 4: period an order would carry. */
					__( 'The plan says %1$s for %2$s, but an order would carry %3$s for %4$s. Save the plans again to correct WooCommerce.', 'wplicense-it' ),
					self::sites_text( $plan->sites ),
					self::period_text( $plan->period ),
					self::sites_text( $item->activation_limit ),
					self::period_text( $item->period )
				)
			);
		}

		return new CheckResult(
			'terms',
			$label,
			CheckResult::PASS,
			sprintf(
			/* translators: 1: number of sites, 2: license period. */
				__( 'An order would carry %1$s for %2$s, as the plan says.', 'wplicense-it' ),
				self::sites_text( $plan->sites ),
				self::period_text( $plan->period )
			)
		);
	}

	/**
	 * Check 3: the software and its zip.
	 *
	 * @param int $software_id License product ID.
	 */
	private function check_package( int $software_id ): CheckResult {
		$label   = __( 'The software and its zip', 'wplicense-it' );
		$product = $this->catalog->find( $software_id );

		if ( null === $product ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, __( 'This license product is not published. Publish it (not Draft), or customers will get "unknown product".', 'wplicense-it' ) );
		}

		if ( '' === trim( $product->version ) ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, __( 'The software has no version. Upload the zip, or enter a version.', 'wplicense-it' ) );
		}

		if ( ! $product->has_package ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, __( 'No zip is stored for this software, or the file is missing from the server. Upload the zip again.', 'wplicense-it' ) );
		}

		$file = $this->catalog->package_file( $software_id );
		if ( null === $file ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, __( 'The zip is missing from the server. Upload it again.', 'wplicense-it' ) );
		}

		try {
			$info = PackageReader::read( $file );
		} catch ( PackageException $e ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, __( 'The stored zip is not a usable plugin or theme:', 'wplicense-it' ) . ' ' . $e->getMessage() );
		}

		return new CheckResult(
			'package',
			$label,
			CheckResult::PASS,
			sprintf(
			/* translators: 1: software name, 2: version. */
				__( '%1$s %2$s is published and its zip opens.', 'wplicense-it' ),
				$info->name,
				$info->version
			)
		);
	}

	/**
	 * Check 4: a purchase issues a license.
	 *
	 * @param int                                       $software_id License product ID.
	 * @param Plan                                      $plan        Plan.
	 * @param OrderData                                 $order       The order to try.
	 * @param Sandbox                                   $sandbox     Throwaway licensing.
	 * @param \Devllo\WPLicenseIt\Licenses\License|null $license     Receives the issued license.
	 */
	private function check_purchase( int $software_id, Plan $plan, OrderData $order, Sandbox $sandbox, &$license ): CheckResult {
		$label = __( 'A purchase issues a license', 'wplicense-it' );
		$now   = ( $this->clock )();

		try {
			$interval = PeriodParser::parse( $plan->period );
		} catch ( \InvalidArgumentException $e ) {
			return new CheckResult( 'purchase', $label, CheckResult::FAIL, __( 'The plan\'s period is not valid:', 'wplicense-it' ) . ' ' . $e->getMessage() );
		}

		$report = $sandbox->processor()->issue( $order );
		$ids    = $sandbox->links->links[ $order->items[0]->item_id ] ?? array();

		if ( 1 !== $report->issued || 1 !== count( $ids ) ) {
			$why = $report->notes ? ' ' . implode( ' ', $report->notes ) : '';

			return new CheckResult(
				'purchase',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: %d: number of licenses issued. */
					__( 'An order for one unit issued %d licenses instead of 1.', 'wplicense-it' ),
					$report->issued
				) . $why
			);
		}

		$issued = $sandbox->licenses()->find( $ids[0] );
		if ( null === $issued || $issued->product_id !== $software_id || $issued->activation_limit !== $plan->sites ) {
			return new CheckResult( 'purchase', $label, CheckResult::FAIL, __( 'The license that was issued does not match the plan.', 'wplicense-it' ) );
		}

		$expected = null === $interval ? null : $now->add( $interval )->format( 'Y-m-d' );
		$actual   = null === $issued->expires_at ? null : $issued->expires_at->format( 'Y-m-d' );
		if ( $expected !== $actual ) {
			return new CheckResult(
				'purchase',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: 1: date the license expires (or never), 2: date the plan gives (or never). */
					__( 'The license expires on %1$s but the plan says %2$s.', 'wplicense-it' ),
					$actual ?? __( 'never', 'wplicense-it' ),
					$expected ?? __( 'never', 'wplicense-it' )
				)
			);
		}

		$license = $issued;

		return new CheckResult(
			'purchase',
			$label,
			CheckResult::PASS,
			( null === $actual
			? sprintf(
				/* translators: %s: number of sites. */
				__( 'One license was issued for %s, never expiring.', 'wplicense-it' ),
				self::sites_text( $plan->sites )
			)
			: sprintf(
				/* translators: 1: number of sites, 2: expiry date. */
				__( 'One license was issued for %1$s, expiring %2$s.', 'wplicense-it' ),
				self::sites_text( $plan->sites ),
				$actual
			) )
		);
	}

	/**
	 * Check 5: activation and the site limit, through the real API logic.
	 *
	 * @param int                                  $software_id License product ID.
	 * @param Plan                                 $plan        Plan.
	 * @param \Devllo\WPLicenseIt\Licenses\License $license     The license from the purchase.
	 * @param ApiService                           $api         API logic over the sandbox.
	 */
	private function check_activation( int $software_id, Plan $plan, $license, ApiService $api ): CheckResult {
		$label = __( 'The license can be activated', 'wplicense-it' );
		$base  = array(
			'license_key' => $license->license_key,
			'product_id'  => $software_id,
		);

		$valid = $api->validate( $base, self::CLIENT );
		if ( 200 !== $valid->status ) {
			return new CheckResult(
				'activation',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: %s: what the API answered. */
					__( 'A new license did not validate (%s).', 'wplicense-it' ),
					self::code( $valid )
				)
			);
		}

		$tries = $plan->sites >= 1 && $plan->sites <= self::MAX_TRY_SITES;
		$count = $tries ? $plan->sites : 1;

		for ( $i = 1; $i <= $count; $i++ ) {
			$activated = $api->activate( $base + array( 'site_url' => self::site( $i ) ), self::CLIENT );
			if ( 200 !== $activated->status ) {
				return new CheckResult(
					'activation',
					$label,
					CheckResult::FAIL,
					sprintf(
					/* translators: 1: site number, 2: number of sites, 3: what the API answered. */
						__( 'Site %1$d of %2$d could not be activated (%3$s).', 'wplicense-it' ),
						$i,
						$count,
						self::code( $activated )
					)
				);
			}
		}

		if ( ! $tries ) {
			$freed = $api->deactivate( $base + array( 'site_url' => self::site( 1 ) ), self::CLIENT );
			if ( 200 !== $freed->status ) {
				return new CheckResult(
					'activation',
					$label,
					CheckResult::FAIL,
					sprintf(
					/* translators: %s: what the API answered. */
						__( 'Deactivating a site failed (%s).', 'wplicense-it' ),
						self::code( $freed )
					)
				);
			}

			return new CheckResult(
				'activation',
				$label,
				CheckResult::PASS,
				0 === $plan->sites
				? __( 'It activated on one site and deactivated again. The site limit was not tried (the plan is unlimited).', 'wplicense-it' )
				: sprintf(
					/* translators: %d: the most sites the test tries. */
					__( 'It activated on one site and deactivated again. The site limit was not tried (more than %d sites).', 'wplicense-it' ),
					self::MAX_TRY_SITES
				)
			);
		}//end if

		$over = $api->activate( $base + array( 'site_url' => self::site( $count + 1 ) ), self::CLIENT );
		if ( 403 !== $over->status || 'limit_reached' !== ( $over->body['code'] ?? '' ) ) {
			return new CheckResult(
				'activation',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: 1: site number, 2: sites the license allows, 3: what the API answered. */
					__( 'Site %1$d was allowed on a %2$d-site license (%3$s). The site limit is not being enforced.', 'wplicense-it' ),
					$count + 1,
					$count,
					self::code( $over )
				)
			);
		}

		$freed = $api->deactivate( $base + array( 'site_url' => self::site( 1 ) ), self::CLIENT );
		$again = 200 === $freed->status ? $api->activate( $base + array( 'site_url' => self::site( $count + 1 ) ), self::CLIENT ) : $freed;
		if ( 200 !== $again->status ) {
			return new CheckResult(
				'activation',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: %s: what the API answered. */
					__( 'Freeing a site did not make room for another (%s).', 'wplicense-it' ),
					self::code( $again )
				)
			);
		}

		return new CheckResult(
			'activation',
			$label,
			CheckResult::PASS,
			sprintf(
			/* translators: %d: number of sites. */
				_n( 'It activated on %d site, the next site was refused, and freeing one made room.', 'It activated on %d sites, the next site was refused, and freeing one made room.', $count, 'wplicense-it' ),
				$count
			)
		);
	}

	/**
	 * Check 6: the update check hands out a download link that works.
	 *
	 * @param int                                  $software_id License product ID.
	 * @param \Devllo\WPLicenseIt\Licenses\License $license     The license from the purchase.
	 * @param ApiService                           $api         API logic over the sandbox.
	 */
	private function check_download( int $software_id, $license, ApiService $api ): CheckResult {
		$label = __( 'The download works', 'wplicense-it' );

		$update = $api->check_update(
			array(
				'license_key' => $license->license_key,
				'product_id'  => $software_id,
			),
			self::CLIENT
		);
		if ( 200 !== $update->status || empty( $update->body['download_url'] ) ) {
			return new CheckResult(
				'download',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: %s: what the API answered. */
					__( 'The update check gave no download link (%s).', 'wplicense-it' ),
					self::code( $update )
				)
			);
		}

		$token    = (string) $update->body['download_url'];
		$download = $api->download( $token, self::CLIENT );
		$expected = $this->catalog->package_file( $software_id );

		if ( 200 !== $download->status || null === $download->file || $download->file !== $expected ) {
			return new CheckResult(
				'download',
				$label,
				CheckResult::FAIL,
				sprintf(
				/* translators: %s: what the API answered. */
					__( 'The download link did not lead to the stored zip (%s).', 'wplicense-it' ),
					self::code( $download )
				)
			);
		}

		$tampered = $api->download( $token . 'x', self::CLIENT );
		if ( 403 !== $tampered->status ) {
			return new CheckResult( 'download', $label, CheckResult::FAIL, __( 'A download link that was changed was still accepted.', 'wplicense-it' ) );
		}

		return new CheckResult(
			'download',
			$label,
			CheckResult::PASS,
			sprintf(
			/* translators: %s: zip file name. */
				__( 'The update check gave a signed link that downloads %s, and a changed link is refused.', 'wplicense-it' ),
				basename( (string) $download->file )
			)
		);
	}

	/**
	 * Check 7: can this site reach its own license API. A failure is only a warning.
	 */
	private function check_reachable(): CheckResult {
		$label = __( 'This site can reach its own license API', 'wplicense-it' );
		$probe = $this->probe->probe();

		return new CheckResult( 'reachable', $label, $probe['ok'] ? CheckResult::PASS : CheckResult::WARN, $probe['message'] );
	}

	/**
	 * A check that did not run because an earlier one failed.
	 *
	 * @param string $id    Check ID.
	 * @param string $label Check label.
	 */
	private function skipped( string $id, string $label ): CheckResult {
		return new CheckResult( $id, $label, CheckResult::SKIP, __( 'Not tried, because an earlier check did not pass.', 'wplicense-it' ) );
	}

	/**
	 * Sites per license as words.
	 *
	 * @param int $sites Sites, 0 for unlimited.
	 */
	private static function sites_text( int $sites ): string {
		return 0 === $sites
			? __( 'unlimited sites', 'wplicense-it' )
			: sprintf(
				/* translators: %d: number of sites. */
				_n( '%d site', '%d sites', $sites, 'wplicense-it' ),
				$sites
			);
	}

	/**
	 * A period as words.
	 *
	 * @param string $period Period setting.
	 */
	private static function period_text( string $period ): string {
		$names = array(
			'lifetime' => __( 'a lifetime', 'wplicense-it' ),
			'P1M'      => __( '1 month', 'wplicense-it' ),
			'P3M'      => __( '3 months', 'wplicense-it' ),
			'P6M'      => __( '6 months', 'wplicense-it' ),
			'P1Y'      => __( '1 year', 'wplicense-it' ),
			'P2Y'      => __( '2 years', 'wplicense-it' ),
		);

		return $names[ $period ] ?? $period;
	}

	/**
	 * An address for the Nth made-up customer site.
	 *
	 * @param int $number Site number.
	 */
	private static function site( int $number ): string {
		return 'https://test-site-' . $number . '.example.com';
	}

	/**
	 * What an API response said, for messages.
	 *
	 * @param \Devllo\WPLicenseIt\Api\ApiResponse $response Response.
	 */
	private static function code( $response ): string {
		$code = is_array( $response->body ) && isset( $response->body['code'] ) ? (string) $response->body['code'] : 'no code';

		return 'HTTP ' . $response->status . ', ' . $code;
	}
}
