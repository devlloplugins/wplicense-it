<?php
/**
 * Tests for checking the Plans table input.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Plans\Plan;
use Devllo\WPLicenseIt\Plans\PlanInput;
use Devllo\WPLicenseIt\WooCommerce\Money;
use Devllo\WPLicenseIt\WooCommerce\PeriodParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Plans\PlanInput
 * @covers \Devllo\WPLicenseIt\WooCommerce\Money::from_minor
 */
final class PlanInputTest extends TestCase {

	private function row( string $name = 'One site', string $price = '49', string $sites = '1', string $period = 'P1Y', string $id = '' ): array {
		return array(
			'id'     => $id,
			'name'   => $name,
			'price'  => $price,
			'sites'  => $sites,
			'period' => $period,
		);
	}

	private function ids(): callable {
		$n = 0;

		return static function () use ( &$n ): string {
			return sprintf( '%08x', ++$n );
		};
	}

	public function test_a_valid_row_becomes_a_plan(): void {
		$result = PlanInput::parse( array( $this->row() ), array(), $this->ids() );

		$this->assertCount( 0, $result['errors'] );
		$this->assertCount( 1, $result['plans'] );
		$plan = $result['plans'][0];
		$this->assertSame( '00000001', $plan->id );
		$this->assertSame( 'One site', $plan->name );
		$this->assertSame( '49', $plan->price );
		$this->assertSame( 1, $plan->sites );
		$this->assertSame( 'P1Y', $plan->period );
		$this->assertSame( 0, $plan->woo_product_id );
	}

	public function test_the_spare_empty_row_is_ignored(): void {
		$result = PlanInput::parse( array( $this->row(), $this->row( '', '', '', '' ) ), array(), $this->ids() );

		$this->assertCount( 0, $result['errors'] );
		$this->assertCount( 1, $result['plans'] );
	}

	/**
	 * @dataProvider good_prices
	 */
	public function test_prices_that_are_accepted( string $input, string $stored ): void {
		$result = PlanInput::parse( array( $this->row( 'A', $input ) ), array(), $this->ids() );

		$this->assertCount( 0, $result['errors'] );
		$this->assertSame( $stored, $result['plans'][0]->price );
	}

	/**
	 * @return array<string, array{string,string}>
	 */
	public function good_prices(): array {
		return array(
			'whole'          => array( '49', '49' ),
			'decimals'       => array( '49.50', '49.50' ),
			'comma decimals' => array( '49,50', '49.50' ),
			'free'           => array( '0', '0' ),
			'padded'         => array( '  19.99 ', '19.99' ),
		);
	}

	/**
	 * @dataProvider bad_prices
	 */
	public function test_prices_that_are_refused( string $input ): void {
		$result = PlanInput::parse( array( $this->row( 'A', $input ) ), array(), $this->ids() );

		$this->assertCount( 0, $result['plans'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'price', $result['errors'][0] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function bad_prices(): array {
		return array(
			'empty'     => array( '' ),
			'words'     => array( 'free' ),
			'negative'  => array( '-5' ),
			'currency'  => array( '$49' ),
			'thousands' => array( '1,000.00' ),
			'too many'  => array( '49.9999' ),
			'huge'      => array( '12345678901' ),
			'exponent'  => array( '1e3' ),
		);
	}

	/**
	 * @dataProvider bad_sites
	 */
	public function test_sites_that_are_refused( string $input ): void {
		$result = PlanInput::parse( array( $this->row( 'A', '10', $input ) ), array(), $this->ids() );

		$this->assertCount( 0, $result['plans'] );
		$this->assertStringContainsString( 'sites', $result['errors'][0] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function bad_sites(): array {
		return array(
			'empty'    => array( '' ),
			'negative' => array( '-1' ),
			'decimal'  => array( '1.5' ),
			'words'    => array( 'many' ),
			'too big'  => array( '100001' ),
		);
	}

	public function test_zero_sites_means_unlimited_and_is_accepted(): void {
		$result = PlanInput::parse( array( $this->row( 'Unlimited', '199', '0', 'lifetime' ) ), array(), $this->ids() );

		$this->assertCount( 0, $result['errors'] );
		$this->assertSame( 0, $result['plans'][0]->sites );
	}

	public function test_an_unknown_period_is_refused(): void {
		$result = PlanInput::parse( array( $this->row( 'A', '1', '1', 'P5Y' ) ), array(), $this->ids() );

		$this->assertCount( 0, $result['plans'] );
		$this->assertStringContainsString( 'lasts', $result['errors'][0] );
	}

	public function test_every_period_choice_is_understood_by_the_license_issuer(): void {
		foreach ( PlanInput::PERIODS as $period ) {
			PeriodParser::parse( $period );
		}
		$this->assertTrue( true );
	}

	public function test_a_name_is_required_and_tags_are_stripped(): void {
		$empty = PlanInput::parse( array( $this->row( '', '10' ) ), array(), $this->ids() );
		$this->assertStringContainsString( 'name', $empty['errors'][0] );

		$tags = PlanInput::parse( array( $this->row( '<b>Pro</b>  plan', '10' ) ), array(), $this->ids() );
		$this->assertSame( 'Pro plan', $tags['plans'][0]->name );
	}

	public function test_a_long_name_is_refused(): void {
		$result = PlanInput::parse( array( $this->row( str_repeat( 'a', 81 ) ) ), array(), $this->ids() );

		$this->assertCount( 0, $result['plans'] );
		$this->assertStringContainsString( 'too long', $result['errors'][0] );
	}

	public function test_names_must_be_different(): void {
		$result = PlanInput::parse( array( $this->row( 'Pro' ), $this->row( 'pro', '99' ) ), array(), $this->ids() );

		$this->assertCount( 1, $result['plans'] );
		$this->assertStringContainsString( 'different', $result['errors'][0] );
	}

	public function test_all_mistakes_are_reported_at_once(): void {
		$result = PlanInput::parse( array( $this->row( '', 'x', 'y', 'z' ) ), array(), $this->ids() );

		$this->assertCount( 4, $result['errors'] );
	}

	public function test_there_is_a_limit_on_the_number_of_plans(): void {
		$rows = array();
		for ( $i = 1; $i <= 21; $i++ ) {
			$rows[] = $this->row( 'Plan name ' . $i );
		}

		$result = PlanInput::parse( $rows, array(), $this->ids() );

		$this->assertCount( 20, $result['plans'] );
		$this->assertCount( 1, $result['errors'] );
	}

	public function test_a_saved_plan_keeps_its_woocommerce_link(): void {
		$saved  = new Plan( 'aaaaaaaa', 'Old name', '49', 1, 'P1Y', 12, 4900, 'USD', 3900, '2026-10-11 12:00:00', 'print-1' );
		$result = PlanInput::parse( array( $this->row( 'New name', '59', '2', 'P2Y', 'aaaaaaaa' ) ), array( $saved ), $this->ids() );
		$plan   = $result['plans'][0];

		$this->assertSame( 'aaaaaaaa', $plan->id );
		$this->assertSame( 'New name', $plan->name );
		$this->assertSame( '59', $plan->price );
		$this->assertSame( 12, $plan->woo_product_id );
		$this->assertSame( 4900, $plan->synced_minor );
		$this->assertSame( 3900, $plan->conflict_minor );
		$this->assertSame( '2026-10-11 12:00:00', $plan->tested_at );
		$this->assertSame( 'print-1', $plan->tested_hash );
	}

	public function test_an_id_that_is_not_saved_is_not_trusted(): void {
		$saved  = new Plan( 'aaaaaaaa', 'Real', '49', 1, 'P1Y', 12, 4900, 'USD' );
		$result = PlanInput::parse( array( $this->row( 'Fake', '1', '1', 'P1Y', 'bbbbbbbb' ) ), array( $saved ), $this->ids() );

		$this->assertSame( '00000001', $result['plans'][0]->id );
		$this->assertSame( 0, $result['plans'][0]->woo_product_id );
	}

	public function test_an_id_cannot_be_used_twice(): void {
		$saved  = new Plan( 'aaaaaaaa', 'Real', '49', 1, 'P1Y', 12, 4900, 'USD' );
		$result = PlanInput::parse( array( $this->row( 'One', '1', '1', 'P1Y', 'aaaaaaaa' ), $this->row( 'Two', '2', '1', 'P1Y', 'aaaaaaaa' ) ), array( $saved ), $this->ids() );

		$this->assertSame( 12, $result['plans'][0]->woo_product_id );
		$this->assertSame( 0, $result['plans'][1]->woo_product_id );
		$this->assertTrue( $result['plans'][0]->id !== $result['plans'][1]->id );
	}

	public function test_a_malformed_id_is_ignored(): void {
		$result = PlanInput::parse( array( $this->row( 'A', '1', '1', 'P1Y', '../../x' ) ), array(), $this->ids() );

		$this->assertSame( '00000001', $result['plans'][0]->id );
	}

	public function test_non_array_rows_and_values_are_ignored(): void {
		$result = PlanInput::parse(
			array(
				'junk',
				array(
					'name'   => array( 'x' ),
					'price'  => '1',
					'sites'  => '1',
					'period' => 'P1Y',
				),
			),
			array(),
			$this->ids()
		);

		$this->assertCount( 0, $result['plans'] );
		$this->assertCount( 1, $result['errors'] );
	}

	public function test_removed_plans_are_the_saved_ones_missing_from_the_new_list(): void {
		$a = new Plan( 'aaaaaaaa', 'A', '1', 1, 'P1Y' );
		$b = new Plan( 'bbbbbbbb', 'B', '1', 1, 'P1Y' );

		$removed = PlanInput::removed( array( $a, $b ), array( $b ) );

		$this->assertCount( 1, $removed );
		$this->assertSame( 'aaaaaaaa', $removed[0]->id );
	}

	/**
	 * @dataProvider money_cases
	 */
	public function test_minor_units_back_to_text( int $minor, string $currency, string $expected ): void {
		$this->assertSame( $expected, Money::from_minor( $minor, $currency ) );
		$this->assertSame( $minor, Money::to_minor( $expected, $currency ) );
	}

	/**
	 * @return array<string, array{int,string,string}>
	 */
	public function money_cases(): array {
		return array(
			'dollars'        => array( 4900, 'USD', '49.00' ),
			'cents'          => array( 5, 'USD', '0.05' ),
			'odd cents'      => array( 4950, 'EUR', '49.50' ),
			'yen'            => array( 49, 'JPY', '49' ),
			'dinar'          => array( 49500, 'KWD', '49.500' ),
			'dinar fraction' => array( 7, 'KWD', '0.007' ),
		);
	}
}
