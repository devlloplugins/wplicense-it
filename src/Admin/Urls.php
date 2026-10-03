<?php
/**
 * Admin URLs.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

/**
 * Page slugs and the URLs of the license admin screens and actions.
 */
final class Urls {

	public const PAGE_LICENSES = 'wplit-licenses';
	public const PAGE_ADD      = 'wplit-add-license';
	public const PAGE_SETTINGS = 'wplit-licensing-settings';
	public const PARENT        = 'edit.php?post_type=wplit_product';
	public const ACTION        = 'wplit_license_action';

	/**
	 * The license list.
	 *
	 * @param array<string, scalar> $args Extra query arguments.
	 */
	public static function list( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'post_type' => 'wplit_product', 'page' => self::PAGE_LICENSES ), $args ), admin_url( 'edit.php' ) );
	}

	/**
	 * One license.
	 *
	 * @param int                   $license_id License ID.
	 * @param array<string, scalar> $args       Extra query arguments.
	 */
	public static function view( int $license_id, array $args = array() ): string {
		return self::list( array_merge( array( 'license' => $license_id ), $args ) );
	}

	/**
	 * The add license screen.
	 */
	public static function add(): string {
		return add_query_arg( array( 'post_type' => 'wplit_product', 'page' => self::PAGE_ADD ), admin_url( 'edit.php' ) );
	}

	/**
	 * The settings screen.
	 */
	public static function settings(): string {
		return add_query_arg( array( 'post_type' => 'wplit_product', 'page' => self::PAGE_SETTINGS ), admin_url( 'edit.php' ) );
	}

	/**
	 * A link that performs an action on a license, protected by a nonce.
	 *
	 * @param string $do         Action name.
	 * @param int    $license_id License ID.
	 */
	public static function action( string $do, int $license_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'do'      => $do,
					'license' => $license_id,
				),
				admin_url( 'admin-post.php' )
			),
			'wplit_license_' . $do . '_' . $license_id
		);
	}

	/**
	 * The hidden fields every action form needs.
	 *
	 * @param string $do         Action name.
	 * @param int    $license_id License ID.
	 */
	public static function form_fields( string $do, int $license_id ): string {
		return '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. '<input type="hidden" name="license" value="' . esc_attr( (string) $license_id ) . '">'
			. wp_nonce_field( 'wplit_license_' . $do . '_' . $license_id, '_wpnonce', true, false );
	}
}
