<?php
/**
 * Converts 1.x rows to the 2.0 format.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Licenses\License;
use Devllo\WPLicenseIt\Licenses\Status;

/**
 * Pure conversion rules from docs/SCHEMA.md. No database access.
 *
 * 1.x stored dates in the site's time zone (and a zero date for "no expiry");
 * 2.0 stores UTC and NULL.
 */
final class LegacyConverter {

	private const BILLING_FIELDS = array(
		'order_email',
		'first_name',
		'last_name',
		'billing_company',
		'billing_address',
		'billing_state',
		'billing_city',
		'billing_country',
		'billing_phone',
		'postal_code',
		'discount_code',
	);

	/**
	 * Constructor.
	 *
	 * @param DateTimeZone $site_timezone Time zone the 1.x dates were written in.
	 */
	public function __construct( private DateTimeZone $site_timezone ) {
	}

	/**
	 * Converts a 1.x date to UTC.
	 *
	 * @param mixed $value DATETIME string from the 1.x tables.
	 * @return DateTimeImmutable|null Null for empty, zero or unparseable values.
	 */
	public function to_utc( $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === trim( $value ) || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}

		try {
			$local = new DateTimeImmutable( $value, $this->site_timezone );
		} catch ( \Exception $e ) {
			return null;
		}

		return $local->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Converts a 1.x license row.
	 *
	 * @param array<string, mixed> $row 1.x row.
	 * @param DateTimeImmutable    $now Current time (UTC), used for missing dates and expiry.
	 * @return array{license:License,notes:string[]} The license (ID 0) and anything worth reporting.
	 * @throws InvalidLegacyRow If the row cannot be migrated.
	 */
	public function license( array $row, DateTimeImmutable $now ): array {
		$notes = array();
		$id    = (int) ( $row['id'] ?? 0 );

		$key = trim( (string) ( $row['license_key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 64 ) {
			throw new InvalidLegacyRow( "License {$id}: the license key is empty or longer than 64 characters." );
		}

		$product_id = (int) ( $row['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			throw new InvalidLegacyRow( "License {$id}: it has no product." );
		}

		$email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
		if ( '' === $email ) {
			throw new InvalidLegacyRow( "License {$id}: it has no email." );
		}

		$expires = $this->to_utc( $row['valid_until'] ?? null );
		$status  = (string) ( $row['license_status'] ?? '' );

		if ( 'active' === $status ) {
			$status = null !== $expires && $expires <= $now ? Status::EXPIRED : Status::ACTIVE;
		} else {
			$notes[] = "License {$id}: unknown status '{$status}', migrated as revoked.";
			$status  = Status::REVOKED;
		}

		$created = $this->to_utc( $row['created_at'] ?? null ) ?? $now;
		$updated = $this->to_utc( $row['updated_at'] ?? null ) ?? $created;
		$user_id = (int) ( $row['user_id'] ?? 0 );

		$license = new License(
			0,
			$product_id,
			$user_id > 0 ? $user_id : null,
			null,
			$key,
			$email,
			$status,
			0, // 1.x never limited sites. A new limit would lock out existing customers.
			$expires,
			$created,
			$updated
		);

		return array(
			'license' => $license,
			'notes'   => $notes,
		);
	}

	/**
	 * Converts a 1.x order row.
	 *
	 * @param array<string, mixed> $row          1.x row.
	 * @param DateTimeImmutable    $now          Current time (UTC), used for missing dates.
	 * @param bool                 $keep_billing Keep the billing fields as JSON.
	 * @throws InvalidLegacyRow If the row cannot be migrated.
	 */
	public function order( array $row, DateTimeImmutable $now, bool $keep_billing ): MigratedOrder {
		$id         = (int) ( $row['id'] ?? 0 );
		$product_id = (int) ( $row['product_id'] ?? 0 );

		if ( $product_id <= 0 ) {
			throw new InvalidLegacyRow( "Order {$id}: it has no product." );
		}

		$number = trim( (string) ( $row['order_number'] ?? '' ) );
		if ( '' === $number ) {
			$number = 'LEGACY-' . $id;
		}

		$total   = $this->to_minor_units( $row['order_total'] ?? '' );
		$status  = (string) ( $row['order_status'] ?? '' );
		$created = $this->to_utc( $row['created_at'] ?? null ) ?? $now;
		$updated = $this->to_utc( $row['updated_at'] ?? null ) ?? $created;
		$user_id = (int) ( $row['user_id'] ?? 0 );

		$billing = null;
		if ( $keep_billing ) {
			$data = array();
			foreach ( self::BILLING_FIELDS as $field ) {
				$data[ $field ] = (string) ( $row[ $field ] ?? '' );
			}
			$billing = (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress dependency in this class.
		}

		return new MigratedOrder(
			$id,
			$number,
			0 === $total ? 'free' : 'legacy_stripe',
			$user_id > 0 ? $user_id : null,
			$product_id,
			'USD', // 1.x charged in USD only.
			$total,
			'' === $status ? 'completed' : $status,
			$billing,
			$created,
			$updated
		);
	}

	/**
	 * Converts a price such as "29.99" to cents.
	 *
	 * @param mixed $value Price as stored by 1.x (text).
	 */
	public function to_minor_units( $value ): int {
		$clean = str_replace( ',', '', (string) $value );

		if ( 1 !== preg_match( '/-?\d+(?:\.\d+)?/', $clean, $match ) ) {
			return 0;
		}

		return max( 0, (int) round( (float) $match[0] * 100 ) );
	}
}
