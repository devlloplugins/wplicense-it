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

		$changed = false;

		foreach ( $roles as $name ) {
			$role = get_role( (string) $name );

			if ( $role && ! $role->has_cap( self::MANAGE ) ) {
				$role->add_cap( self::MANAGE );
				$changed = true;
			}
		}

		// The person making this request was loaded before the role changed, so they would not see the new menu until their
		// next page load. That is what an administrator of a network's subsite would meet on their first visit.
		if ( $changed && is_user_logged_in() ) {
			wp_get_current_user()->get_role_caps();
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
