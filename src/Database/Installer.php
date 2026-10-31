<?php
/**
 * Table installer.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Database;

/**
 * Creates and upgrades the 2.0 tables.
 *
 * This only adds the new tables. It never touches the 1.x tables, and it does
 * not set wplit_db_version, which is set when the 1.x data has been migrated.
 */
final class Installer {

	public const OPTION = 'wplit_schema_version';

	/**
	 * Creates or upgrades the tables if the stored schema version is out of date.
	 */
	public static function maybe_install(): void {
		if ( Schema::VERSION === get_option( self::OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates or upgrades the tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION, Schema::VERSION );
	}

	/**
	 * Adds this plugin's tables to the ones WordPress drops when a site of a network is deleted. Without it they stay behind,
	 * with every license and customer email of that site.
	 *
	 * @param string[] $tables  Tables WordPress is about to drop.
	 * @param int      $site_id ID of the site being deleted.
	 * @return string[]
	 */
	public static function drop_with_site( array $tables, int $site_id ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $site_id );

		foreach ( Schema::table_names() as $name ) {
			$tables[] = Schema::table( $prefix, $name );
		}

		return $tables;
	}
}
