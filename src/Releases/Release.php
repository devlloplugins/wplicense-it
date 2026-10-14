<?php
/**
 * A release.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Releases;

/**
 * One uploaded version of a plugin or theme: the zip, what it said about itself, and the notes for customers.
 */
final class Release {

	/**
	 * Constructor.
	 *
	 * @param string $id           Stable short ID.
	 * @param string $version      Version.
	 * @param string $path         Zip path relative to the uploads folder.
	 * @param string $url          Address of the zip. Never served: downloads go through the license API.
	 * @param string $name         Zip file name.
	 * @param int    $size         Size in bytes, 0 if unknown.
	 * @param string $uploaded_at  When it was uploaded (UTC, Y-m-d H:i:s), '' if unknown.
	 * @param string $notes        What changed, shown to customers.
	 * @param string $type         'plugin' or 'theme', or ''.
	 * @param string $package_name Name read from the zip, or ''.
	 * @param string $folder       Folder inside the zip, or ''.
	 * @param string $requires_php Minimum PHP version, or ''.
	 * @param string $requires_wp  Minimum WordPress version, or ''.
	 * @param string $tested       Tested up to WordPress version, or ''.
	 */
	public function __construct(
		public string $id,
		public string $version,
		public string $path,
		public string $url,
		public string $name,
		public int $size = 0,
		public string $uploaded_at = '',
		public string $notes = '',
		public string $type = '',
		public string $package_name = '',
		public string $folder = '',
		public string $requires_php = '',
		public string $requires_wp = '',
		public string $tested = ''
	) {
	}

	/**
	 * Storage form.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'version'      => $this->version,
			'path'         => $this->path,
			'url'          => $this->url,
			'name'         => $this->name,
			'size'         => $this->size,
			'uploaded_at'  => $this->uploaded_at,
			'notes'        => $this->notes,
			'type'         => $this->type,
			'package_name' => $this->package_name,
			'folder'       => $this->folder,
			'requires_php' => $this->requires_php,
			'requires_wp'  => $this->requires_wp,
			'tested'       => $this->tested,
		);
	}

	/**
	 * Builds a release from stored data. Returns null if the data is not a release, so a damaged row is skipped.
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || empty( $data['id'] ) || ! isset( $data['version'], $data['path'], $data['name'] ) ) {
			return null;
		}

		return new self(
			(string) $data['id'],
			(string) $data['version'],
			(string) $data['path'],
			(string) ( $data['url'] ?? '' ),
			(string) $data['name'],
			(int) ( $data['size'] ?? 0 ),
			(string) ( $data['uploaded_at'] ?? '' ),
			(string) ( $data['notes'] ?? '' ),
			(string) ( $data['type'] ?? '' ),
			(string) ( $data['package_name'] ?? '' ),
			(string) ( $data['folder'] ?? '' ),
			(string) ( $data['requires_php'] ?? '' ),
			(string) ( $data['requires_wp'] ?? '' ),
			(string) ( $data['tested'] ?? '' )
		);
	}
}
