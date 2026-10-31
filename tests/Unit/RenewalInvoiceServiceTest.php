<?php
/**
 * Tests for renewal invoices.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\DryRun\MemoryEventLog;
use Devllo\WPLicenseIt\DryRun\MemoryLicenseRepository;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\Status;
use Devllo\WPLicenseIt\Renewals\RenewalInvoiceService;
use Devllo\WPLicenseIt\Renewals\RenewalOrderCreator;
use Devllo\WPLicenseIt\Renewals\RenewalSettings;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Renewals\RenewalInvoiceService
 * @covers \Devllo\WPLicenseIt\Renewals\RenewalSettings
 */
final class RenewalInvoiceServiceTest extends TestCase {

	private FixedClock $clock;
	private MemoryLicenseRepository $licenses;
	private MemoryEventLog $events;
	private object $creator;
	private RenewalInvoiceService $service;
	private int $counter = 0;

	protected function setUp(): void {
		$this->clock    = new FixedClock( '2026-06-01 12:00:00' );
		$this->licenses = new MemoryLicenseRepository();
		$this->events   = new MemoryEventLog();
		$this->creator  = new class() implements RenewalOrderCreator {
			/** @var int[] */
			public array $made = array();
			/** @var int[] Product IDs that cannot be invoiced. */
			public array $unsellable = array();
			public bool $works       = true;
			private int $next_order  = 500;

			public function sells( int $license_product_id ): bool {
				return ! in_array( $license_product_id, $this->unsellable, true );
			}

			public function create( License $license ): ?int {
				if ( ! $this->works ) {
					return null;
				}

				$this->made[] = $license->id;

				return $this->next_order++;
			}
		};
		$this->service  = new RenewalInvoiceService( $this->licenses, $this->events, $this->creator, $this->clock );
	}

	/**
	 * Adds a license that expires in a number of days from the fixed "now".
	 *
	 * @param string|null $in      Relative time such as "+10 days", or null for a license that never expires.
	 * @param string      $status  Status.
	 * @param string      $email   Email.
	 * @param int         $product Product ID.
	 */
	private function license( ?string $in, string $status = Status::ACTIVE, string $email = 'buyer@example.com', int $product = 1 ): License {
		$now = ( $this->clock )();

		++$this->counter;

		return $this->licenses->insert( new License( 0, $product, null, null, 'KEY-' . $this->counter, $email, $status, 1, null === $in ? null : $now->modify( $in ), $now, $now ) );
	}

	public function test_a_license_inside_the_window_gets_one_invoice(): void {
		$license = $this->license( '+10 days' );

		$this->assertSame( 1, $this->service->create_due( 14 ) );
		$this->assertSame( array( $license->id ), $this->creator->made );

		$events = $this->events->of_type( $license->id, EventLog::INVOICED );
		$this->assertCount( 1, $events );
		$this->assertSame( 500, $events[0]['data']['order_id'] );
	}

	public function test_nothing_is_invoiced_twice_for_one_expiry_date(): void {
		$this->license( '+10 days' );

		$this->service->create_due( 14 );
		$this->assertSame( 0, $this->service->create_due( 14 ) );
		$this->assertCount( 1, $this->creator->made );
	}

	public function test_a_renewal_starts_a_new_cycle(): void {
		$license = $this->license( '+10 days' );
		$this->service->create_due( 14 );

		$this->licenses->update( new License( $license->id, $license->product_id, null, null, $license->license_key, $license->email, Status::ACTIVE, 1, ( $this->clock )()->modify( '+375 days' ), $license->created_at, $license->updated_at ) );
		$this->assertSame( 0, $this->service->create_due( 14 ), 'a year away: not due' );

		$this->clock->advance( '+365 days' );
		$this->assertSame( 1, $this->service->create_due( 14 ) );
		$this->assertCount( 2, $this->creator->made );
	}

	public function test_licenses_that_should_not_be_invoiced_are_skipped(): void {
		$this->license( null );                                   // Never expires.
		$this->license( '+30 days' );                              // Not within 14 days yet.
		$this->license( '-1 day' );                                // Already past its expiry.
		$this->license( '+5 days', Status::REVOKED );
		$this->license( '+5 days', Status::REFUNDED );
		$this->license( '+5 days', Status::EXPIRED );
		$this->license( '+5 days', Status::ACTIVE, 'erased-9@erased.invalid' );
		$this->license( '+5 days', Status::ACTIVE, '' );
		$this->license( '+5 days', Status::ACTIVE, 'buyer@example.com', 77 ); // A product with nothing to sell.
		$this->creator->unsellable = array( 77 );

		$this->assertSame( 0, $this->service->create_due( 14 ) );
		$this->assertSame( array(), $this->creator->made );
	}

	public function test_an_invoice_that_could_not_be_made_is_tried_again_and_not_recorded(): void {
		$this->license( '+5 days' );
		$this->creator->works = false;

		$this->assertSame( 0, $this->service->create_due( 14 ) );
		$this->assertSame( array(), $this->events->types() );

		$this->creator->works = true;
		$this->assertSame( 1, $this->service->create_due( 14 ) );
	}

	public function test_a_run_stops_at_the_limit_and_the_rest_is_made_next_time(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->license( '+5 days' );
		}

		$this->assertSame( 3, $this->service->create_due( 14, 3 ) );
		$this->assertSame( 2, $this->service->create_due( 14, 3 ) );
		$this->assertSame( 0, $this->service->create_due( 14, 3 ) );
	}

	public function test_every_license_is_reached_in_small_batches(): void {
		foreach ( array( '+5 days', '+2 days', '+2 days', '+9 days', '+2 days', '+5 days', '+1 day' ) as $in ) {
			$this->license( $in );
		}

		$this->assertSame( 7, $this->service->create_due( 14, 100, 2 ) );
		$this->assertCount( 7, array_unique( $this->creator->made ) );
	}

	public function test_a_product_is_asked_about_once_per_run(): void {
		$asked = new class() implements RenewalOrderCreator {
			public int $asked = 0;

			public function sells( int $license_product_id ): bool {
				++$this->asked;

				return false;
			}

			public function create( License $license ): ?int {
				return null;
			}
		};

		for ( $i = 0; $i < 4; $i++ ) {
			$this->license( '+5 days' );
		}

		( new RenewalInvoiceService( $this->licenses, $this->events, $asked, $this->clock ) )->create_due( 14 );
		$this->assertSame( 1, $asked->asked );
	}

	public function test_a_nonsense_number_of_days_makes_nothing(): void {
		$this->license( '+5 days' );

		$this->assertSame( 0, $this->service->create_due( 0 ) );
		$this->assertSame( 0, $this->service->create_due( -3 ) );
	}

	/**
	 * @dataProvider typed_days
	 *
	 * @param mixed $typed    What was typed.
	 * @param int   $expected What is used.
	 */
	public function test_the_days_setting_is_kept_in_range( $typed, int $expected ): void {
		$this->assertSame( $expected, RenewalSettings::clamp_days( $typed ) );
	}

	/**
	 * @return array<string, array{mixed, int}>
	 */
	public function typed_days(): array {
		return array(
			'normal'   => array( '21', 21 ),
			'zero'     => array( 0, 1 ),
			'negative' => array( -5, 1 ),
			'huge'     => array( 5000, 90 ),
			'text'     => array( 'soon', 14 ),
			'array'    => array( array( 3 ), 14 ),
		);
	}
}
