<?php
/**
 * Fake database that records the SQL it is asked to run.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Support;

require_once __DIR__ . '/wpdb-stub.php';

/**
 * Implements just enough of wpdb (prepare, esc_like, reads) to check the SQL repositories build.
 */
final class FakeWpdb extends \wpdb {

	/**
	 * SQL of every query run, after preparing.
	 *
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Value returned by get_var().
	 *
	 * @var mixed
	 */
	public $var = '0';

	/**
	 * Rows returned by get_results().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * Prepares SQL like wpdb::prepare: %s is quoted and escaped, %d is cast to int. Accepts an array of values.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Values, or a single array of values.
	 */
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return preg_replace_callback(
			'/%[sd]/',
			static function ( array $match ) use ( &$i, $args ): string {
				$value = $args[ $i++ ];

				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$sql
		);
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_var( $query ) {
		$this->queries[] = $query;

		return $this->var;
	}

	public function get_results( $query, $output = 'OBJECT' ) {
		$this->queries[] = $query;

		return $this->rows;
	}

	/**
	 * The last SQL that was run.
	 */
	public function last_query(): string {
		return (string) end( $this->queries );
	}
}
