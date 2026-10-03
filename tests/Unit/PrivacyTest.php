<?php
/**
 * Tests for the privacy export and erase tools.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\Activation;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Privacy\PrivacyService;
use Devllo\WPLicenseIt\Privacy\WpdbPrivacyRepository;
use Devllo\WPLicenseIt\Tests\Support\FakeWpdb;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Tests\Support\InMemoryActivationRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryLicenseRepository;
use Devllo\WPLicenseIt\Tests\Support\InMemoryPrivacyRepository;
use Devllo\WPLicenseIt\Tests\Support\RecordingEventLog;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/woocommerce-stubs.php';

/**
 * @covers \Devllo\WPLicenseIt\Privacy\PrivacyService
 * @covers \Devllo\WPLicenseIt\Privacy\WpdbPrivacyRepository
 */
final class PrivacyTest extends TestCase {

	private LicenseService $licenses;
	private InMemoryLicenseRepository $repo;
	private InMemoryPrivacyRepository $data;
	private PrivacyService $privacy;

	protected function setUp(): void {
		$this->repo     = new InMemoryLicenseRepository();
		$this->data     = new InMemoryPrivacyRepository( $this->repo );
		$this->licenses = new LicenseService( $this->repo, new InMemoryActivationRepository(), new RecordingEventLog(), new KeyGenerator(), new FixedClock( '2026-03-01 00:00:00' ) );
		$this->privacy  = new PrivacyService( $this->data );
	}

	private function utc( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Finds the values of an export item by field name.
	 *
	 * @param array<string, mixed> $item Export item.
	 * @return string[]
	 */
	private function values( array $item, string $name ): array {
		return array_values( array_map( static fn( array $row ): string => $row['value'], array_filter( $item['data'], static fn( array $row ): bool => $row['name'] === $name ) ) );
	}

	// Export.

	public function test_a_persons_licenses_sites_orders_and_history_are_exported(): void {
		$order_id = 1;
		$license  = $this->licenses->issue_license( 7, 'Ada@Example.com', 5, $order_id, 2, $this->utc( '2027-03-01 00:00:00' ) );
		$this->data->activations[ $license->id ] = array( new Activation( 1, $license->id, 'ada-site.com', false, 'active', '1.0.0', $this->utc( '2026-03-02 10:00:00' ), null, null ) );
		$this->data->events[ $license->id ]      = array( array( 'type' => 'issued', 'data' => array( 'product_id' => 7 ), 'created_at' => '2026-03-01 00:00:00' ) );
		$this->data->orders[ $order_id ]         = array( 'id' => 1, 'order_number' => 'WC-100', 'source' => 'woocommerce', 'currency' => 'USD', 'total_minor' => 2999, 'status' => 'completed', 'created_at' => '2026-03-01 00:00:00', 'user_id' => 5, 'billing' => array( 'first_name' => 'Ada', 'billing_phone' => '555' ) );

		$export = $this->privacy->export( 'ada@example.com', 5, 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 2, $export['data'] );

		$item = $export['data'][0];
		$this->assertSame( 'wplicense-it-licenses', $item['group_id'] );
		$this->assertSame( array( $license->license_key ), $this->values( $item, 'License key' ) );
		$this->assertSame( array( 'ada@example.com' ), $this->values( $item, 'Email' ) );
		$this->assertSame( array( '2' ), $this->values( $item, 'Sites allowed' ) );
		$this->assertSame( array( 'ada-site.com (active, 2026-03-02)' ), $this->values( $item, 'Activated site' ) );
		$this->assertSame( array( '2026-03-01 00:00:00 UTC: issued (product_id: 7)' ), $this->values( $item, 'History' ) );

		$order = $export['data'][1];
		$this->assertSame( 'wplicense-it-orders', $order['group_id'] );
		$this->assertSame( array( '29.99 USD' ), $this->values( $order, 'Total' ) );
		$this->assertSame( array( 'Ada' ), $this->values( $order, 'First name' ) );
		$this->assertSame( array( '555' ), $this->values( $order, 'Phone' ) );
	}

	public function test_licenses_linked_by_account_are_found_even_under_another_email(): void {
		$this->licenses->issue_license( 7, 'old-address@example.com', 5 );
		$this->licenses->issue_license( 7, 'someone-else@example.com', 6 );

		$export = $this->privacy->export( 'new-address@example.com', 5, 1 );

		$this->assertCount( 1, $export['data'] );
		$this->assertSame( array( 'old-address@example.com' ), $this->values( $export['data'][0], 'Email' ) );
	}

	public function test_nothing_is_exported_for_someone_with_no_data(): void {
		$this->licenses->issue_license( 7, 'ada@example.com', 5 );

		$export = $this->privacy->export( 'stranger@example.com', null, 1 );

		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );
	}

	public function test_a_long_list_is_exported_in_pages(): void {
		for ( $i = 0; $i < PrivacyService::PER_PAGE + 5; $i++ ) {
			$this->licenses->issue_license( 7, 'ada@example.com' );
		}

		$first  = $this->privacy->export( 'ada@example.com', null, 1 );
		$second = $this->privacy->export( 'ada@example.com', null, 2 );

		$this->assertCount( PrivacyService::PER_PAGE, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 5, $second['data'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_old_1x_records_are_exported_once(): void {
		$this->data->legacy['licenses'][] = array( 'id' => 3, 'user_id' => 5, 'product_id' => 7, 'license_key' => 'OLDKEY', 'email' => 'ada@example.com', 'valid_until' => '0000-00-00 00:00:00', 'created_at' => '2025-01-01 00:00:00' );
		$this->data->legacy['orders'][]   = array( 'id' => 4, 'user_id' => 5, 'order_number' => '#2025-AB', 'order_total' => '29.99', 'order_email' => 'ada@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'billing_address' => '1 Main St', 'billing_phone' => '555', 'created_at' => '2025-01-01 00:00:00' );

		$page1 = $this->privacy->export( 'ada@example.com', 5, 1 );
		$page2 = $this->privacy->export( 'ada@example.com', 5, 2 );

		$groups = array_column( $page1['data'], 'group_id' );
		$this->assertContains( 'wplicense-it-legacy-licenses', $groups );
		$this->assertContains( 'wplicense-it-legacy-orders', $groups );
		$this->assertSame( array(), $page2['data'] );

		$legacy_order = $page1['data'][1];
		$this->assertSame( array( '1 Main St' ), $this->values( $legacy_order, 'Address' ) );
		$this->assertSame( array( 'Lovelace' ), $this->values( $legacy_order, 'Last name' ) );
	}

	// Erase.

	public function test_erasing_removes_the_identity_but_keeps_the_license_working_for_support(): void {
		$license = $this->licenses->issue_license( 7, 'ada@example.com', 5, 1, 1 );
		$this->data->orders[1]              = array( 'id' => 1, 'order_number' => 'WC-100', 'source' => 'woocommerce', 'currency' => 'USD', 'total_minor' => 2999, 'status' => 'completed', 'created_at' => '2026-03-01 00:00:00', 'user_id' => 5, 'billing' => array( 'first_name' => 'Ada' ) );
		$this->data->events[ $license->id ] = array( array( 'type' => 'updated', 'data' => array( 'email' => array( 'a@x.com', 'b@x.com' ), 'activation_limit' => array( 1, 2 ) ), 'created_at' => '2026-03-01 00:00:00' ) );

		$result = $this->privacy->erase( 'ADA@example.com', 5 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringContainsString( 'license keys', $result['messages'][0] );

		$kept = $this->repo->find( $license->id );
		$this->assertSame( PrivacyService::placeholder( $license->id ), $kept->email );
		$this->assertNull( $kept->user_id );
		$this->assertSame( $license->license_key, $kept->license_key ); // The license itself stays.
		$this->assertSame( 7, $kept->product_id );

		$this->assertSame( array(), $this->data->orders[1]['billing'] );
		$this->assertNull( $this->data->orders[1]['user_id'] );
		$this->assertSame( 2999, $this->data->orders[1]['total_minor'] ); // Accounting figures stay.
		$this->assertArrayNotHasKey( 'email', $this->data->events[ $license->id ][0]['data'] );
		$this->assertSame( array( 1, 2 ), $this->data->events[ $license->id ][0]['data']['activation_limit'] );
	}

	public function test_erasing_leaves_other_people_alone_and_finds_nothing_the_second_time(): void {
		$this->licenses->issue_license( 7, 'ada@example.com', 5 );
		$other = $this->licenses->issue_license( 7, 'grace@example.com', 6 );

		$first  = $this->privacy->erase( 'ada@example.com', 5 );
		$second = $this->privacy->erase( 'ada@example.com', 5 );

		$this->assertTrue( $first['items_removed'] );
		$this->assertFalse( $second['items_removed'] );
		$this->assertSame( array(), $second['messages'] );
		$this->assertSame( 'grace@example.com', $this->repo->find( $other->id )->email );
		$this->assertSame( 6, $this->repo->find( $other->id )->user_id );
	}

	public function test_erasing_a_long_list_takes_several_rounds_and_finishes(): void {
		for ( $i = 0; $i < PrivacyService::PER_PAGE + 5; $i++ ) {
			$this->licenses->issue_license( 7, 'ada@example.com' );
		}

		$first  = $this->privacy->erase( 'ada@example.com', null );
		$second = $this->privacy->erase( 'ada@example.com', null );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame( array(), $this->privacy->export( 'ada@example.com', null, 1 )['data'] );
	}

	public function test_old_1x_records_are_erased_too(): void {
		$this->data->legacy['licenses'][] = array( 'id' => 3, 'user_id' => 5, 'product_id' => 7, 'license_key' => 'OLDKEY', 'email' => 'ada@example.com', 'valid_until' => '', 'created_at' => '' );
		$this->data->legacy['orders'][]   = array( 'id' => 4, 'user_id' => 5, 'order_number' => '#1', 'order_total' => '1', 'order_email' => 'ada@example.com', 'first_name' => 'Ada', 'billing_address' => '1 Main St', 'created_at' => '' );

		$result = $this->privacy->erase( 'ada@example.com', 5 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( 'erased-3@erased.invalid', $this->data->legacy['licenses'][0]['email'] );
		$this->assertSame( 0, $this->data->legacy['licenses'][0]['user_id'] );
		$this->assertSame( '', $this->data->legacy['orders'][0]['first_name'] );
		$this->assertSame( '', $this->data->legacy['orders'][0]['billing_address'] );
	}

	public function test_nothing_to_erase_is_reported_honestly(): void {
		$result = $this->privacy->erase( 'stranger@example.com', null );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_placeholders_can_never_be_a_real_address(): void {
		$this->assertSame( 'erased-12@erased.invalid', PrivacyService::placeholder( 12 ) );
		$this->assertTrue( PrivacyService::is_placeholder( 'erased-12@erased.invalid' ) );
		$this->assertFalse( PrivacyService::is_placeholder( 'erased-12@example.com' ) );
		$this->assertFalse( PrivacyService::is_placeholder( 'ada@example.com' ) );
	}

	// The SQL.

	public function test_the_person_search_uses_prepared_values(): void {
		$db   = new FakeWpdb();
		$repo = new WpdbPrivacyRepository( $db );

		$repo->licenses( "x' OR '1'='1", 5, 2, 25 );

		$sql = $db->last_query();
		$this->assertStringContainsString( "email = 'x\\' or \\'1\\'=\\'1' OR user_id = 5", $sql ); // The quotes cannot end the string.
		$this->assertStringContainsString( 'LIMIT 25 OFFSET 25', $sql );
	}

	public function test_order_ids_are_cast_to_integers_before_they_reach_the_sql(): void {
		$db      = new FakeWpdb();
		$db->col = array( '3', '4; DROP TABLE wp_users', '5' );
		$repo    = new WpdbPrivacyRepository( $db );

		$repo->anonymize_orders( array( 1, 2 ), null );

		$this->assertStringContainsString( 'IN (3,4,5)', end( $db->queries ) );
		$this->assertStringNotContainsString( 'DROP', end( $db->queries ) );
	}
}
