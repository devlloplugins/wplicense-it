<?php
/**
 * Database schema.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Database;

/**
 * SQL for the 2.0 tables. See docs/SCHEMA.md.
 *
 * The statements follow dbDelta() formatting rules: one column per line, two
 * spaces after PRIMARY KEY, and KEY (not INDEX).
 */
final class Schema {

	/**
	 * Schema version, stored in the wplit_schema_version option.
	 */
	public const VERSION = '2.0.0';

	/**
	 * Table names, without prefix, in creation order.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array( 'licenses', 'activations', 'license_orders', 'license_events' );
	}

	/**
	 * Full table name.
	 *
	 * @param string $prefix Database table prefix ($wpdb->prefix).
	 * @param string $name   One of table_names().
	 */
	public static function table( string $prefix, string $name ): string {
		return $prefix . 'wplit_' . $name;
	}

	/**
	 * CREATE TABLE statements, keyed by table name.
	 *
	 * @param string $prefix          Database table prefix ($wpdb->prefix).
	 * @param string $charset_collate Charset clause ($wpdb->get_charset_collate()).
	 * @return array<string, string>
	 */
	public static function sql( string $prefix, string $charset_collate ): array {
		$licenses    = self::table( $prefix, 'licenses' );
		$activations = self::table( $prefix, 'activations' );
		$orders      = self::table( $prefix, 'license_orders' );
		$events      = self::table( $prefix, 'license_events' );

		return array(
			'licenses'       => "CREATE TABLE {$licenses} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  license_key varchar(64) NOT NULL,
  email varchar(190) NOT NULL,
  status varchar(20) NOT NULL,
  activation_limit int(10) unsigned NOT NULL DEFAULT 1,
  expires_at datetime DEFAULT NULL,
  legacy_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_key (license_key),
  UNIQUE KEY legacy_id (legacy_id),
  KEY product_status (product_id,status),
  KEY user_id (user_id),
  KEY email (email),
  KEY status_expires (status,expires_at)
) {$charset_collate};",
			'activations'    => "CREATE TABLE {$activations} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL,
  site varchar(190) NOT NULL,
  is_local tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL,
  product_version varchar(32) DEFAULT NULL,
  activated_at datetime NOT NULL,
  deactivated_at datetime DEFAULT NULL,
  last_checked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_site (license_id,site),
  KEY site (site)
) {$charset_collate};",
			'license_orders' => "CREATE TABLE {$orders} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(20) NOT NULL,
  external_id varchar(100) DEFAULT NULL,
  order_number varchar(48) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  product_id bigint(20) unsigned NOT NULL,
  currency char(3) NOT NULL,
  total_minor bigint(20) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL,
  legacy_id bigint(20) unsigned DEFAULT NULL,
  legacy_billing longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_number (order_number),
  UNIQUE KEY source_external (source,external_id),
  UNIQUE KEY legacy_id (legacy_id),
  KEY user_id (user_id),
  KEY product_id (product_id)
) {$charset_collate};",
			'license_events' => "CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL,
  type varchar(30) NOT NULL,
  data longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY license_created (license_id,created_at)
) {$charset_collate};",
		);
	}
}
