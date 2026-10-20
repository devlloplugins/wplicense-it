<?php
/**
 * Saves the product form.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Products;

use Devllo\WPLicenseIt\Packages\PackageException;
use Devllo\WPLicenseIt\Packages\PackageInfo;
use Devllo\WPLicenseIt\Packages\PackageReader;
use Devllo\WPLicenseIt\Releases\MetaReleaseRepository;
use Devllo\WPLicenseIt\Releases\Release;
use Devllo\WPLicenseIt\Releases\ReleaseException;
use Devllo\WPLicenseIt\Releases\ReleaseService;
use Devllo\WPLicenseIt\WooCommerce\ProductFields;

/**
 * Turns the submitted product form into saved settings and stored files.
 *
 * The caller has already checked the nonce, the user's rights and that this is a real save. Every value is
 * cleaned here. A field that was not submitted is left alone. One thing going wrong (a zip that is not a
 * plugin, an image of the wrong type) never stops the other fields from saving: it becomes a message in the
 * report.
 */
final class ProductSaver {

	/**
	 * @var ProductMeta
	 */
	private $meta;

	/**
	 * @var FileStore
	 */
	private $files;

	/**
	 * @var string
	 */
	private $upload_limit;

	/**
	 * @var ReleaseService
	 */
	private $releases;

	/**
	 * Constructor.
	 *
	 * @param ProductMeta         $meta         Where settings are kept.
	 * @param FileStore           $files        Where uploaded files go.
	 * @param string|null         $upload_limit The server's upload limit for messages. Defaults to ini upload_max_filesize.
	 * @param ReleaseService|null $releases     Release history. Defaults to one kept in the product settings.
	 */
	public function __construct( ProductMeta $meta, FileStore $files, ?string $upload_limit = null, ?ReleaseService $releases = null ) {
		$this->meta         = $meta;
		$this->files        = $files;
		$this->upload_limit = $upload_limit ?? (string) ini_get( 'upload_max_filesize' );
		$this->releases     = $releases ?? new ReleaseService( new MetaReleaseRepository( $meta ), $meta );
	}

	/**
	 * Saves a submitted form.
	 *
	 * @param int                  $product_id License product ID.
	 * @param array<string, mixed> $post       Submitted fields, without WordPress's added slashes.
	 * @param array<string, mixed> $uploads    The $_FILES array.
	 */
	public function save( int $product_id, array $post, array $uploads ): SaveReport {
		$report = new SaveReport();

		$this->save_defaults( $product_id, $post );

		$this->save_text( $product_id, $post, 'wplit_product_name', 120 );
		$this->save_text( $product_id, $post, 'wplit_product_description', 1000 );
		$this->save_wp_version( $product_id, $post, 'wplit_tested_wp_version' );
		$this->save_wp_version( $product_id, $post, 'wplit_required_wp_version' );

		$posted_version = self::posted( $post, 'wplit_product_version' );
		$version_posted = null !== $posted_version;
		$version        = $version_posted ? Version::clean( $posted_version ) : $this->meta->get( $product_id, 'wplit_product_version' );

		$notes         = self::posted( $post, 'wplit_release_notes' ) ?? '';
		$package_saved = $this->save_package( $product_id, $uploads, $notes, $report );
		if ( ! $package_saved ) {
			if ( $version_posted ) {
				$this->save_version( $product_id, $version );
			}
			$this->save_notes( $product_id, $notes, $report );
		}

		$this->save_image( $product_id, $uploads, 'wplit_product_logo', 'logo', 'wplit_product_logo_url', 'logo', $report );
		$this->save_image( $product_id, $uploads, 'wplit_product_banner', 'banner', 'wplit_product_banner_url', 'banner', $report );

		$this->save_api_key( $product_id, $post );

		return $report;
	}

	/**
	 * Defaults for licenses issued by hand: sites and period. Empty removes the setting.
	 *
	 * @param int                  $product_id License product ID.
	 * @param array<string, mixed> $post       Submitted fields.
	 */
	private function save_defaults( int $product_id, array $post ): void {
		$posted = self::posted( $post, 'wplit_default_activation_limit' );
		if ( null !== $posted ) {
			$limit = ProductFields::clean_limit( Text::line( $posted, 10 ) );
			'' === $limit ? $this->meta->delete( $product_id, 'wplit_default_activation_limit' ) : $this->meta->set( $product_id, 'wplit_default_activation_limit', $limit );
		}

		$posted = self::posted( $post, 'wplit_period' );
		if ( null !== $posted ) {
			$period = ProductFields::clean_period( Text::line( $posted, 20 ) );
			'' === $period ? $this->meta->delete( $product_id, 'wplit_period' ) : $this->meta->set( $product_id, 'wplit_period', $period );
		}
	}

	/**
	 * A line of plain text.
	 *
	 * @param int                  $product_id License product ID.
	 * @param array<string, mixed> $post       Submitted fields.
	 * @param string               $key        Field and setting name.
	 * @param int                  $length     Maximum length.
	 */
	private function save_text( int $product_id, array $post, string $key, int $length ): void {
		$posted = self::posted( $post, $key );
		if ( null !== $posted ) {
			$this->meta->set( $product_id, $key, Text::line( $posted, $length ) );
		}
	}

	/**
	 * A WordPress version number.
	 *
	 * @param int                  $product_id License product ID.
	 * @param array<string, mixed> $post       Submitted fields.
	 * @param string               $key        Field and setting name.
	 */
	private function save_wp_version( int $product_id, array $post, string $key ): void {
		$posted = self::posted( $post, $key );
		if ( null !== $posted ) {
			$this->meta->set( $product_id, $key, Text::wp_version( $posted ) );
		}
	}

	/**
	 * The release zip. Its header is the source of truth for the version and the requirements.
	 *
	 * @param int                  $product_id License product ID.
	 * @param array<string, mixed> $uploads    The $_FILES array.
	 * @param string               $notes      Notes typed for this release. Empty uses the readme's changelog.
	 * @param SaveReport           $report     Report, added to.
	 * @return bool Whether a new release was stored.
	 */
	private function save_package( int $product_id, array $uploads, string $notes, SaveReport $report ): bool {
		$file = $this->upload( $uploads, 'wplit_product_file_upload' );
		if ( null === $file ) {
			return false;
		}

		$problem = $this->upload_problem( $file );
		if ( null !== $problem ) {
			$report->errors[] = __( 'The release was not saved.', 'wplicense-it' ) . ' ' . $problem;

			return false;
		}

		try {
			$info   = PackageReader::read( (string) $file['tmp_name'] );
			$stored = $this->files->store_package( $product_id, (string) $file['tmp_name'], (string) $file['name'], Version::clean( $info->version ) );
		} catch ( PackageException $e ) {
			$report->errors[] = __( 'The release was not saved.', 'wplicense-it' ) . ' ' . $e->getMessage();

			return false;
		} catch ( FileStoreException $e ) {
			$report->errors[] = __( 'The release was not saved.', 'wplicense-it' ) . ' ' . $e->getMessage();

			return false;
		}

		$warnings = $this->releases->add( $product_id, $this->release_from( $info, $stored, (int) ( $file['size'] ?? 0 ), $notes ) );

		$report->messages[] = self::package_message( $info );
		$report->warnings   = array_merge( $report->warnings, $info->warnings, $warnings );

		return true;
	}

	/**
	 * The release record for an upload.
	 *
	 * @param PackageInfo $info   Details read from the zip.
	 * @param StoredFile  $stored Where the zip was stored.
	 * @param int         $size   Size in bytes.
	 * @param string      $notes  Notes typed for this release. Empty uses the readme's changelog.
	 */
	private function release_from( PackageInfo $info, StoredFile $stored, int $size, string $notes ): Release {
		return new Release(
			'',
			Version::clean( $info->version ),
			$stored->relative,
			$stored->url,
			$stored->name,
			max( 0, $size ),
			'',
			'' !== trim( $notes ) ? $notes : $info->changelog,
			$info->type,
			$info->name,
			$info->folder,
			$info->requires_php,
			$info->requires_wp,
			$info->tested_up_to
		);
	}

	/**
	 * A typed version with no new zip: the setting changes, and so does the current release.
	 *
	 * @param int    $product_id License product ID.
	 * @param string $version    Cleaned version.
	 */
	private function save_version( int $product_id, string $version ): void {
		$this->meta->set( $product_id, 'wplit_product_version', $version );

		$current = $this->releases->current( $product_id );
		if ( null !== $current && '' !== $version && $current->version !== $version ) {
			$this->releases->set_version( $product_id, $current->id, $version );
		}
	}

	/**
	 * Notes typed with no new zip replace the notes of the current release.
	 *
	 * @param int        $product_id License product ID.
	 * @param string     $notes      Typed notes.
	 * @param SaveReport $report     Report, added to.
	 */
	private function save_notes( int $product_id, string $notes, SaveReport $report ): void {
		if ( '' === trim( $notes ) ) {
			return;
		}

		$current = $this->releases->current( $product_id );
		if ( null === $current ) {
			$report->warnings[] = __( 'The release notes were not saved: there is no release yet. Upload a zip first.', 'wplicense-it' );

			return;
		}

		try {
			$this->releases->set_notes( $product_id, $current->id, $notes );
			$report->messages[] = __( 'The release notes were updated.', 'wplicense-it' );
		} catch ( ReleaseException $e ) {
			$report->errors[] = $e->getMessage();
		}
	}

	/**
	 * A logo or banner.
	 *
	 * @param int                  $product_id License product ID.
	 * @param array<string, mixed> $uploads    The $_FILES array.
	 * @param string               $field      Upload field name.
	 * @param string               $kind       'logo' or 'banner'.
	 * @param string               $meta_key   Setting that holds the image address.
	 * @param string               $label      Name for messages.
	 * @param SaveReport           $report     Report, added to.
	 */
	private function save_image( int $product_id, array $uploads, string $field, string $kind, string $meta_key, string $label, SaveReport $report ): void {
		$file = $this->upload( $uploads, $field );
		if ( null === $file ) {
			return;
		}

		$problem = $this->upload_problem( $file );
		if ( null !== $problem ) {
			$report->errors[] = 'The ' . $label . ' was not saved. ' . $problem;

			return;
		}

		try {
			$stored = $this->files->store_image( $product_id, $kind, $file );
		} catch ( FileStoreException $e ) {
			$report->errors[] = 'The ' . $label . ' was not saved. ' . $e->getMessage();

			return;
		}

		$this->meta->set( $product_id, $meta_key, $stored->url );
		$report->messages[] = 'The ' . $label . ' was saved.';
	}

	/**
	 * The old API key, still needed by 1.x clients. A typed key is kept. An empty field keeps the current key, and a
	 * product with none gets a new one, so an existing key is never replaced by accident.
	 *
	 * @param int                  $product_id License product ID.
	 * @param array<string, mixed> $post       Submitted fields.
	 */
	private function save_api_key( int $product_id, array $post ): void {
		$typed = Text::line( self::posted( $post, 'wplit_product_api_key' ) ?? '', 64 );

		if ( '' !== $typed ) {
			$this->meta->set( $product_id, 'wplit_product_api_key', $typed );

			return;
		}

		if ( '' === $this->meta->get( $product_id, 'wplit_product_api_key' ) ) {
			$this->meta->set( $product_id, 'wplit_product_api_key', bin2hex( random_bytes( 12 ) ) );
		}
	}

	/**
	 * A submitted value as text, or null if the field was not submitted or was not a plain value (a form field
	 * sent as a list, for example). A field that is not understood is left alone.
	 *
	 * @param array<string, mixed> $post Submitted fields.
	 * @param string               $key  Field name.
	 */
	private static function posted( array $post, string $key ): ?string {
		return array_key_exists( $key, $post ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : null;
	}

	/**
	 * The $_FILES entry for a field, or null when nothing was chosen.
	 *
	 * @param array<string, mixed> $uploads The $_FILES array.
	 * @param string               $field   Field name.
	 * @return array<string, mixed>|null
	 */
	private function upload( array $uploads, string $field ): ?array {
		$file = $uploads[ $field ] ?? null;

		if ( ! is_array( $file ) || ! isset( $file['name'] ) || ! is_string( $file['name'] ) || '' === $file['name'] ) {
			return null;
		}

		return $file;
	}

	/**
	 * Why an upload cannot be used, or null if it can.
	 *
	 * @param array<string, mixed> $file The $_FILES entry.
	 */
	private function upload_problem( array $file ): ?string {
		$error = UploadError::message( (int) ( $file['error'] ?? UPLOAD_ERR_OK ), $this->upload_limit );
		if ( null !== $error ) {
			return $error;
		}

		$tmp = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp || ! $this->files->is_uploaded( $tmp ) ) {
			return __( 'The upload could not be verified. Try again.', 'wplicense-it' );
		}

		return null;
	}

	/**
	 * The message after a release was read.
	 *
	 * @param PackageInfo $info Details read from the zip.
	 */
	public static function package_message( PackageInfo $info ): string {
		$needs = array();
		if ( '' !== $info->requires_wp ) {
			$needs[] = 'WordPress ' . $info->requires_wp;
		}
		if ( '' !== $info->requires_php ) {
			$needs[] = 'PHP ' . $info->requires_php;
		}

		/* translators: 1: software name, 2: version, 3: plugin or theme. */
		return sprintf( __( 'Release saved. Read from the zip: %1$s %2$s (%3$s).', 'wplicense-it' ), $info->name, $info->version, $info->type )
			. ( $needs ? ' Needs ' . implode( ' and ', $needs ) . '.' : '' )
			. ' The version and requirements were filled in from the file.';
	}
}
