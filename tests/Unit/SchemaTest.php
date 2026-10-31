<?php
/**
 * Tests for the schema SQL.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use Devllo\WPLicenseIt\Database\Dates;
use Devllo\WPLicenseIt\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Database\Schema
 * @covers \Devllo\WPLicenseIt\Database\Dates
 */
final class SchemaTest extends TestCase {

	public function test_every_table_has_a_statement_using_the_prefix(): void {
		$sql = Schema::sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertSame( Schema::table_names(), array_keys( $sql ) );

		foreach ( Schema::table_names() as $name ) {
			$this->assertStringStartsWith( 'CREATE TABLE wp_wplit_' . $name . ' (', $sql[ $name ] );
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql[ $name ] ); // dbDelta needs two spaces.
			$this->assertStringEndsWith( 'DEFAULT CHARACTER SET utf8mb4;', $sql[ $name ] );
		}
	}

	public function test_new_tables_do_not_collide_with_the_1x_tables(): void {
		$legacy = array( 'wp_wplit_product_licenses', 'wp_wplit_orders' );

		foreach ( Schema::table_names() as $name ) {
			$this->assertNotContains( Schema::table( 'wp_', $name ), $legacy );
		}
	}

	public function test_no_zero_dates_and_no_small_ids(): void {
		foreach ( Schema::sql( 'wp_', '' ) as $statement ) {
			$this->assertStringNotContainsString( '0000-00-00', $statement );
			$this->assertStringNotContainsString( 'mediumint', $statement );
		}
	}

	public function test_dates_round_trip_in_utc(): void {
		$date = Dates::from_db( '2026-03-04 05:06:07' );

		$this->assertSame( '2026-03-04 05:06:07', Dates::to_db( $date ) );
		$this->assertSame( 'UTC', $date->getTimezone()->getName() );
	}

	public function test_zero_and_empty_dates_become_null(): void {
		$this->assertNull( Dates::from_db( null ) );
		$this->assertNull( Dates::from_db( '' ) );
		$this->assertNull( Dates::from_db( '0000-00-00 00:00:00' ) );
		$this->assertNull( Dates::to_db( null ) );
	}
}
