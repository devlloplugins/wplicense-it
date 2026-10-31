<?php
/**
 * Tests for exporting and importing licenses as CSV.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\DryRun\MemoryActivationRepository;
use Devllo\WPLicenseIt\DryRun\MemoryEventLog;
use Devllo\WPLicenseIt\DryRun\MemoryLicenseRepository;
use Devllo\WPLicenseIt\Licenses\EventLog;
use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\LicenseException;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Tests\Support\FixedClock;
use Devllo\WPLicenseIt\Transfer\LicenseCsv;
use Devllo\WPLicenseIt\Transfer\LicenseImport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Devllo\WPLicenseIt\Transfer\LicenseCsv
 * @covers \Devllo\WPLicenseIt\Transfer\LicenseImport
 * @covers \Devllo\WPLicenseIt\Licenses\LicenseService::import_license
 */
final class LicenseTransferTest extends TestCase {

	private function license( string $email = 'a@example.com', ?string $expires = '2027-01-31 12:00:00' ): License {
		$utc = new DateTimeZone( 'UTC' );

		return new License( 9, 7, 3, 12, 'KEY-ONE-1234', $email, 'active', 2, null === $expires ? null : new DateTimeImmutable( $expires, $utc ), new DateTimeImmutable( '2026-01-31 08:00:00', $utc ), new DateTimeImmutable( '2026-02-01 08:00:00', $utc ) );
	}

	private function importer( array $keys = array(), array $names = array( 'Great Plugin' => array( 7 ) ) ): LicenseImport {
		return new LicenseImport(
			static fn( int $id ): bool => in_array( $id, array( 7, 8 ), true ),
			static fn( string $name ): array => $names[ $name ] ?? array(),
			static fn( string $key ): bool => in_array( $key, $keys, true ),
			static fn( int $id ): bool => 3 === $id
		);
	}

	public function test_an_export_row_has_every_column_in_order(): void {
		$row = LicenseCsv::row( $this->license(), 'Great Plugin', 1, 'WC-12' );

		$this->assertCount( count( LicenseCsv::COLUMNS ), $row );
		$this->assertSame( array( 'KEY-ONE-1234', '7', 'Great Plugin', 'a@example.com', 'active', '2', '1', '2027-01-31 12:00:00', '2026-01-31 08:00:00', 'WC-12', '3' ), $row );
		$this->assertSame( '', LicenseCsv::row( $this->license( 'a@example.com', null ), 'P', 0, '' )[7], 'a lifetime license has no expiry' );
	}

	public function test_a_value_that_a_spreadsheet_would_run_is_written_as_text(): void {
		$row = LicenseCsv::row( $this->license( '=HYPERLINK("https://evil.example","x")@example.com' ), '+cmd', 0, '-1' );

		$this->assertSame( "'=HYPERLINK(\"https://evil.example\",\"x\")@example.com", $row[3] );
		$this->assertSame( "'+cmd", $row[2] );
		$this->assertSame( "'-1", $row[9] );
		$this->assertSame( "\"'=HYPERLINK(\"\"https://evil.example\"\",\"\"x\"\")@example.com\",plain\r\n", LicenseCsv::line( array( $row[3], 'plain' ) ), 'and quoted as CSV' );
	}

	public function test_an_export_reads_back_the_same(): void {
		$file   = LicenseCsv::line( LicenseCsv::COLUMNS ) . LicenseCsv::line( LicenseCsv::row( $this->license( '-x@example.com' ), 'Great, "Pro" Plugin', 1, 'WC-12' ) );
		$parsed = LicenseCsv::parse( "\xEF\xBB\xBF" . $file );

		$this->assertSame( LicenseCsv::COLUMNS, $parsed['columns'] );
		$this->assertSame( 2, $parsed['rows'][0]['line'] );
		$this->assertSame( '-x@example.com', $parsed['rows'][0]['values']['email'], 'the apostrophe added on export comes off' );
		$this->assertSame( 'Great, "Pro" Plugin', $parsed['rows'][0]['values']['product'] );
	}

	public function test_semicolons_spaces_in_headers_and_blank_lines_are_fine(): void {
		$parsed = LicenseCsv::parse( "License Key;Email;Product ID\n\nABCD-1234;b@example.com;7\n" );

		$this->assertSame( array( 'license_key', 'email', 'product_id' ), $parsed['columns'] );
		$this->assertCount( 1, $parsed['rows'] );
		$this->assertSame( 3, $parsed['rows'][0]['line'] );
		$this->assertSame( 'ABCD-1234', $parsed['rows'][0]['values']['license_key'] );
	}

	public function test_line_numbers_are_the_lines_a_text_editor_shows(): void {
		$parsed = LicenseCsv::parse( "a,b\n\"two\nlines\",1\n\nz,2\n" );

		$this->assertSame( array( 2, 5 ), array_column( $parsed['rows'], 'line' ), 'a value over two lines, then a blank line' );
		$this->assertSame( "two\nlines", $parsed['rows'][0]['values']['a'] );
	}

	public function test_dates(): void {
		$this->assertNull( LicenseCsv::date( '' ) );
		$this->assertSame( '2027-03-01 23:59:59', LicenseCsv::date( '2027-03-01' )->format( 'Y-m-d H:i:s' ), 'a day means the end of that day' );
		$this->assertSame( '2027-03-01 10:30:00', LicenseCsv::date( '2027-03-01 10:30' )->format( 'Y-m-d H:i:s' ) );
		$this->assertFalse( LicenseCsv::date( '2027-02-31' ), 'a day that does not exist' );
		$this->assertFalse( LicenseCsv::date( '01/03/2027' ) );
	}

	public function test_the_plan_imports_good_rows_skips_existing_keys_and_explains_mistakes(): void {
		$csv = "license_key,email,product_id,product,status,activation_limit,expires_at,created_at,user_id\n"
			. "NEW-KEY-0001,New@Example.com,7,,expired,0,2026-05-01,2025-05-01 09:00:00,3\n"
			. "OLD-KEY-0001,old@example.com,7,,,,,,\n"
			. "NEW-KEY-0002,c@example.com,,Great Plugin,,,,,\n"
			. "NEW-KEY-0001,d@example.com,7,,,,,,\n"
			. "abc,e@example.com,7,,,,,,\n"
			. "NEW-KEY-0003,not-an-email,7,,,,,,\n"
			. "NEW-KEY-0004,f@example.com,99,,,,,,\n"
			. "NEW-KEY-0005,g@example.com,,Unknown,,,,,\n"
			. "NEW-KEY-0006,h@example.com,7,,paused,,,,\n"
			. "NEW-KEY-0007,i@example.com,7,,,-1,,,\n"
			. "NEW-KEY-0008,j@example.com,7,,,,2027-13-01,,\n"
			. "NEW-KEY-0009,k@example.com,7,,,,,,42\n"
			. "NEW-KEY-0010,l@example.com,7,,,100000,,,\n";

		$plan = $this->importer( array( 'OLD-KEY-0001' ) )->check( LicenseCsv::parse( $csv ) );

		$this->assertSame( '', $plan['fatal'] );
		$this->assertSame( array( 2, 4, 14 ), array_column( $plan['import'], 'line' ) );
		$this->assertSame(
			array(
				'line'             => 2,
				'license_key'      => 'NEW-KEY-0001',
				'product_id'       => 7,
				'email'            => 'new@example.com',
				'status'           => 'expired',
				'activation_limit' => 0,
				'expires_at'       => '2026-05-01 23:59:59',
				'created_at'       => '2025-05-01 09:00:00',
				'user_id'          => 3,
			),
			$plan['import'][0]
		);
		$this->assertSame( 7, $plan['import'][1]['product_id'], 'a product can be named instead of numbered' );
		$this->assertSame( 'active', $plan['import'][1]['status'] );
		$this->assertSame( 1, $plan['import'][1]['activation_limit'] );
		$this->assertNull( $plan['import'][1]['expires_at'], 'no expiry means never' );
		$this->assertSame( array( 3 ), array_column( $plan['skipped'], 'line' ), 'an existing key is skipped, not changed' );

		$errors = array_column( $plan['errors'], 'reason', 'line' );
		$this->assertSame( array( 5, 6, 7, 8, 9, 10, 11, 12, 13 ), array_keys( $errors ) );
		$this->assertStringContainsString( 'line 2', $errors[5] );
		$this->assertStringContainsString( '4 to 64', $errors[6] );
		$this->assertStringContainsString( 'email', $errors[7] );
		$this->assertStringContainsString( 'No license product', $errors[8] );
		$this->assertStringContainsString( 'No license product', $errors[9] );
		$this->assertStringContainsString( 'status', $errors[10] );
		$this->assertStringContainsString( 'activation limit', $errors[11] );
		$this->assertStringContainsString( 'date', $errors[12] );
		$this->assertStringContainsString( 'user_id', $errors[13] );
	}

	public function test_a_name_shared_by_two_products_is_refused(): void {
		$plan = $this->importer( array(), array( 'Twin' => array( 7, 8 ) ) )->check( LicenseCsv::parse( "license_key,email,product\nTWIN-0001,a@example.com,Twin\n" ) );

		$this->assertSame( array(), $plan['import'] );
		$this->assertStringContainsString( 'Several license products', $plan['errors'][0]['reason'] );
	}

	public function test_a_file_without_the_required_columns_or_rows_is_refused_as_a_whole(): void {
		$this->assertStringContainsString( 'license_key, product_id', $this->importer()->check( LicenseCsv::parse( "email\na@example.com\n" ) )['fatal'] );
		$this->assertStringContainsString( 'only a header', $this->importer()->check( LicenseCsv::parse( "license_key,email,product_id\n" ) )['fatal'] );

		$many = "license_key,email,product_id\n" . str_repeat( "KEY-AAAA,a@example.com,7\n", LicenseImport::MAX_ROWS + 1 );
		$this->assertStringContainsString( 'more than', $this->importer()->check( LicenseCsv::parse( $many ) )['fatal'] );
	}

	public function test_importing_keeps_the_key_status_and_dates_and_records_it(): void {
		$events  = new MemoryEventLog();
		$service = new LicenseService( new MemoryLicenseRepository(), new MemoryActivationRepository(), $events, new KeyGenerator(), new FixedClock( '2026-03-01 00:00:00' ) );
		$utc     = new DateTimeZone( 'UTC' );

		$license = $service->import_license( 7, ' New@Example.com ', 'IMPORTED-KEY-1', 'revoked', 3, new DateTimeImmutable( '2027-01-01', $utc ), 5, new DateTimeImmutable( '2024-06-01', $utc ) );

		$this->assertSame( 'IMPORTED-KEY-1', $license->license_key );
		$this->assertSame( 'revoked', $license->status );
		$this->assertSame( 'new@example.com', $license->email );
		$this->assertSame( '2024-06-01', $license->created_at->format( 'Y-m-d' ) );
		$this->assertSame( 5, $license->user_id );
		$this->assertSame( EventLog::IMPORTED, $events->for_license( $license->id )[0]['type'] );

		$this->expectException( LicenseException::class );
		$service->import_license( 7, 'b@example.com', 'IMPORTED-KEY-1', 'active', 1, null );
	}
}
