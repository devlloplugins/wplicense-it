<?php
/**
 * Licenses as CSV: writing an export, reading a file to import.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Transfer;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\License;

/**
 * The CSV format, without WordPress. An export can be imported again: the import reads the columns it knows by their
 * header name, in any order, and ignores the others (product name, sites in use, order).
 *
 * Cells that a spreadsheet would run as a formula (starting with = + - @ or a tab or carriage return) are written with a
 * leading apostrophe, so opening an export cannot run anything a customer typed into an email address.
 */
final class LicenseCsv {

	public const COLUMNS = array( 'license_key', 'product_id', 'product', 'email', 'status', 'activation_limit', 'sites_in_use', 'expires_at', 'created_at', 'order', 'user_id' );

	/**
	 * One license as an export row, in the order of COLUMNS.
	 *
	 * @param License $license License.
	 * @param string  $product Product name.
	 * @param int     $sites   Sites in use.
	 * @param string  $order   Order number, or empty.
	 * @return string[]
	 */
	public static function row( License $license, string $product, int $sites, string $order ): array {
		return array_map(
			array( self::class, 'cell' ),
			array(
				$license->license_key,
				(string) $license->product_id,
				$product,
				$license->email,
				$license->status,
				(string) $license->activation_limit,
				(string) $sites,
				null === $license->expires_at ? '' : $license->expires_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				$license->created_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				$order,
				null === $license->user_id ? '' : (string) $license->user_id,
			)
		);
	}

	/**
	 * A value that a spreadsheet will show as text, not run.
	 *
	 * @param string $value Value.
	 */
	public static function cell( string $value ): string {
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * One CSV line (with a line break), quoted where needed.
	 *
	 * @param string[] $fields Fields.
	 */
	public static function line( array $fields ): string {
		$quoted = array();

		foreach ( $fields as $field ) {
			$quoted[] = 1 === preg_match( '/[",\r\n]/', $field ) ? '"' . str_replace( '"', '""', $field ) . '"' : $field;
		}

		return implode( ',', $quoted ) . "\r\n";
	}

	/**
	 * Reads a CSV file: the header row names the columns (case and spaces do not matter, a byte order mark is ignored),
	 * comma or semicolon separated. Empty lines are skipped.
	 *
	 * @param string $text File contents.
	 * @return array{columns: string[], rows: list<array{line: int, values: array<string, string>}>}
	 */
	public static function parse( string $text ): array {
		$text  = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$first = strtok( $text, "\n" );
		$sep   = false !== $first && substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';

		// A memory stream, so that quoted values with line breaks are read as one value. Not a file on disk.
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A memory stream.
		if ( false === $handle ) {
			return array(
				'columns' => array(),
				'rows'    => array(),
			);
		}
		fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A memory stream.
		rewind( $handle );

		$columns = array();
		$rows    = array();
		$next    = 1;

		for ( $at = 0, $fields = fgetcsv( $handle, 0, $sep, '"', '' ); false !== $fields; $fields = fgetcsv( $handle, 0, $sep, '"', '' ) ) {
			// The line the record starts on, as a text editor numbers it (a quoted value can span lines).
			$line  = $next;
			$end   = (int) ftell( $handle );
			$next += substr_count( $text, "\n", $at, $end - $at );
			$at    = $end;

			if ( array( null ) === $fields || array( '' ) === array_map( 'trim', array_map( 'strval', $fields ) ) ) {
				continue;
			}

			if ( array() === $columns ) {
				$columns = array_map( static fn( $name ): string => strtolower( trim( str_replace( ' ', '_', (string) $name ) ) ), $fields );
				continue;
			}

			$values = array();
			foreach ( $columns as $i => $name ) {
				$values[ $name ] = self::uncell( trim( (string) ( $fields[ $i ] ?? '' ) ) );
			}

			$rows[] = array(
				'line'   => $line,
				'values' => $values,
			);
		}//end for

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A memory stream.

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * A date from a file: Y-m-d or Y-m-d H:i:s (or H:i), read as UTC. Empty gives null; anything else gives false.
	 *
	 * @param string $value Text.
	 * @return DateTimeImmutable|null|false
	 */
	public static function date( string $value ) {
		if ( '' === $value ) {
			return null;
		}

		$utc = new DateTimeZone( 'UTC' );

		foreach ( array( '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( $format, $value, $utc );

			// A date that does not exist (2026-02-31) is rolled over by PHP; formatting it back shows that.
			if ( false !== $date && $date->format( ltrim( $format, '!' ) ) === $value ) {
				return '!Y-m-d' === $format ? $date->setTime( 23, 59, 59 ) : $date;
			}
		}

		return false;
	}

	/**
	 * Takes off the apostrophe an export put before a value that looked like a formula.
	 *
	 * @param string $value Value.
	 */
	private static function uncell( string $value ): string {
		return strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( "=+-@\t\r", $value[1] ) ? substr( $value, 1 ) : $value;
	}
}
