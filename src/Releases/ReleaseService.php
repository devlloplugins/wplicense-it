<?php
/**
 * Releases of a product.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Releases;

use DateTimeImmutable;
use DateTimeZone;
use Devllo\WPLicenseIt\Products\ProductMeta;

/**
 * Keeps every upload as a release, knows which one customers get, and can switch it back to an older one.
 *
 * Customers get the product's "current" release. The settings the license API reads (version, zip path, file name,
 * requirements, notes) always describe the current release, so the API needs no knowledge of the history. A product
 * that was set up before releases were recorded gets its one release from those settings the first time anything
 * is saved.
 */
final class ReleaseService {

	public const CURRENT_KEY = 'wplit_current_release';
	public const NOTES_KEY   = 'wplit_changelog';
	public const MAX_NOTES   = 5000;

	/**
	 * @var ReleaseRepository
	 */
	private $repo;

	/**
	 * @var ProductMeta
	 */
	private $meta;

	/**
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param ReleaseRepository $repo  Release storage.
	 * @param ProductMeta       $meta  Product settings.
	 * @param callable|null     $clock Returns the current UTC DateTimeImmutable.
	 */
	public function __construct( ReleaseRepository $repo, ProductMeta $meta, ?callable $clock = null ) {
		$this->repo  = $repo;
		$this->meta  = $meta;
		$this->clock = $clock ?? static function (): DateTimeImmutable {
			return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		};
	}

	/**
	 * The releases of a product, newest first.
	 *
	 * @param int $product_id License product ID.
	 * @return Release[]
	 */
	public function all( int $product_id ): array {
		return array_reverse( $this->load( $product_id ) );
	}

	/**
	 * The release customers get now, or null if the product has none.
	 *
	 * @param int $product_id License product ID.
	 */
	public function current( int $product_id ): ?Release {
		$releases = $this->load( $product_id );
		$id       = $this->meta->get( $product_id, self::CURRENT_KEY );

		foreach ( $releases as $release ) {
			if ( '' !== $id && $release->id === $id ) {
				return $release;
			}
		}

		// No pointer yet (a product from before releases): the one the settings describe, else the newest.
		$path = $this->meta->get( $product_id, 'file_dir_path' );
		foreach ( $releases as $release ) {
			if ( '' !== $path && $release->path === $path ) {
				return $release;
			}
		}

		return $releases ? $releases[ count( $releases ) - 1 ] : null;
	}

	/**
	 * Records an upload as a new release and makes it the current one.
	 *
	 * @param int     $product_id License product ID.
	 * @param Release $release    The new release. Its ID and time are set here.
	 * @return string[] Things worth a second look.
	 */
	public function add( int $product_id, Release $release ): array {
		$releases = $this->load( $product_id );
		$previous = $this->current( $product_id );
		$warnings = array();

		$release->id          = $this->new_id( $releases );
		$release->uploaded_at = ( $this->clock )()->format( 'Y-m-d H:i:s' );
		$release->notes       = self::notes( $release->notes );

		foreach ( $releases as $existing ) {
			if ( $existing->version === $release->version ) {
				$warnings[] = 'Version ' . $release->version . ' was already released. Sites on that version will not be offered this file as an update. Raise the version number if it should reach them.';
				break;
			}
		}

		if ( null !== $previous && '' !== $previous->version && version_compare( $release->version, $previous->version, '<' ) ) {
			$warnings[] = 'This release (' . $release->version . ') is older than the one customers had (' . $previous->version . '). Sites already on ' . $previous->version . ' will not be offered it.';
		}

		$releases[] = $release;
		$this->repo->save( $product_id, $releases );
		$this->apply( $product_id, $release );

		return $warnings;
	}

	/**
	 * Makes an existing release the one customers get, which is how a bad release is rolled back. Sites that already
	 * updated stay on the newer version: WordPress does not downgrade on its own.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $release_id Release ID.
	 * @throws ReleaseException If there is no such release.
	 */
	public function make_current( int $product_id, string $release_id ): Release {
		$releases = $this->load( $product_id );
		$release  = $this->find( $releases, $release_id );

		$this->repo->save( $product_id, $releases );
		$this->apply( $product_id, $release );

		return $release;
	}

	/**
	 * Corrects the version of a release. If it is the one customers get, the product's version follows.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $release_id Release ID.
	 * @param string $version    Cleaned version.
	 * @throws ReleaseException If there is no such release.
	 */
	public function set_version( int $product_id, string $release_id, string $version ): void {
		$releases         = $this->load( $product_id );
		$release          = $this->find( $releases, $release_id );
		$release->version = $version;

		$this->repo->save( $product_id, $releases );

		$current = $this->current( $product_id );
		if ( null !== $current && $current->id === $release->id ) {
			$this->meta->set( $product_id, 'wplit_product_version', $version );
		}
	}

	/**
	 * Changes the notes of a release.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $release_id Release ID.
	 * @param string $notes      New notes.
	 * @throws ReleaseException If there is no such release.
	 */
	public function set_notes( int $product_id, string $release_id, string $notes ): void {
		$releases       = $this->load( $product_id );
		$release        = $this->find( $releases, $release_id );
		$release->notes = self::notes( $notes );

		$this->repo->save( $product_id, $releases );

		$current = $this->current( $product_id );
		if ( null !== $current && $current->id === $release->id ) {
			$this->meta->set( $product_id, self::NOTES_KEY, $release->notes );
		}
	}

	/**
	 * Removes a release from the history. The caller deletes its file.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $release_id Release ID.
	 * @return Release The release that was removed.
	 * @throws ReleaseException If there is no such release, or it is the one customers get.
	 */
	public function remove( int $product_id, string $release_id ): Release {
		$releases = $this->load( $product_id );
		$release  = $this->find( $releases, $release_id );
		$current  = $this->current( $product_id );

		if ( null !== $current && $current->id === $release->id ) {
			throw new ReleaseException( 'This is the release customers get now. Make another release current before deleting it.' );
		}

		$this->repo->save(
			$product_id,
			array_values(
				array_filter(
					$releases,
					static function ( Release $other ) use ( $release ): bool {
						return $other->id !== $release->id;
					}
				)
			)
		);

		return $release;
	}

	/**
	 * Whether another release of the product uses the same zip file, so deleting the file would break it.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $release_id Release ID.
	 * @param string $path       Zip path.
	 */
	public function shares_file( int $product_id, string $release_id, string $path ): bool {
		foreach ( $this->load( $product_id ) as $release ) {
			if ( $release->id !== $release_id && $release->path === $path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cleans release notes: plain text lines, cut to a length.
	 *
	 * @param string $notes Typed or read notes.
	 */
	public static function notes( string $notes ): string {
		$lines = array();
		foreach ( (array) preg_split( '/\R/', strip_tags( $notes ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Plain text is wanted, and this also runs without WordPress.
			$line = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		$text = implode( "\n", $lines );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_NOTES ) : substr( $text, 0, self::MAX_NOTES );
	}

	/**
	 * The recorded releases, or the one described by the product's settings if none are recorded yet.
	 *
	 * @param int $product_id License product ID.
	 * @return Release[] Oldest first.
	 */
	private function load( int $product_id ): array {
		$releases = $this->repo->all( $product_id );
		if ( $releases ) {
			return $releases;
		}

		$path = $this->meta->get( $product_id, 'file_dir_path' );
		$name = $this->meta->get( $product_id, 'file_name' );
		if ( '' === $path || '' === $name ) {
			return array();
		}

		return array(
			new Release(
				substr( sha1( $path ), 0, 8 ),
				$this->meta->get( $product_id, 'wplit_product_version' ),
				$path,
				$this->meta->get( $product_id, 'file_dir_location' ),
				$name,
				0,
				'',
				$this->meta->get( $product_id, self::NOTES_KEY ),
				$this->meta->get( $product_id, 'wplit_package_type' ),
				$this->meta->get( $product_id, 'wplit_package_name' ),
				$this->meta->get( $product_id, 'wplit_package_folder' ),
				$this->meta->get( $product_id, 'wplit_required_php_version' ),
				$this->meta->get( $product_id, 'wplit_required_wp_version' ),
				$this->meta->get( $product_id, 'wplit_tested_wp_version' )
			),
		);
	}

	/**
	 * Writes the settings the license API reads, from a release. Requirements are only replaced when the release
	 * states them, so ones typed by the seller are not wiped.
	 *
	 * @param int     $product_id License product ID.
	 * @param Release $release    The release customers now get.
	 */
	private function apply( int $product_id, Release $release ): void {
		$this->meta->set( $product_id, self::CURRENT_KEY, $release->id );
		$this->meta->set( $product_id, 'wplit_product_version', $release->version );
		$this->meta->set( $product_id, 'file_dir_path', $release->path );
		$this->meta->set( $product_id, 'file_dir_location', $release->url );
		$this->meta->set( $product_id, 'file_name', $release->name );
		$this->meta->set( $product_id, 'wplit_package_type', $release->type );
		$this->meta->set( $product_id, 'wplit_package_name', $release->package_name );
		$this->meta->set( $product_id, 'wplit_package_folder', $release->folder );
		$this->meta->set( $product_id, 'wplit_required_php_version', $release->requires_php );
		$this->meta->set( $product_id, self::NOTES_KEY, $release->notes );

		if ( '' !== $release->requires_wp ) {
			$this->meta->set( $product_id, 'wplit_required_wp_version', $release->requires_wp );
		}
		if ( '' !== $release->tested ) {
			$this->meta->set( $product_id, 'wplit_tested_wp_version', $release->tested );
		}
	}

	/**
	 * A release by ID.
	 *
	 * @param Release[] $releases Releases.
	 * @param string    $id       Release ID.
	 * @throws ReleaseException If there is no such release.
	 */
	private function find( array $releases, string $id ): Release {
		foreach ( $releases as $release ) {
			if ( $release->id === $id ) {
				return $release;
			}
		}

		throw new ReleaseException( 'That release no longer exists.' );
	}

	/**
	 * A new release ID that no release of the product has.
	 *
	 * @param Release[] $releases Releases so far.
	 */
	private function new_id( array $releases ): string {
		$taken = array();
		foreach ( $releases as $release ) {
			$taken[ $release->id ] = true;
		}

		do {
			$id = bin2hex( random_bytes( 4 ) );
		} while ( isset( $taken[ $id ] ) );

		return $id;
	}
}
