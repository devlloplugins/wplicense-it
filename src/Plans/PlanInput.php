<?php
/**
 * Checks the plans a seller typed in.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Plans;

/**
 * Turns the rows of the Plans table into plans, or into messages saying what to fix. Nothing is saved when
 * there are errors, so a typo never changes a live price.
 */
final class PlanInput {

	public const MAX_PLANS     = 20;
	public const MAX_SITES     = 100000;
	public const MAX_NAME      = 80;
	public const PERIODS       = array( 'lifetime', 'P1M', 'P3M', 'P6M', 'P1Y', 'P2Y' );
	private const ID_PATTERN   = '/^[a-f0-9]{8}$/';
	private const PRICE_FORMAT = '/^[0-9]{1,9}(?:[.,][0-9]{1,3})?$/';

	/**
	 * Checks the submitted rows.
	 *
	 * @param mixed[]       $rows     Submitted rows, each with id, name, price, sites and period.
	 * @param Plan[]        $existing The plans saved so far. A row whose ID matches one keeps its WooCommerce link.
	 * @param callable|null $new_id   Returns a new plan ID. Defaults to a random one.
	 * @return array{plans:Plan[],errors:string[]}
	 */
	public static function parse( array $rows, array $existing, ?callable $new_id = null ): array {
		$new_id = $new_id ?? static function (): string {
			return bin2hex( random_bytes( 4 ) );
		};

		$known = array();
		foreach ( $existing as $plan ) {
			$known[ $plan->id ] = $plan;
		}

		$plans  = array();
		$errors = array();
		$names  = array();
		$used   = array();
		$number = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name_raw  = self::text( $row['name'] ?? '' );
			$price_raw = trim( self::text( $row['price'] ?? '' ) );

			// A row left completely empty is the spare row of the form, not a mistake.
			if ( '' === $name_raw && '' === $price_raw && '' === trim( self::text( $row['sites'] ?? '' ) ) ) {
				continue;
			}

			++$number;
			$label = 'Plan ' . $number;
			$ok    = true;

			if ( $number > self::MAX_PLANS ) {
				$errors[] = sprintf(
				/* translators: %d: the most plans a product can have. */
					__( 'There can be at most %d plans.', 'wplicense-it' ),
					self::MAX_PLANS
				);
				break;
			}

			if ( '' === $name_raw ) {
				$errors[] = $label . ': enter a name.';
				$ok       = false;
			} elseif ( self::length( $name_raw ) > self::MAX_NAME ) {
				$errors[] = $label . ': the name is too long (at most ' . self::MAX_NAME . ' characters).';
				$ok       = false;
			} elseif ( isset( $names[ strtolower( $name_raw ) ] ) ) {
				$errors[] = $label . ': another plan already has the name "' . $name_raw . '". Plan names must be different.';
				$ok       = false;
			}
			$names[ strtolower( $name_raw ) ] = true;

			if ( ! preg_match( self::PRICE_FORMAT, $price_raw ) ) {
				$errors[] = $label . ': enter the price as a number such as 49 or 49.50. Use 0 for a free plan.';
				$ok       = false;
			}

			$sites_raw = trim( self::text( $row['sites'] ?? '' ) );
			if ( '' === $sites_raw || ! ctype_digit( $sites_raw ) || (int) $sites_raw > self::MAX_SITES ) {
				$errors[] = $label . ': enter the number of sites as a whole number from 0 to ' . self::MAX_SITES . '. Use 0 for unlimited.';
				$ok       = false;
			}

			$period = self::text( $row['period'] ?? '' );
			if ( ! in_array( $period, self::PERIODS, true ) ) {
				$errors[] = $label . ': choose how long the license lasts.';
				$ok       = false;
			}

			if ( ! $ok ) {
				continue;
			}

			$price = str_replace( ',', '.', $price_raw );

			// Only an ID that belongs to a saved plan is trusted, and only once.
			$id = self::text( $row['id'] ?? '' );
			if ( preg_match( self::ID_PATTERN, $id ) && isset( $known[ $id ] ) && ! isset( $used[ $id ] ) ) {
				$old         = $known[ $id ];
				$used[ $id ] = true;
				$plan        = new Plan( $id, $name_raw, $price, (int) $sites_raw, $period, $old->woo_product_id, $old->synced_minor, $old->synced_currency, $old->conflict_minor, $old->tested_at, $old->tested_hash );
			} else {
				$plan = new Plan( (string) $new_id(), $name_raw, $price, (int) $sites_raw, $period );
			}

			$plans[] = $plan;
		}//end foreach

		return array(
			'plans'  => $plans,
			'errors' => $errors,
		);
	}

	/**
	 * The saved plans that are missing from the new list.
	 *
	 * @param Plan[] $existing Saved plans.
	 * @param Plan[] $plans    New plans.
	 * @return Plan[]
	 */
	public static function removed( array $existing, array $plans ): array {
		$keep = array();
		foreach ( $plans as $plan ) {
			$keep[ $plan->id ] = true;
		}

		return array_values(
			array_filter(
				$existing,
				static function ( Plan $plan ) use ( $keep ): bool {
					return ! isset( $keep[ $plan->id ] );
				}
			)
		);
	}

	/**
	 * Plain text: no tags, no control characters, single spaces.
	 *
	 * @param mixed $value Submitted value.
	 */
	private static function text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Plain text is wanted, and this also runs without WordPress.
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );

		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Length in characters.
	 *
	 * @param string $value Text.
	 */
	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
