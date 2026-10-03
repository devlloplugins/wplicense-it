<?php
/**
 * Admin capability.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Admin;

/**
 * The capability that guards every license admin screen and action.
 */
final class Capabilities {

	public const MANAGE = 'manage_wplicense_it';

	/**
	 * Registers the hook that grants the capability.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'grant' ) );
	}

	/**
	 * Gives administrators the capability (cheap, so it is checked on every admin request).
	 * Other roles can be added with the wplicense_it_manage_roles filter.
	 */
	public static function grant(): void {
		$roles = (array) apply_filters( 'wplicense_it_manage_roles', array( 'administrator' ) );

		foreach ( $roles as $name ) {
			$role = get_role( (string) $name );

			if ( $role && ! $role->has_cap( self::MANAGE ) ) {
				$role->add_cap( self::MANAGE );
			}
		}
	}

	/**
	 * Stops the request unless the current user may manage licenses.
	 */
	public static function require_manage(): void {
		if ( ! current_user_can( self::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to manage licenses.', 'wplicense-it' ), '', array( 'response' => 403 ) );
		}
	}
}
