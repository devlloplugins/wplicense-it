<?php
/**
 * Checks a CSV of licenses before anything is imported.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Transfer;

use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\Licenses\Status;

/**
 * Turns a parsed file (LicenseCsv::parse) into a plan: the rows that will be imported, the rows skipped because their
 * key is already in use (an existing license is never changed), and the rows with a mistake, each with its line number
 * and why. Nothing is written here; the site owner sees the plan and confirms it.
 *
 * Columns: license_key, email and product_id (or product, a product name) are required; status (default active),
 * activation_limit (default 1, 0 for unlimited), expires_at (empty for never), created_at and user_id are optional.
 */
final class LicenseImport {

	public const MAX_ROWS = 5000;

	/**
	 * Constructor.
	 *
	 * @param callable(int): bool     $product_exists Whether a license product ID exists.
	 * @param callable(string): int[] $products_named License product IDs with exactly this name.
	 * @param callable(string): bool  $key_in_use     Whether a license already has this key.
	 * @param callable(int): bool     $user_exists    Whether a WordPress user ID exists.
	 */
	public function __construct(
		private $product_exists,
		private $products_named,
		private $key_in_use,
		private $user_exists
	) {
	}

	/**
	 * The plan for a parsed file.
	 *
	 * @param array{columns: string[], rows: list<array{line: int, values: array<string, string>}>} $parsed Parsed file.
	 * @return array{fatal: string, import: list<array{line:int,license_key:string,product_id:int,email:string,status:string,activation_limit:int,expires_at:?string,created_at:?string,user_id:?int}>, skipped: list<array{line:int,reason:string}>, errors: list<array{line:int,reason:string}>}
	 */
	public function check( array $parsed ): array {
		$plan = array(
			'fatal'   => '',
			'import'  => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		$columns = $parsed['columns'];
		$missing = array();
		foreach ( array( 'license_key', 'email' ) as $required ) {
			if ( ! in_array( $required, $columns, true ) ) {
				$missing[] = $required;
			}
		}
		if ( ! in_array( 'product_id', $columns, true ) && ! in_array( 'product', $columns, true ) ) {
			$missing[] = 'product_id';
		}

		if ( array() !== $missing ) {
			$plan['fatal'] = sprintf(
				/* translators: %s: column names. */
				__( 'The first line of the file must name the columns, and these are missing: %s.', 'wplicense-it' ),
				implode( ', ', $missing )
			);

			return $plan;
		}

		if ( array() === $parsed['rows'] ) {
			$plan['fatal'] = __( 'The file has no licenses, only a header line.', 'wplicense-it' );

			return $plan;
		}

		if ( count( $parsed['rows'] ) > self::MAX_ROWS ) {
			$plan['fatal'] = sprintf(
				/* translators: %d: the most licenses one file may have. */
				__( 'The file has more than %d licenses. Split it into smaller files.', 'wplicense-it' ),
				self::MAX_ROWS
			);

			return $plan;
		}

		$seen = array();

		foreach ( $parsed['rows'] as $row ) {
			$line  = $row['line'];
			$value = static fn( string $name ): string => $row['values'][ $name ] ?? '';
			$fail  = static function ( string $reason ) use ( &$plan, $line ): void {
				$plan['errors'][] = array(
					'line'   => $line,
					'reason' => $reason,
				);
			};

			$key = $value( 'license_key' );
			if ( ! LicenseService::is_valid_key( $key ) ) {
				$fail( '' === $key ? __( 'The license key is empty.', 'wplicense-it' ) : __( 'The license key must be 4 to 64 characters, without spaces.', 'wplicense-it' ) );
				continue;
			}

			if ( isset( $seen[ $key ] ) ) {
				$fail(
					sprintf(
						/* translators: %d: line number. */
						__( 'The same license key is on line %d.', 'wplicense-it' ),
						$seen[ $key ]
					)
				);
				continue;
			}
			$seen[ $key ] = $line;

			if ( ( $this->key_in_use )( $key ) ) {
				$plan['skipped'][] = array(
					'line'   => $line,
					'reason' => __( 'A license with this key already exists. It is left as it is.', 'wplicense-it' ),
				);
				continue;
			}

			$product_id = $this->product( $value( 'product_id' ), $value( 'product' ) );
			if ( 0 === $product_id ) {
				$fail( __( 'No license product has this ID or name.', 'wplicense-it' ) );
				continue;
			}
			if ( -1 === $product_id ) {
				$fail( __( 'Several license products have this name. Use the product_id column instead.', 'wplicense-it' ) );
				continue;
			}

			$email = strtolower( $value( 'email' ) );
			if ( ! LicenseService::is_valid_email( $email ) ) {
				$fail( __( 'The email address is not valid.', 'wplicense-it' ) );
				continue;
			}

			$status = '' === $value( 'status' ) ? Status::ACTIVE : strtolower( $value( 'status' ) );
			if ( ! in_array( $status, Status::all(), true ) ) {
				$fail( __( 'The status must be active, expired, revoked or refunded.', 'wplicense-it' ) );
				continue;
			}

			$limit = $value( 'activation_limit' );
			if ( '' !== $limit && ( 1 !== preg_match( '/^\d{1,6}$/', $limit ) || (int) $limit > LicenseService::MAX_ACTIVATION_LIMIT ) ) {
				$fail(
					sprintf(
						/* translators: %d: the highest activation limit. */
						__( 'The activation limit must be a whole number from 0 to %d.', 'wplicense-it' ),
						LicenseService::MAX_ACTIVATION_LIMIT
					)
				);
				continue;
			}

			$expires = LicenseCsv::date( $value( 'expires_at' ) );
			$created = LicenseCsv::date( $value( 'created_at' ) );
			if ( false === $expires || false === $created ) {
				$fail( __( 'A date is not valid. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC), or leave expires_at empty for never.', 'wplicense-it' ) );
				continue;
			}

			$user = $value( 'user_id' );
			if ( '' !== $user && ( 1 !== preg_match( '/^\d{1,19}$/', $user ) || ! ( $this->user_exists )( (int) $user ) ) ) {
				$fail( __( 'No WordPress user has this user_id. Leave it empty to import the license without an account.', 'wplicense-it' ) );
				continue;
			}

			$plan['import'][] = array(
				'line'             => $line,
				'license_key'      => $key,
				'product_id'       => $product_id,
				'email'            => $email,
				'status'           => $status,
				'activation_limit' => '' === $limit ? 1 : (int) $limit,
				'expires_at'       => null === $expires ? null : $expires->format( 'Y-m-d H:i:s' ),
				'created_at'       => null === $created ? null : $created->format( 'Y-m-d H:i:s' ),
				'user_id'          => '' === $user ? null : (int) $user,
			);
		}//end foreach

		return $plan;
	}

	/**
	 * The product a row names: its ID, 0 if none matches, -1 if a name matches several.
	 *
	 * @param string $id   Value of product_id.
	 * @param string $name Value of product.
	 */
	private function product( string $id, string $name ): int {
		if ( '' !== $id ) {
			return 1 === preg_match( '/^\d{1,19}$/', $id ) && ( $this->product_exists )( (int) $id ) ? (int) $id : 0;
		}

		if ( '' === $name ) {
			return 0;
		}

		$found = ( $this->products_named )( $name );

		return 1 === count( $found ) ? (int) $found[0] : ( count( $found ) > 1 ? -1 : 0 );
	}
}
