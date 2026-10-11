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
		$terms     = $product_ok ? $this->check_terms( $software_id, $plan, $order ) : $this->skipped( 'terms', 'The license terms' );
		$results[] = $terms;

		$package    = $this->check_package( $software_id );
		$results[]  = $package;
		$package_ok = CheckResult::PASS === $package->status;

		$sandbox    = new Sandbox( $this->clock );
		$license    = null;
		$purchase   = CheckResult::PASS === $terms->status && null !== $order ? $this->check_purchase( $software_id, $plan, $order, $sandbox, $license ) : $this->skipped( 'purchase', 'A purchase issues a license' );
		$results[]  = $purchase;
		$purchase_ok = CheckResult::PASS === $purchase->status && null !== $license;

		$api = $sandbox->api( $this->catalog );

		$results[] = $purchase_ok ? $this->check_activation( $software_id, $plan, $license, $api ) : $this->skipped( 'activation', 'The license can be activated' );
		$results[] = $purchase_ok && $package_ok ? $this->check_download( $software_id, $license, $api ) : $this->skipped( 'download', 'The download works' );
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
		$label = 'The WooCommerce product';

		if ( 0 === $plan->woo_product_id ) {
			return new CheckResult( 'product', $label, CheckResult::FAIL, 'This plan has no WooCommerce product yet. Save the plans to create it.' );
		}

		$remote = $this->woo->find( $plan->woo_product_id );
		if ( null === $remote || $remote->is_trashed() ) {
			return new CheckResult( 'product', $label, CheckResult::FAIL, 'The WooCommerce product was deleted. Use "Create it again" on the plan.' );
		}

		if ( 'simple' !== $remote->type ) {
			return new CheckResult( 'product', $label, CheckResult::FAIL, 'The WooCommerce product is a ' . $remote->type . ' product. Plans are sold as simple products. Change its type back to Simple product.' );
		}

		if ( $remote->software_id !== $software_id ) {
			return new CheckResult( 'product', $label, CheckResult::FAIL, 0 === $remote->software_id ? 'The WooCommerce product is not linked to this software, so buying it would issue no license. Save the plans to link it.' : 'The WooCommerce product is linked to a different software product. Save the plans to correct it.' );
		}

		return new CheckResult( 'product', $label, CheckResult::PASS, 'WooCommerce product #' . $remote->id . ' exists and is linked to this software.' );
	}

	/**
	 * Check 2: the terms an order would carry.
	 *
	 * @param int            $software_id License product ID.
	 * @param Plan           $plan        Plan.
	 * @param OrderData|null $order       Receives the order that was built.
	 */
	private function check_terms( int $software_id, Plan $plan, ?OrderData &$order ): CheckResult {
		$label = 'The license terms';

		try {
			$order = $this->orders->build( $plan->woo_product_id, 1 );
		} catch ( \Exception $e ) {
			return new CheckResult( 'terms', $label, CheckResult::FAIL, $e->getMessage() );
		}

		$item = $order->items[0] ?? null;
		if ( null === $item || $item->wplit_product_id !== $software_id ) {
			$order = null;

			return new CheckResult( 'terms', $label, CheckResult::FAIL, 'An order for this product would not issue a license for this software. Save the plans to link it.' );
		}

		if ( $item->activation_limit !== $plan->sites || $item->period !== $plan->period ) {
			$order = null;

			return new CheckResult(
				'terms',
				$label,
				CheckResult::FAIL,
				sprintf(
					'The plan says %1$s for %2$s, but an order would carry %3$s for %4$s. Save the plans again to correct WooCommerce.',
					self::sites_text( $plan->sites ),
					self::period_text( $plan->period ),
					self::sites_text( $item->activation_limit ),
					self::period_text( $item->period )
				)
			);
		}

		return new CheckResult( 'terms', $label, CheckResult::PASS, 'An order would carry ' . self::sites_text( $plan->sites ) . ' for ' . self::period_text( $plan->period ) . ', as the plan says.' );
	}

	/**
	 * Check 3: the software and its zip.
	 *
	 * @param int $software_id License product ID.
	 */
	private function check_package( int $software_id ): CheckResult {
		$label   = 'The software and its zip';
		$product = $this->catalog->find( $software_id );

		if ( null === $product ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, 'This license product is not published. Publish it (not Draft), or customers will get "unknown product".' );
		}

		if ( '' === trim( $product->version ) ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, 'The software has no version. Upload the zip, or enter a version.' );
		}

		if ( ! $product->has_package ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, 'No zip is stored for this software, or the file is missing from the server. Upload the zip again.' );
		}

		$file = $this->catalog->package_file( $software_id );
		if ( null === $file ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, 'The zip is missing from the server. Upload it again.' );
		}

		try {
			$info = PackageReader::read( $file );
		} catch ( PackageException $e ) {
			return new CheckResult( 'package', $label, CheckResult::FAIL, 'The stored zip is not a usable plugin or theme: ' . $e->getMessage() );
		}

		return new CheckResult( 'package', $label, CheckResult::PASS, $info->name . ' ' . $info->version . ' is published and its zip opens.' );
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
		$label = 'A purchase issues a license';
		$now   = ( $this->clock )();

		try {
			$interval = PeriodParser::parse( $plan->period );
		} catch ( \InvalidArgumentException $e ) {
			return new CheckResult( 'purchase', $label, CheckResult::FAIL, 'The plan\'s period is not valid: ' . $e->getMessage() );
		}

		$report = $sandbox->processor()->issue( $order );
		$ids    = $sandbox->links->links[ $order->items[0]->item_id ] ?? array();

		if ( 1 !== $report->issued || 1 !== count( $ids ) ) {
			$why = $report->notes ? ' ' . implode( ' ', $report->notes ) : '';

			return new CheckResult( 'purchase', $label, CheckResult::FAIL, 'An order for one unit issued ' . $report->issued . ' licenses instead of 1.' . $why );
		}

		$issued = $sandbox->licenses()->find( $ids[0] );
		if ( null === $issued || $issued->product_id !== $software_id || $issued->activation_limit !== $plan->sites ) {
			return new CheckResult( 'purchase', $label, CheckResult::FAIL, 'The license that was issued does not match the plan.' );
		}

		$expected = null === $interval ? null : $now->add( $interval )->format( 'Y-m-d' );
		$actual   = null === $issued->expires_at ? null : $issued->expires_at->format( 'Y-m-d' );
		if ( $expected !== $actual ) {
			return new CheckResult( 'purchase', $label, CheckResult::FAIL, 'The license expires on ' . ( $actual ?? 'never' ) . ' but the plan says ' . ( $expected ?? 'never' ) . '.' );
		}

		$license = $issued;

		return new CheckResult( 'purchase', $label, CheckResult::PASS, 'One license was issued for ' . self::sites_text( $plan->sites ) . ', ' . ( null === $actual ? 'never expiring.' : 'expiring ' . $actual . '.' ) );
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
		$label = 'The license can be activated';
		$base  = array(
			'license_key' => $license->license_key,
			'product_id'  => $software_id,
		);

		$valid = $api->validate( $base, self::CLIENT );
		if ( 200 !== $valid->status ) {
			return new CheckResult( 'activation', $label, CheckResult::FAIL, 'A new license did not validate (' . self::code( $valid ) . ').' );
		}

		$tries = $plan->sites >= 1 && $plan->sites <= self::MAX_TRY_SITES;
		$count = $tries ? $plan->sites : 1;

		for ( $i = 1; $i <= $count; $i++ ) {
			$activated = $api->activate( $base + array( 'site_url' => self::site( $i ) ), self::CLIENT );
			if ( 200 !== $activated->status ) {
				return new CheckResult( 'activation', $label, CheckResult::FAIL, 'Site ' . $i . ' of ' . $count . ' could not be activated (' . self::code( $activated ) . ').' );
			}
		}

		if ( ! $tries ) {
			$freed = $api->deactivate( $base + array( 'site_url' => self::site( 1 ) ), self::CLIENT );
			if ( 200 !== $freed->status ) {
				return new CheckResult( 'activation', $label, CheckResult::FAIL, 'Deactivating a site failed (' . self::code( $freed ) . ').' );
			}

			return new CheckResult( 'activation', $label, CheckResult::PASS, 'It activated on one site and deactivated again. The site limit was not tried (' . ( 0 === $plan->sites ? 'the plan is unlimited' : 'more than ' . self::MAX_TRY_SITES . ' sites' ) . ').' );
		}

		$over = $api->activate( $base + array( 'site_url' => self::site( $count + 1 ) ), self::CLIENT );
		if ( 403 !== $over->status || 'limit_reached' !== ( $over->body['code'] ?? '' ) ) {
			return new CheckResult( 'activation', $label, CheckResult::FAIL, 'Site ' . ( $count + 1 ) . ' was allowed on a ' . $count . '-site license (' . self::code( $over ) . '). The site limit is not being enforced.' );
		}

		$freed = $api->deactivate( $base + array( 'site_url' => self::site( 1 ) ), self::CLIENT );
		$again = 200 === $freed->status ? $api->activate( $base + array( 'site_url' => self::site( $count + 1 ) ), self::CLIENT ) : $freed;
		if ( 200 !== $again->status ) {
			return new CheckResult( 'activation', $label, CheckResult::FAIL, 'Freeing a site did not make room for another (' . self::code( $again ) . ').' );
		}

		return new CheckResult( 'activation', $label, CheckResult::PASS, 'It activated on ' . $count . ' site' . ( 1 === $count ? '' : 's' ) . ', the next site was refused, and freeing one made room.' );
	}

	/**
	 * Check 6: the update check hands out a download link that works.
	 *
	 * @param int                                  $software_id License product ID.
	 * @param \Devllo\WPLicenseIt\Licenses\License $license     The license from the purchase.
	 * @param ApiService                           $api         API logic over the sandbox.
	 */
	private function check_download( int $software_id, $license, ApiService $api ): CheckResult {
		$label = 'The download works';

		$update = $api->check_update(
			array(
				'license_key' => $license->license_key,
				'product_id'  => $software_id,
			),
			self::CLIENT
		);
		if ( 200 !== $update->status || empty( $update->body['download_url'] ) ) {
			return new CheckResult( 'download', $label, CheckResult::FAIL, 'The update check gave no download link (' . self::code( $update ) . ').' );
		}

		$token    = (string) $update->body['download_url'];
		$download = $api->download( $token, self::CLIENT );
		$expected = $this->catalog->package_file( $software_id );

		if ( 200 !== $download->status || null === $download->file || $download->file !== $expected ) {
			return new CheckResult( 'download', $label, CheckResult::FAIL, 'The download link did not lead to the stored zip (' . self::code( $download ) . ').' );
		}

		$tampered = $api->download( $token . 'x', self::CLIENT );
		if ( 403 !== $tampered->status ) {
			return new CheckResult( 'download', $label, CheckResult::FAIL, 'A download link that was changed was still accepted.' );
		}

		return new CheckResult( 'download', $label, CheckResult::PASS, 'The update check gave a signed link that downloads ' . basename( (string) $download->file ) . ', and a changed link is refused.' );
	}

	/**
	 * Check 7: can this site reach its own license API. A failure is only a warning.
	 */
	private function check_reachable(): CheckResult {
		$label = 'This site can reach its own license API';
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
		return new CheckResult( $id, $label, CheckResult::SKIP, 'Not tried, because an earlier check did not pass.' );
	}

	/**
	 * Sites per license as words.
	 *
	 * @param int $sites Sites, 0 for unlimited.
	 */
	private static function sites_text( int $sites ): string {
		return 0 === $sites ? 'unlimited sites' : $sites . ' site' . ( 1 === $sites ? '' : 's' );
	}

	/**
	 * A period as words.
	 *
	 * @param string $period Period setting.
	 */
	private static function period_text( string $period ): string {
		$names = array(
			'lifetime' => 'a lifetime',
			'P1M'      => '1 month',
			'P3M'      => '3 months',
			'P6M'      => '6 months',
			'P1Y'      => '1 year',
			'P2Y'      => '2 years',
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
