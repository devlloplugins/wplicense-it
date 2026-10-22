<?php
/**
 * Update information.
 *
 * @package WPLicenseIt\Client
 */

declare( strict_types=1 );

namespace WPLicenseIt\Client;

/**
 * The latest version of a product, as the server describes it. The short-lived download link is
 * deliberately not part of it: it is fetched when an update is installed.
 */
final class UpdateInfo {

	/**
	 * Product name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Latest version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Minimum WordPress version.
	 *
	 * @var string
	 */
	public $requires;

	/**
	 * WordPress version tested up to.
	 *
	 * @var string
	 */
	public $tested;

	/**
	 * Description.
	 *
	 * @var string
	 */
	public $description;

	/**
	 * What changed in this version, plain text lines. Empty if the seller wrote nothing.
	 *
	 * @var string
	 */
	public $changelog = '';

	/**
	 * Minimum PHP version, or ''. WordPress does not offer the update to a site with an older PHP.
	 *
	 * @var string
	 */
	public $requires_php = '';

	/**
	 * '1' when the license has expired: the update is shown but cannot be installed until the license is renewed.
	 *
	 * @var string
	 */
	public $needs_renewal = '';

	/**
	 * Where to renew, for an expired license (from the server), or ''.
	 *
	 * @var string
	 */
	public $renew_url = '';

	/**
	 * Small banner URL.
	 *
	 * @var string
	 */
	public $banner_low;

	/**
	 * Large banner URL.
	 *
	 * @var string
	 */
	public $banner_high;

	/**
	 * Builds the information from an updates/check response.
	 *
	 * @param array<string, mixed> $data Server response.
	 * @return self|null Null if the response has no usable version.
	 */
	public static function from_response( array $data ): ?self {
		$product = isset( $data['product'] ) && is_array( $data['product'] ) ? $data['product'] : array();

		if ( empty( $product['version'] ) ) {
			return null;
		}

		$banners = isset( $product['banners'] ) && is_array( $product['banners'] ) ? $product['banners'] : array();
		$info    = new self();

		$info->name         = (string) ( $product['name'] ?? '' );
		$info->version      = (string) $product['version'];
		$info->requires     = (string) ( $product['requires'] ?? '' );
		$info->tested       = (string) ( $product['tested'] ?? '' );
		$info->requires_php = (string) ( $product['requires_php'] ?? '' );
		$info->description  = (string) ( $product['description'] ?? '' );
		$info->changelog    = (string) ( $product['changelog'] ?? '' );
		$info->banner_low   = (string) ( $banners['low'] ?? '' );
		$info->banner_high  = (string) ( $banners['high'] ?? '' );

		return $info;
	}

	/**
	 * Array form, for the cache.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Rebuilds from the cache.
	 *
	 * @param mixed $data Cached array.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return null;
		}

		$info = new self();

		foreach ( array( 'name', 'version', 'requires', 'tested', 'description', 'changelog', 'banner_low', 'banner_high', 'requires_php', 'needs_renewal', 'renew_url' ) as $field ) {
			$info->$field = isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
		}

		return $info;
	}

	/**
	 * Whether the license has to be renewed before this version can be installed.
	 */
	public function needs_renewal(): bool {
		return '1' === $this->needs_renewal;
	}

	/**
	 * Whether this version is newer than the one installed.
	 *
	 * @param string $installed Installed version.
	 */
	public function is_newer_than( string $installed ): bool {
		return version_compare( $this->version, $installed, '>' );
	}
}
