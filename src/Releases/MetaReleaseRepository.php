<?php
/**
 * Release storage in product settings.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Releases;

use Devllo\WPLicenseIt\Products\ProductMeta;

/**
 * Keeps a product's releases as one JSON value in its settings.
 */
final class MetaReleaseRepository implements ReleaseRepository {

	public const KEY = 'wplit_releases';

	/**
	 * @var ProductMeta
	 */
	private $meta;

	/**
	 * Constructor.
	 *
	 * @param ProductMeta $meta Product settings.
	 */
	public function __construct( ProductMeta $meta ) {
		$this->meta = $meta;
	}

	public function all( int $product_id ): array {
		$raw  = $this->meta->get( $product_id, self::KEY );
		$rows = '' !== $raw ? json_decode( $raw, true ) : null;

		$releases = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$release = Release::from_array( $row );
			if ( null !== $release ) {
				$releases[] = $release;
			}
		}

		return $releases;
	}

	public function save( int $product_id, array $releases ): void {
		$rows = array_map(
			static function ( Release $release ): array {
				return $release->to_array();
			},
			array_values( $releases )
		);

		$this->meta->set( $product_id, self::KEY, (string) json_encode( $rows ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress dependency in this class.
	}
}
