<?php
/**
 * A demo store: sample products, plans, customers and orders.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Demo;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Api\WpProductCatalog;
use Devllo\WPLicenseIt\Connect\ExamplePlugin;
use Devllo\WPLicenseIt\Database\Schema;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Plans\PlanInput;
use Devllo\WPLicenseIt\Plans\PlanResult;
use Devllo\WPLicenseIt\Plans\PlanSync;
use Devllo\WPLicenseIt\Plans\PostMetaPlanRepository;
use Devllo\WPLicenseIt\Plans\WcProductGateway;
use Devllo\WPLicenseIt\Products\ProductSaver;
use Devllo\WPLicenseIt\Products\WpFileStore;
use Devllo\WPLicenseIt\Products\WpProductMeta;
use Devllo\WPLicenseIt\TestPurchase\CheckResult;
use Devllo\WPLicenseIt\TestPurchase\Fingerprint;
use Devllo\WPLicenseIt\TestPurchase\RestLoopbackProbe;
use Devllo\WPLicenseIt\TestPurchase\TestPurchase;
use Devllo\WPLicenseIt\TestPurchase\WcOrderBuilder;
use Devllo\WPLicenseIt\WooCommerce\OrderReader;
use Devllo\WPLicenseIt\WooCommerce\WooItemLicenseStore;
use RuntimeException;
use WC_Order;
use wpdb;
use ZipArchive;

/**
 * Fills a site with a small, realistic store through the plugin's own code: two products with releases, plans that are
 * tested and published in WooCommerce, customers, and real WooCommerce orders in the states a seller meets (paid, waiting
 * for a bank transfer, refunded, cancelled, renewed, expiring soon, expired), sites activated, and a license given by
 * hand. Everything it makes is written down in one option as it is made, so remove() deletes exactly that, even after
 * a run that stopped half way. No email is sent while it runs.
 */
final class DemoStore {

	public const OPTION = 'wplit_demo';

	/**
	 * The customers, by first name. Their addresses are first name at example.com with a demo- prefix.
	 */
	private const CUSTOMERS = array(
		'alice' => array( 'Alice', 'Martin', 'FR' ),
		'bob'   => array( 'Bob', 'Okafor', 'NG' ),
		'carol' => array( 'Carol', 'Smith', 'GB' ),
		'dave'  => array( 'Dave', 'Kowalski', 'PL' ),
		'erin'  => array( 'Erin', 'Walsh', 'IE' ),
		'frank' => array( 'Frank', 'Meyer', 'DE' ),
		'gina'  => array( 'Gina', 'Rossi', 'IT' ),
		'henry' => array( 'Henry', 'Clark', 'US' ),
	);

	/**
	 * What was made, by kind: products, woo_products, users, orders, licenses.
	 *
	 * @var array<string, int[]>
	 */
	private array $made = array();

	/**
	 * Constructor.
	 *
	 * @param LicenseService $licenses Licensing core.
	 * @param wpdb           $db       Database.
	 * @param callable|null  $say      Called with a line of progress.
	 */
	public function __construct( private LicenseService $licenses, private wpdb $db, private $say = null ) {
	}

	/**
	 * What the demo made, or null if there is none.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function recorded(): ?array {
		$made = get_option( self::OPTION, null );

		return is_array( $made ) ? $made : null;
	}

	/**
	 * The demo customers' email addresses.
	 *
	 * @return string[]
	 */
	public static function emails(): array {
		$emails = array();
		foreach ( array_keys( self::CUSTOMERS ) as $name ) {
			$emails[] = 'demo-' . $name . '@example.com';
		}
		$emails[] = 'demo-press@example.com';

		return $emails;
	}

	/**
	 * Makes the demo store.
	 *
	 * @return string[] Notes worth showing: plans that could not be published, and why.
	 * @throws RuntimeException If there is a demo already, WooCommerce is not active, or something cannot be made.
	 */
	public function create(): array {
		if ( null !== self::recorded() ) {
			throw new RuntimeException( 'There is a demo store already. Remove it first: wp wplit demo remove' );
		}
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_create_order' ) ) {
			throw new RuntimeException( 'WooCommerce must be active: the demo sells through it.' );
		}
		foreach ( self::emails() as $email ) {
			if ( false !== get_user_by( 'email', $email ) ) {
				throw new RuntimeException( 'A user with the address ' . $email . ' exists already. The demo makes its own customers; delete that user first.' );
			}
		}

		$this->made = array(
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'products'     => array(),
			'woo_products' => array(),
			'users'        => array(),
			'orders'       => array(),
			'licenses'     => array(),
		);
		$this->save();

		add_filter( 'pre_wp_mail', '__return_false', 99 );

		try {
			return $this->fill();
		} finally {
			remove_filter( 'pre_wp_mail', '__return_false', 99 );
		}
	}

	/**
	 * Deletes everything the demo made.
	 *
	 * @return array<string, int> How many of each kind were deleted.
	 */
	public function remove(): array {
		$made = self::recorded();
		if ( null === $made ) {
			return array();
		}

		$ids   = static fn( string $kind ): array => array_values( array_filter( array_map( 'intval', (array) ( $made[ $kind ] ?? array() ) ) ) );
		$count = array_fill_keys( array( 'orders', 'licenses', 'woo_products', 'products', 'users' ), 0 );

		foreach ( $ids( 'orders' ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				foreach ( $order->get_refunds() as $refund ) {
					$refund->delete( true );
				}
				$order->delete( true );
				++$count['orders'];
			}
		}

		$count['licenses'] = $this->delete_licenses( $ids( 'licenses' ), $ids( 'orders' ) );

		foreach ( $ids( 'woo_products' ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->delete( true );
				++$count['woo_products'];
			}
		}

		foreach ( $ids( 'products' ) as $product_id ) {
			if ( 'wplit_product' === get_post_type( $product_id ) && wp_delete_post( $product_id, true ) ) {
				++$count['products'];
			}
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $ids( 'users' ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( false !== $user && in_array( strtolower( (string) $user->user_email ), self::emails(), true ) && wp_delete_user( $user_id ) ) {
				++$count['users'];
			}
		}

		delete_option( self::OPTION );

		return $count;
	}

	/**
	 * Makes the products, plans, customers and orders.
	 *
	 * @return string[] Notes.
	 */
	private function fill(): array {
		$notes = array();

		// Software: a plugin with two releases, and a theme.
		$plugin = $this->product( 'Demo Backup Pro', 'Backups for WordPress sites, to the cloud of your choice. (Demo product.)' );
		$this->release( $plugin, $this->plugin_zip( 'Demo Backup Pro', '1.0.0' ), 'First release.' );
		$this->release( $plugin, $this->plugin_zip( 'Demo Backup Pro', '1.1.0' ), "Scheduled backups.\nFaster restores." );
		$theme = $this->product( 'Demo Starter Theme', 'A clean starter theme for blogs and shops. (Demo product.)' );
		$this->release( $theme, $this->theme_zip( 'Demo Starter Theme', '2.0.0' ), 'Block patterns for shops.' );
		$this->say( 'Products: Demo Backup Pro (releases 1.0.0 and 1.1.0) and Demo Starter Theme (2.0.0).' );

		// Plans, tested and published like a seller would.
		$plans = array(
			$plugin => array(
				'single' => array( 'Single site', '49', 1, 'P1Y' ),
				'five'   => array( 'Five sites', '99', 5, 'P1Y' ),
				'agency' => array( 'Agency', '199', 0, 'P1Y' ),
			),
			$theme  => array(
				'theme' => array( 'Theme license', '39', 1, 'lifetime' ),
			),
		);
		$woo   = array();
		foreach ( $plans as $software => $rows ) {
			$woo += $this->plans( $software, $rows, $notes );
		}
		$this->say( 'Plans: 3 for the plugin, 1 for the theme, in WooCommerce.' );

		// Customers.
		$users = array();
		foreach ( self::CUSTOMERS as $key => $customer ) {
			$users[ $key ] = $this->customer( $key, $customer[0], $customer[1], $customer[2] );
		}
		$this->say( 'Customers: ' . count( $users ) . ', with addresses demo-<name>@example.com.' );

		// Orders, oldest first, with the sites each customer activated.
		$this->order( $users['gina'], $woo['five'], 400, 'card', 0, array( 'https://gina-studio.example.com' ), '1.0.0' );
		$this->order( $users['frank'], $woo['single'], 355, 'card', 0, array( 'https://frank-meyer.example.org' ), '1.1.0' );
		$bob = $this->order( $users['bob'], $woo['five'], 200, 'card', 0, array( 'https://okafor-agency.example.com', 'https://client-one.example.net', 'https://staging.client-one.example.net' ), '1.1.0' );
		$this->order( $users['erin'], $woo['theme'], 90, 'card', 0, array( 'https://erin-walsh.example.com' ), '2.0.0' );

		$henry = $this->order( $users['henry'], $woo['single'], 60, 'card' );
		$henry->update_status( 'cancelled', 'Demo: the customer asked to cancel.' );

		$this->order( $users['alice'], $woo['single'], 40, 'card', 0, array( 'https://alice-photography.example.com' ), '1.1.0' );

		$carol  = $this->order( $users['carol'], $woo['agency'], 20, 'card' );
		$refund = wc_create_refund(
			array(
				'order_id' => $carol->get_id(),
				'amount'   => (float) $carol->get_total(),
				'reason'   => 'Demo: bought the wrong plan.',
			)
		);
		if ( is_wp_error( $refund ) ) {
			$notes[] = 'Carol\'s order could not be refunded: ' . $refund->get_error_message();
		}

		// Bob renews his license early: the same license is extended by a year.
		$this->order( $users['bob'], $woo['five'], 10, 'card', $this->licenses_of( $bob )[0] ?? 0 );

		// Dave pays by bank transfer: the order waits on hold, and so does his license.
		$this->order( $users['dave'], $woo['single'], 2, 'bacs' );

		$this->say( 'Orders: 9 (paid, renewed, refunded, cancelled, one waiting for a bank transfer).' );

		// A license given by hand, for a reviewer.
		$gift = $this->licenses->issue_license( $plugin, 'demo-press@example.com', null, null, 1, ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->add( new DateInterval( 'P3M' ) ) );
		$this->record( 'licenses', $gift->id );
		$this->say( 'A license issued by hand for demo-press@example.com.' );

		// Gina's ran out a month ago: mark it expired, as the daily job would.
		$this->licenses->expire_due_licenses();

		return $notes;
	}

	/**
	 * A published license product.
	 *
	 * @param string $name        Name.
	 * @param string $description Short description.
	 * @throws RuntimeException If it cannot be made.
	 */
	private function product( string $name, string $description ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => 'wplit_product',
				'post_status' => 'publish',
				'post_title'  => $name,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'The product ' . $name . ' could not be made: ' . $id->get_error_message() );
		}
		$this->record( 'products', (int) $id );

		( new WpProductMeta() )->set( (int) $id, 'wplit_product_description', $description );

		return (int) $id;
	}

	/**
	 * Adds a release, through the same code as an upload on the Releases tab.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $zip        Path of the zip. Deleted afterwards.
	 * @param string $notes      Release notes.
	 * @throws RuntimeException If the release is refused.
	 */
	private function release( int $product_id, string $zip, string $notes ): void {
		$saver  = new ProductSaver( new WpProductMeta(), new WpFileStore( true ) );
		$report = $saver->save(
			$product_id,
			array( 'wplit_release_notes' => $notes ),
			array(
				'wplit_product_file_upload' => array(
					'name'     => sanitize_title( get_the_title( $product_id ) ) . '.zip',
					'tmp_name' => $zip,
					'size'     => (int) filesize( $zip ),
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);
		wp_delete_file( $zip );

		if ( $report->errors ) {
			throw new RuntimeException( 'A release of ' . get_the_title( $product_id ) . ' was refused: ' . implode( ' ', $report->errors ) );
		}
	}

	/**
	 * Saves plans, makes their WooCommerce products, runs the test purchase and publishes the ones that pass.
	 *
	 * @param int                                            $software License product ID.
	 * @param array<string, array{string,string,int,string}> $rows     Plans by key: name, price, sites, period.
	 * @param string[]                                       $notes    Notes, added to.
	 * @return array<string, int> WooCommerce product ID by plan key.
	 * @throws RuntimeException If the plans are refused.
	 */
	private function plans( int $software, array $rows, array &$notes ): array {
		$repo   = new PostMetaPlanRepository();
		$parsed = PlanInput::parse(
			array_map(
				static fn( array $row ): array => array(
					'name'   => $row[0],
					'price'  => $row[1],
					'sites'  => (string) $row[2],
					'period' => $row[3],
				),
				array_values( $rows )
			),
			array()
		);
		if ( $parsed['errors'] ) {
			throw new RuntimeException( 'The demo plans were refused: ' . implode( ' ', $parsed['errors'] ) );
		}

		$gateway = new WcProductGateway();
		$sync    = new PlanSync( $repo, $gateway );
		foreach ( $sync->save( $software, get_the_title( $software ), $parsed['plans'] ) as $result ) {
			if ( PlanResult::ERROR === $result->status ) {
				throw new RuntimeException( 'A demo plan could not be put in WooCommerce: ' . $result->message );
			}
		}

		$catalog = new WpProductCatalog();
		$tester  = new TestPurchase( $gateway, $catalog, new WcOrderBuilder( new OrderReader() ), new RestLoopbackProbe() );
		$woo     = array();
		$keys    = array_keys( $rows );

		foreach ( $repo->all( $software ) as $i => $plan ) {
			$this->record( 'woo_products', $plan->woo_product_id );
			$woo[ $keys[ $i ] ] = $plan->woo_product_id;

			$report = $tester->run( $software, $plan );
			$print  = Fingerprint::of( $software, $plan, $catalog->package_file( $software ) );
			$sync->record_test( $software, $plan->id, $report->passed(), $print, gmdate( 'Y-m-d H:i:s' ) );

			if ( $report->passed() ) {
				$sync->publish( $software, $plan->id, $print );
				continue;
			}

			// Left as a draft, as a seller would find it. Orders can still be made for it here.
			$failed = array();
			foreach ( $report->results as $check ) {
				if ( CheckResult::PASS !== $check->status && CheckResult::WARN !== $check->status ) {
					$failed[] = $check->label . ': ' . $check->message;
				}
			}
			$notes[] = 'The plan "' . $plan->name . '" did not pass its test purchase, so it stays a draft in WooCommerce. ' . implode( ' ', $failed );
		}//end foreach

		return $woo;
	}

	/**
	 * A customer account.
	 *
	 * @param string $key     Short name.
	 * @param string $first   First name.
	 * @param string $last    Last name.
	 * @param string $country Billing country.
	 * @throws RuntimeException If it cannot be made.
	 */
	private function customer( string $key, string $first, string $last, string $country ): int {
		$id = wp_insert_user(
			array(
				'user_login'   => 'demo-' . $key,
				'user_email'   => 'demo-' . $key . '@example.com',
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => $first . ' ' . $last,
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'The customer ' . $first . ' could not be made: ' . $id->get_error_message() );
		}
		$this->record( 'users', $id );

		$customer = new \WC_Customer( $id );
		$customer->set_billing_first_name( $first );
		$customer->set_billing_last_name( $last );
		$customer->set_billing_email( 'demo-' . $key . '@example.com' );
		$customer->set_billing_country( $country );
		$customer->save();

		return $id;
	}

	/**
	 * A WooCommerce order, placed some days ago, paid by card (completed at once, like a payment service does) or by bank
	 * transfer (on hold until the money is in). Its licenses are dated from the day it was paid.
	 *
	 * @param int      $user_id    Customer.
	 * @param int      $product_id WooCommerce product.
	 * @param int      $days_ago   How long ago it was placed.
	 * @param string   $method     'card' or 'bacs'.
	 * @param int      $renew   License to renew, or 0.
	 * @param string[] $sites   Sites the customer activated the new license on.
	 * @param string   $version Version installed on those sites.
	 * @throws RuntimeException If it cannot be made.
	 */
	private function order( int $user_id, int $product_id, int $days_ago, string $method, int $renew = 0, array $sites = array(), string $version = '' ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		if ( is_wp_error( $order ) ) {
			throw new RuntimeException( 'An order could not be made: ' . $order->get_error_message() );
		}
		$this->record( 'orders', $order->get_id() );

		$item = $order->add_product( wc_get_product( $product_id ), 1 );
		if ( $renew > 0 ) {
			wc_add_order_item_meta( $item, OrderReader::META_RENEW, $renew );
		}

		$customer = new \WC_Customer( $user_id );
		$order->set_address( $customer->get_billing(), 'billing' );
		$order->set_payment_method( 'card' === $method ? 'demo-card' : 'bacs' );
		$order->set_payment_method_title( 'card' === $method ? 'Card (demo)' : 'Direct bank transfer' );
		$order->set_created_via( 'checkout' );
		$order->calculate_totals();
		$order->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		$order->save();

		if ( 'card' !== $method ) {
			$order->update_status( 'on-hold', 'Demo: awaiting the bank transfer.' );

			return $order;
		}

		$order->payment_complete( 'demo-' . $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		$paid  = time() - $days_ago * DAY_IN_SECONDS;
		$order->set_date_paid( $paid );
		$order->set_date_completed( $paid );
		$order->save();

		$ids = $this->licenses_of( $order );
		foreach ( $ids as $license_id ) {
			$this->record( 'licenses', $license_id );
		}
		if ( 0 === $renew ) {
			$this->activate( $ids, $sites, $version );
			$this->backdate( $ids, $days_ago );
		}

		return $order;
	}

	/**
	 * The licenses an order issued or renewed.
	 *
	 * @param WC_Order $order Order.
	 * @return int[]
	 */
	private function licenses_of( WC_Order $order ): array {
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			$ids = array_merge( $ids, WooItemLicenseStore::ids( $item ) );
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Moves new licenses' dates back to the day their order was paid, so expiry and "expiring soon" look real.
	 *
	 * @param int[] $license_ids Licenses.
	 * @param int   $days        Days back.
	 */
	private function backdate( array $license_ids, int $days ): void {
		if ( ! $license_ids || $days <= 0 ) {
			return;
		}

		$in    = implode( ',', array_map( 'intval', $license_ids ) );
		$table = Schema::table( $this->db->prefix, 'licenses' );
		$sites = Schema::table( $this->db->prefix, 'activations' );
		$log   = Schema::table( $this->db->prefix, 'license_events' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from the prefix, IDs cast to integers; demo data only.
		$this->db->query( $this->db->prepare( "UPDATE {$table} SET created_at = DATE_SUB(created_at, INTERVAL %d DAY), expires_at = IF(expires_at IS NULL, NULL, DATE_SUB(expires_at, INTERVAL %d DAY)) WHERE id IN ({$in})", $days, $days ) );
		// The sites were activated a day after the purchase.
		$this->db->query( $this->db->prepare( "UPDATE {$sites} SET activated_at = DATE_SUB(activated_at, INTERVAL %d DAY), last_checked_at = DATE_SUB(last_checked_at, INTERVAL %d DAY) WHERE license_id IN ({$in})", $days - 1, $days - 1 ) );
		$this->db->query( $this->db->prepare( "UPDATE {$log} SET created_at = DATE_SUB(created_at, INTERVAL IF(type = 'activated', %d, %d) DAY) WHERE license_id IN ({$in})", $days - 1, $days ) );
		// phpcs:enable
	}

	/**
	 * Activates licenses on some sites, as the customer's plugin would.
	 *
	 * @param int[]    $license_ids Licenses.
	 * @param string[] $sites       Site addresses.
	 * @param string   $version     Version installed there.
	 */
	private function activate( array $license_ids, array $sites, string $version ): void {
		foreach ( $license_ids as $license_id ) {
			$license = $this->licenses->find( $license_id );
			if ( null === $license ) {
				continue;
			}
			foreach ( $sites as $site ) {
				$this->licenses->activate( $license->license_key, $site, $license->product_id, $version );
			}
		}
	}

	/**
	 * Deletes licenses with their sites and history, and the license records of the demo's orders (a renewal order has
	 * one that no license points to).
	 *
	 * @param int[] $ids       License IDs.
	 * @param int[] $order_ids WooCommerce order IDs.
	 * @return int How many licenses were deleted.
	 */
	private function delete_licenses( array $ids, array $order_ids ): int {
		$licenses = Schema::table( $this->db->prefix, 'licenses' );
		$orders   = Schema::table( $this->db->prefix, 'license_orders' );
		$deleted  = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from the prefix, IDs cast to integers; demo data only.
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			$this->db->query( 'DELETE FROM ' . Schema::table( $this->db->prefix, 'activations' ) . " WHERE license_id IN ({$in})" );
			$this->db->query( 'DELETE FROM ' . Schema::table( $this->db->prefix, 'license_events' ) . " WHERE license_id IN ({$in})" );
			$deleted = (int) $this->db->query( "DELETE FROM {$licenses} WHERE id IN ({$in})" );
		}
		if ( $order_ids ) {
			$external = "'" . implode( "','", array_map( 'intval', $order_ids ) ) . "'";
			// Only records no other license still points to.
			$this->db->query( "DELETE FROM {$orders} WHERE source = 'woocommerce' AND external_id IN ({$external}) AND id NOT IN (SELECT order_id FROM {$licenses} WHERE order_id IS NOT NULL)" );
		}
		// phpcs:enable

		return $deleted;
	}

	/**
	 * Builds the plugin's zip: the example plugin of this product, at a version.
	 *
	 * @param string $name    Plugin name.
	 * @param string $version Version.
	 */
	private function plugin_zip( string $name, string $version ): string {
		$files = ExamplePlugin::files( $name, '', untrailingslashit( home_url() ), (int) end( $this->made['products'] ) );
		foreach ( $files as $path => $content ) {
			$content = str_replace( ExamplePlugin::VERSION, $version, $content );
			if ( str_ends_with( $path, 'readme.txt' ) && ExamplePlugin::VERSION !== $version ) {
				$content = str_replace( '== Changelog ==', "== Changelog ==\n\n= {$version} =\n* Scheduled backups.\n* Faster restores.", $content );
				$content = str_replace( "= {$version} =\n* First version.", '= ' . ExamplePlugin::VERSION . " =\n* First version.", $content );
			}
			$files[ $path ] = $content;
		}

		return ExamplePlugin::build( $files, WPLICENSE_IT_DIR . 'sdk' );
	}

	/**
	 * Builds a small block theme's zip.
	 *
	 * @param string $name    Theme name.
	 * @param string $version Version.
	 * @throws RuntimeException If the zip cannot be made.
	 */
	private function theme_zip( string $name, string $version ): string {
		$slug = sanitize_title( $name );
		$path = wp_tempnam( $slug . '.zip' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create the theme zip.' );
		}
		$zip->addFromString( $slug . '/style.css', "/*\nTheme Name: {$name}\nDescription: A demo theme made by WPLicense It.\nVersion: {$version}\nRequires at least: 6.2\nTested up to: 6.7\nRequires PHP: 7.4\nLicense: GPL-2.0-or-later\n*/\n" );
		$zip->addFromString( $slug . '/templates/index.html', "<!-- wp:post-content /-->\n" );
		$zip->addFromString( $slug . '/theme.json', '{"version":2}' . "\n" );
		$zip->addFromString( $slug . '/readme.txt', "=== {$name} ===\nRequires at least: 6.2\nTested up to: 6.7\nRequires PHP: 7.4\nStable tag: {$version}\n\nA demo theme.\n\n== Changelog ==\n\n= {$version} =\n* Block patterns for shops.\n" );
		if ( ! $zip->close() ) {
			throw new RuntimeException( 'Could not write the theme zip.' );
		}

		return $path;
	}

	/**
	 * Writes down something the demo made, at once, so it can be removed even if a later step fails.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   ID.
	 */
	private function record( string $kind, int $id ): void {
		if ( $id > 0 && ! in_array( $id, $this->made[ $kind ], true ) ) {
			$this->made[ $kind ][] = $id;
			$this->save();
		}
	}

	/**
	 * Saves the record.
	 */
	private function save(): void {
		update_option( self::OPTION, $this->made, false );
	}

	/**
	 * Progress.
	 *
	 * @param string $line Line.
	 */
	private function say( string $line ): void {
		if ( null !== $this->say ) {
			( $this->say )( $line );
		}
	}
}
