<?php
/**
 * Whether an uploaded release still carries the license code for its product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Releases;

use Devllo\WPLicenseIt\Packages\PackageInfo;

/**
 * Customers who install a release without the client SDK and a wplicense_it_client() call for this product and this
 * server stop getting updates for good: their site no longer asks. So such a release is held back as a draft when the
 * code went missing or points elsewhere. A product that never had the code yet (its first uploads, before the Connect
 * step) only gets a warning, so setting up is not blocked.
 */
final class LicenseCodeCheck {

	/**
	 * Whether the package has the SDK and at least one call.
	 *
	 * @param PackageInfo $info Package details.
	 */
	public static function present( PackageInfo $info ): bool {
		return $info->has_sdk && array() !== $info->client_calls;
	}

	/**
	 * Reasons to hold the release back, and warnings.
	 *
	 * @param PackageInfo  $info       Package details.
	 * @param int          $product_id This product's ID.
	 * @param string       $home       This site's address (the license server).
	 * @param Release|null $current    The release customers get now.
	 * @return array{hold:string[], warnings:string[]}
	 */
	public static function check( PackageInfo $info, int $product_id, string $home, ?Release $current ): array {
		$hold     = array();
		$warnings = array();

		foreach ( $info->client_calls as $call ) {
			if ( null !== $call['product_id'] && $call['product_id'] !== $product_id ) {
				$hold[] = sprintf(
					/* translators: 1: product ID in the zip, 2: this product's ID. */
					__( 'Its wplicense_it_client() call is for product %1$d, not this product (%2$d), so customers would check the wrong product and get no updates. Copy the code from this product\'s Connect tab.', 'wplicense-it' ),
					$call['product_id'],
					$product_id
				);
			}

			if ( null !== $call['server'] && self::address( $call['server'] ) !== self::address( $home ) ) {
				$hold[] = sprintf(
					/* translators: 1: server address in the zip, 2: this site's address. */
					__( 'Its wplicense_it_client() call names the license server %1$s, but this site is %2$s, so customers would not reach it for updates.', 'wplicense-it' ),
					$call['server'],
					$home
				);
			}
		}

		if ( ! self::present( $info ) ) {
			$missing = $info->has_sdk
				? __( 'The client SDK is in the zip, but no wplicense_it_client() call was found.', 'wplicense-it' )
				: __( 'The client SDK (wplicense-it-client.php) was not found in the zip.', 'wplicense-it' );

			if ( null !== $current && 'yes' === $current->license_code ) {
				$hold[] = $missing . ' ' . __( 'The release customers have now includes it, so whoever installs this one would stop getting updates.', 'wplicense-it' );
			} else {
				$warnings[] = $missing . ' ' . __( 'Until a release includes the code from the Connect tab, customers cannot activate their license or get updates.', 'wplicense-it' );
			}
		}

		return array(
			'hold'     => array_values( array_unique( $hold ) ),
			'warnings' => $warnings,
		);
	}

	/**
	 * An address without scheme, www and trailing slash, for comparing.
	 *
	 * @param string $url Address.
	 */
	private static function address( string $url ): string {
		$url = strtolower( trim( $url ) );
		$url = (string) preg_replace( '#^[a-z]+://#', '', $url );
		$url = (string) preg_replace( '#^www\.#', '', $url );

		return rtrim( $url, '/' );
	}
}
