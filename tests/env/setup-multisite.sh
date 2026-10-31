#!/usr/bin/env bash
#
# Builds a WordPress multisite network (subdirectory type) next to the test sites made by setup.sh, with this plugin
# symlinked in and three sites: the main site and /alpha/ and /beta/. It has its own database and is not served over HTTP:
# WordPress refuses to run a network on a port other than 80 or 443, so the checks in tests/env/multisite-check.php load each
# site from the command line, with the site's host and path set, which runs the same code a web request would.
#
# Run setup.sh first (it installs MariaDB and downloads WordPress). Running this again does nothing that is done.
#
set -euo pipefail

ROOT="${WPLIT_ENV_ROOT:-/opt/wp-test}"
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="$ROOT/wordpress"
NET="$ROOT/network"
SECRETS="$ROOT/secrets.env"

say() { printf '\n== %s\n' "$*"; }

[ -f "$SECRETS" ] || { echo "Run tests/env/setup.sh first." >&2; exit 1; }
# shellcheck disable=SC1090
. "$SECRETS"

say "Database"
mariadb -e "CREATE DATABASE IF NOT EXISTS wp_network CHARACTER SET utf8mb4; GRANT ALL ON wp_network.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"

say "WordPress files"
if [ ! -f "$NET/wp-load.php" ]; then
	cp -al "$SITE" "$NET"
	rm -f "$NET/wp-config.php" "$NET/wp-content/plugins/wplicense-it" "$NET/wp-content/debug.log"
	rm -rf "$NET/wp-content/uploads" "$NET/wp-content/mu-plugins"
fi
ln -sfn "$PLUGIN_DIR" "$NET/wp-content/plugins/wplicense-it"

say "wp-config.php"
if [ ! -f "$NET/wp-config.php" ]; then
	salts="$(php -r 'foreach (["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k) { echo "define( \"$k\", \"" . bin2hex(random_bytes(32)) . "\" );\n"; }')"
	cat > "$NET/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'wp_network' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', '${DB_PASSWORD}' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
${salts}
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
if ( file_exists( __DIR__ . '/network-constants.php' ) ) { require __DIR__ . '/network-constants.php'; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
fi

say "Install the main site and turn it into a network"
if [ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_network' AND table_name='wp_blogs'")" = "0" ]; then
	rm -f "$NET/network-constants.php"
	# Every command line request needs a host, because a network is looked up by it.
	NET="$NET" ADMIN_PASSWORD="$ADMIN_PASSWORD" php -r '
		$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/";
		define( "WP_INSTALLING", true );
		require getenv( "NET" ) . "/wp-load.php";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		wp_install( "WPLit Network", "admin", "admin@example.test", true, "", getenv( "ADMIN_PASSWORD" ) );
	' 2>&1 | tail -1
	NET="$NET" php -r '
		$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/";
		define( "WP_ALLOW_MULTISITE", true );
		define( "WP_INSTALLING_NETWORK", true );
		require getenv( "NET" ) . "/wp-load.php";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		require_once ABSPATH . "wp-admin/includes/network.php";
		// A site that is not a network yet does not know the names of the network tables. Say them, then create them.
		global $wpdb;
		foreach ( $wpdb->ms_global_tables as $table ) { $wpdb->$table = $wpdb->base_prefix . $table; }
		install_network();
		$r = populate_network( 1, "127.0.0.1", "admin@example.test", "WPLit Network", "/", false );
		if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() . "\n" ); exit( 1 ); }
		echo "network made\n";
	' 2>&1 | tail -2
	cat > "$NET/network-constants.php" <<'PHP'
<?php
define( 'WP_ALLOW_MULTISITE', true );
define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', false );
define( 'DOMAIN_CURRENT_SITE', '127.0.0.1' );
define( 'PATH_CURRENT_SITE', '/' );
define( 'SITE_ID_CURRENT_SITE', 1 );
define( 'BLOG_ID_CURRENT_SITE', 1 );
PHP
fi

say "Two more sites"
NET="$NET" php -r '
	$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/";
	require getenv( "NET" ) . "/wp-load.php";
	foreach ( array( "alpha", "beta" ) as $slug ) {
		if ( get_id_from_blogname( $slug ) ) { continue; }
		$id = wpmu_create_blog( "127.0.0.1", "/" . $slug . "/", "WPLit " . ucfirst( $slug ), 1, array( "public" => 1 ), 1 );
		echo is_wp_error( $id ) ? "could not make {$slug}: " . $id->get_error_message() . "\n" : "made {$slug} as site {$id}\n";
	}
' 2>&1 | tail -3

say "Ready"
echo "Network: ${NET}  (database wp_network)  sites: / /alpha/ /beta/"
