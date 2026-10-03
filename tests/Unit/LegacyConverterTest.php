<?php
/**
 * Tests for the 1.x to 2.0 conversion rules.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Migration\InvalidLegacyRow;
use Devllo\WPLicenseIt\Migration\LegacyConverter;
use Devllo\WPLicenseIt\Migration\WpProductMetaMigrator;
use Devllo\WPLicenseIt\Tests\Support\ArrayLegacySource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Migration\LegacyConverter
 * @covers \Devllo\WPLicenseIt\Migration\WpProductMetaMigrator
 */
final class LegacyConverterTest extends TestCase {

	private LegacyConverter $converter;
	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->converter = new LegacyConverter( new DateTimeZone( 'America/New_York' ) );
		$this->now       = new DateTimeImmutable( '2026-06-01 00:00:00', new DateTimeZone( 'UTC' ) );
	}

	private function license_row( array $overrides = array() ): array {
		$source = new ArrayLegacySource();
		$source->add_license( 1, $overrides );

		return $source->licenses[1];
	}

	private function order_row( array $overrides = array() ): array {
		$source = new ArrayLegacySource();
		$source->add_order( 1, $overrides );

		return $source->orders[1];
	}

	// Dates.

	public function test_site_local_dates_become_utc(): void {
		$this->assertSame( '2026-07-01 16:00:00', $this->converter->to_utc( '2026-07-01 12:00:00' )->format( 'Y-m-d H:i:s' ) ); // Summer: UTC-4.
		$this->assertSame( '2026-01-01 17:00:00', $this->converter->to_utc( '2026-01-01 12:00:00' )->format( 'Y-m-d H:i:s' ) ); // Winter: UTC-5.
	}

	public function test_zero_empty_and_garbage_dates_become_null(): void {
		foreach ( array( '0000-00-00 00:00:00', '', '   ', null, 'not a date', 123 ) as $value ) {
			$this->assertNull( $this->converter->to_utc( $value ) );
		}
	}

	// Licenses.

	public function test_an_active_lifetime_license(): void {
		$converted = $this->converter->license( $this->license_row(), $this->now );
		$license   = $converted['license'];

		$this->assertSame( 0, $license->id );
		$this->assertSame( 7, $license->product_id );
		$this->assertSame( 5, $license->user_id );
		$this->assertSame( 'KEY1', $license->license_key );
		$this->assertSame( 'buyer1@example.com', $license->email );
		$this->assertSame( Status::ACTIVE, $license->status );
		$this->assertNull( $license->expires_at );
		$this->assertSame( array(), $converted['notes'] );
	}

	public function test_existing_customers_get_unlimited_activations(): void {
		$this->assertSame( 0, $this->converter->license( $this->license_row(), $this->now )['license']->activation_limit );
	}

	public function test_expiry_converts_and_decides_the_status(): void {
		$future = $this->converter->license( $this->license_row( array( 'valid_until' => '2027-01-01 00:00:00' ) ), $this->now )['license'];
		$past   = $this->converter->license( $this->license_row( array( 'valid_until' => '2026-01-01 00:00:00' ) ), $this->now )['license'];

		$this->assertSame( Status::ACTIVE, $future->status );
		$this->assertSame( '2027-01-01 05:00:00', $future->expires_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( Status::EXPIRED, $past->status );
	}

	public function test_unknown_statuses_are_revoked_with_a_note(): void {
		$converted = $this->converter->license( $this->license_row( array( 'license_status' => 'banned' ) ), $this->now );

		$this->assertSame( Status::REVOKED, $converted['license']->status );
		$this->assertCount( 1, $converted['notes'] );
	}

	public function test_guest_users_and_missing_dates(): void {
		$license = $this->converter->license( $this->license_row( array( 'user_id' => '0', 'created_at' => '0000-00-00 00:00:00', 'updated_at' => '0000-00-00 00:00:00' ) ), $this->now )['license'];

		$this->assertNull( $license->user_id );
		$this->assertSame( $this->now, $license->created_at );
		$this->assertSame( $this->now, $license->updated_at );
	}

	public function test_unusable_license_rows_are_rejected(): void {
		foreach (
			array(
				array( 'license_key' => '' ),
				array( 'license_key' => str_repeat( 'k', 65 ) ),
				array( 'product_id' => '0' ),
				array( 'email' => '  ' ),
			) as $overrides
		) {
			try {
				$this->converter->license( $this->license_row( $overrides ), $this->now );
				$this->fail( 'Expected InvalidLegacyRow.' );
			} catch ( InvalidLegacyRow $e ) {
				$this->assertStringContainsString( 'License 1', $e->getMessage() );
			}
		}
	}

	// Orders.

	public function test_an_order_is_converted_to_minor_units_and_usd(): void {
		$order = $this->converter->order( $this->order_row(), $this->now, true );

		$this->assertSame( 2999, $order->total_minor );
		$this->assertSame( 'USD', $order->currency );
		$this->assertSame( 'legacy_stripe', $order->source );
		$this->assertSame( 'completed', $order->status );
		$this->assertSame( '#20250301-AB1', $order->order_number );
		$this->assertSame( 5, $order->user_id );
		$this->assertSame( '2025-03-01 15:00:05', $order->created_at->format( 'Y-m-d H:i:s' ) );
	}

	public function test_free_orders_and_odd_prices(): void {
		$this->assertSame( 'free', $this->converter->order( $this->order_row( array( 'order_total' => '0' ) ), $this->now, false )->source );
		$this->assertSame( 0, $this->converter->to_minor_units( '' ) );
		$this->assertSame( 0, $this->converter->to_minor_units( 'free' ) );
		$this->assertSame( 2999, $this->converter->to_minor_units( '$29.99' ) );
		$this->assertSame( 129900, $this->converter->to_minor_units( '1,299' ) );
		$this->assertSame( 1999, $this->converter->to_minor_units( 19.99 ) );
		$this->assertSame( 0, $this->converter->to_minor_units( '-5' ) );
	}

	public function test_billing_details_are_kept_only_when_asked(): void {
		$kept    = $this->converter->order( $this->order_row(), $this->now, true );
		$dropped = $this->converter->order( $this->order_row(), $this->now, false );

		$billing = json_decode( (string) $kept->legacy_billing, true );
		$this->assertSame( 'Ada', $billing['first_name'] );
		$this->assertSame( '1 Main St', $billing['billing_address'] );
		$this->assertSame( '555', $billing['billing_phone'] );
		$this->assertNull( $dropped->legacy_billing );
	}

	public function test_missing_order_numbers_get_a_stable_one_and_bad_orders_are_rejected(): void {
		$this->assertSame( 'LEGACY-1', $this->converter->order( $this->order_row( array( 'order_number' => '' ) ), $this->now, false )->order_number );

		$this->expectException( InvalidLegacyRow::class );
		$this->converter->order( $this->order_row( array( 'product_id' => '0' ) ), $this->now, false );
	}

	// Product meta.

	public function test_periods(): void {
		$this->assertSame( 'P1M', WpProductMetaMigrator::period( 'yes', '1-month' ) );
		$this->assertSame( 'P1Y', WpProductMetaMigrator::period( 'yes', '1-year' ) );
		$this->assertSame( 'lifetime', WpProductMetaMigrator::period( '', '1-year' ) );
		$this->assertSame( 'lifetime', WpProductMetaMigrator::period( 'yes', '' ) );
		$this->assertSame( 'lifetime', WpProductMetaMigrator::period( 'yes', 'weird' ) );
	}
}
