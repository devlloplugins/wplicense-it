<?php
/**
 * Plugin activation.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt;

use Devllo\WPLicenseIt\Admin\Capabilities;
use Devllo\WPLicenseIt\Database\Installer;
use Devllo\WPLicenseIt\Files\ProtectedStorage;

/**
 * What happens when the plugin is activated. Everything here is also repeated safely on
 * upgrades (see Plugin::boot), because updating a plugin does not run its activation hook.
 */
final class Activation {

	/**
	 * Activation hook: creates the tables and the protected package folder.
	 */
	public static function activate(): void {
		Installer::install();
		ProtectedStorage::create( ProtectedStorage::directory() );
		update_option( ProtectedStorage::VERSION_OPTION, ProtectedStorage::VERSION );
		Capabilities::grant();
		flush_rewrite_rules();
	}
}
