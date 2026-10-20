#!/usr/bin/env bash
#
# Builds a local WordPress site for trying WPLicense It, and starts it. Safe to run again: it skips what is done.
#
#   tests/env/setup.sh
#
# Needs: Debian or Ubuntu with apt, PHP 8 with mysqli, git, and network access to the package archive and to GitHub
# (read-only git clones). Everything lives outside the repository, in $WPLIT_ENV_ROOT (default /opt/wp-test), and the
# plugin is symlinked in, so the site always runs the code in this checkout.
#
# Result: http://127.0.0.1:8080/ with an admin user. The admin password and the database password are written to
# $WPLIT_ENV_ROOT/secrets.env, never to the repository.

set -euo pipefail

ROOT="${WPLIT_ENV_ROOT:-/opt/wp-test}"
WP_TAG="${WPLIT_WP_TAG:-6.7.1}"
WPCLI_VERSION="2.12.0"
PORT="${WPLIT_PORT:-8080}"
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="$ROOT/wordpress"
SECRETS="$ROOT/secrets.env"
URL="http://127.0.0.1:${PORT}"
CLIENT_PORT="${WPLIT_CLIENT_PORT:-8081}"
CLIENT="$ROOT/client"

say() { printf '\n== %s\n' "$*"; }

mkdir -p "$ROOT"

say "Secrets"
if [ ! -f "$SECRETS" ]; then
	umask 077
	{
		echo "DB_PASSWORD=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
		echo "ADMIN_PASSWORD=$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n')"
	} > "$SECRETS"
fi
# shellcheck disable=SC1090
. "$SECRETS"

say "MariaDB"
if ! command -v mariadbd >/dev/null 2>&1; then
	export DEBIAN_FRONTEND=noninteractive
	apt-get update -qq >/dev/null
	apt-get install -y -qq mariadb-server mariadb-client >/dev/null
fi
if ! mariadb -e 'select 1' >/dev/null 2>&1; then
	mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
	(nohup mysqld_safe --user=mysql > "$ROOT/mysqld.log" 2>&1 &)
	for _ in $(seq 1 40); do mariadb -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
fi
mariadb -e "CREATE DATABASE IF NOT EXISTS wp_test CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'wp'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL ON wp_test.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"

say "WordPress ${WP_TAG}"
if [ ! -f "$SITE/wp-load.php" ]; then
	GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 --branch "$WP_TAG" https://github.com/WordPress/wordpress "$SITE"
fi

# write_config DIR DATABASE URL
write_config() {
	local dir="$1" db="$2" url="$3"
	[ -f "$dir/wp-config.php" ] && return 0
	local salts
	salts="$(php -r 'foreach (["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k) { echo "define( \"$k\", \"" . bin2hex(random_bytes(32)) . "\" );\n"; }')"
	cat > "$dir/wp-config.php" <<PHP
<?php
define( 'DB_NAME', '${db}' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', '${DB_PASSWORD}' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
${salts}
\$table_prefix = 'wp_';
define( 'WP_HOME', '${url}' );
define( 'WP_SITEURL', '${url}' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
}

# install_site DIR DATABASE TITLE
install_site() {
	local dir="$1" db="$2" title="$3"
	# Ask the database: loading WordPress on an empty database exits cleanly, so its own check cannot be relied on here.
	[ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}' AND table_name='wp_options'")" != "0" ] && return 0
	ADMIN_PASSWORD="$ADMIN_PASSWORD" SITE_TITLE="$title" php -r '
		define( "WP_INSTALLING", true );
		require "'"$dir"'/wp-load.php";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		$result = wp_install( getenv( "SITE_TITLE" ), "admin", "admin@example.test", true, "", getenv( "ADMIN_PASSWORD" ) );
		echo "installed " . getenv( "SITE_TITLE" ) . ", admin id " . $result["user_id"] . "\n";
	' 2>&1 | tail -2
	ADMIN_PASSWORD="$ADMIN_PASSWORD" php -r '
		require "'"$dir"'/wp-load.php";
		update_option( "permalink_structure", "/%postname%/" );
	'
}

# serve DIR PORT NAME: pretty permalinks for PHP's built-in server, with several workers because the site calls
# itself (the test purchase and the client code do), which one worker cannot serve.
serve() {
	local dir="$1" port="$2" name="$3"
	cat > "$ROOT/router-${name}.php" <<PHP
<?php
// Real files and folders are served as they are, everything else goes to WordPress.
\$path = parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== \$path && file_exists( '${dir}' . \$path ) ) {
	return false;
}
// A missing static file is a 404, as on a real web server, and not a WordPress page that pretends to be the file.
if ( preg_match( '/\.(js|css|map|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot)\$/i', \$path ) ) {
	http_response_code( 404 );
	return true;
}
\$_SERVER['SCRIPT_NAME']     = '/index.php';
\$_SERVER['SCRIPT_FILENAME'] = '${dir}/index.php';
chdir( '${dir}' );
require '${dir}/index.php';
PHP
	if ! curl -s -o /dev/null -m 3 "http://127.0.0.1:${port}/wp-login.php"; then
		# The whole group is detached with its output in a file, so a caller that reads this script's output is not kept waiting.
		(cd "$dir" && PHP_CLI_SERVER_WORKERS=6 setsid nohup php -S "127.0.0.1:${port}" -t "$dir" "$ROOT/router-${name}.php") > "$ROOT/server-${name}.log" 2>&1 < /dev/null &
		for _ in $(seq 1 20); do curl -s -o /dev/null -m 2 "http://127.0.0.1:${port}/wp-login.php" && break; sleep 1; done
	fi
}

say "WP-CLI ${WPCLI_VERSION}"
# One fixed version for the tests, Plugin Check and bin/make-pot.sh (versions write translation files differently).
if [ ! -f "$ROOT/wp-cli-${WPCLI_VERSION}.phar" ]; then
	curl -fsSL -o "$ROOT/wp-cli-${WPCLI_VERSION}.phar.part" "https://github.com/wp-cli/wp-cli/releases/download/v${WPCLI_VERSION}/wp-cli-${WPCLI_VERSION}.phar"
	mv "$ROOT/wp-cli-${WPCLI_VERSION}.phar.part" "$ROOT/wp-cli-${WPCLI_VERSION}.phar"
fi

say "wp-config.php and install (license server)"
write_config "$SITE" wp_test "$URL"
install_site "$SITE" wp_test "WPLit Test"

say "Plugin"
mkdir -p "$SITE/wp-content/plugins"
ln -sfn "$PLUGIN_DIR" "$SITE/wp-content/plugins/wplicense-it"
# A fresh site does not have the plugin switched on. Activating it also creates its tables and settings.
php -d memory_limit=512M -r '
	define( "WP_ADMIN", true );
	require "'"$SITE"'/wp-load.php";
	require_once ABSPATH . "wp-admin/includes/plugin.php";
	if ( ! is_plugin_active( "wplicense-it/wplicense-it.php" ) ) {
		$result = activate_plugin( "wplicense-it/wplicense-it.php" );
		echo is_wp_error( $result ) ? "ERROR: " . $result->get_error_message() . "\n" : "WPLicense It activated\n";
	} else {
		echo "WPLicense It already active\n";
	}
'

say "Web server (license server)"
serve "$SITE" "$PORT" server

# A second site plays a customer's site, because a plugin that updates itself from the license server must not be on
# the license server's own site: WordPress puts a site into maintenance mode during an update and it could not answer.
say "Customer site (port ${CLIENT_PORT})"
CLIENT_URL="http://127.0.0.1:${CLIENT_PORT}"
mariadb -e "CREATE DATABASE IF NOT EXISTS wp_client CHARACTER SET utf8mb4; GRANT ALL ON wp_client.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"
if [ ! -f "$CLIENT/wp-load.php" ]; then
	cp -al "$SITE" "$CLIENT"
	rm -f "$CLIENT/wp-config.php" "$CLIENT/wp-content/plugins/wplicense-it" "$CLIENT/wp-content/debug.log"
fi
write_config "$CLIENT" wp_client "$CLIENT_URL"
install_site "$CLIENT" wp_client "WPLit Customer Site"
# Test environment only: the customer site writes down the web requests it makes and when WordPress builds its update list,
# so a failure of the update step can be explained afterwards (tests/e2e/04-sample-plugin.cjs prints it).
mkdir -p "$CLIENT/wp-content/mu-plugins"
cat > "$CLIENT/wp-content/mu-plugins/wplit-test-trace.php" <<'PHP'
<?php
/**
 * Test environment only.
 *
 * @package Devllo\WPLicenseIt
 */

$wplit_trace = static function ( $line ) {
	file_put_contents( WP_CONTENT_DIR . '/http-trace.log', gmdate( 'H:i:s' ) . ' ' . $line . "\n", FILE_APPEND );
};

add_action(
	'http_api_debug',
	static function ( $response, $context, $class, $args, $url ) use ( $wplit_trace ) {
		$wplit_trace( 'HTTP ' . preg_replace( '#(license_key|token)=[^&]+#', '$1=x', (string) $url ) . ' -> ' . ( is_wp_error( $response ) ? 'ERROR ' . $response->get_error_message() : wp_remote_retrieve_response_code( $response ) . ' ' . preg_replace( '/\\s+/', ' ', substr( (string) wp_remote_retrieve_body( $response ), 0, 80 ) ) ) );
	},
	10,
	5
);

add_filter(
	'pre_set_site_transient_update_plugins',
	static function ( $value ) use ( $wplit_trace ) {
		$wplit_trace( 'update list built on ' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'cli' ) . ' user_can_update=' . ( function_exists( 'current_user_can' ) && current_user_can( 'update_plugins' ) ? 'yes' : 'no' ) );

		return $value;
	},
	1
);
PHP

serve "$CLIENT" "$CLIENT_PORT" client

say "Ready"
echo "Site:   ${URL}/"
echo "Admin:  ${URL}/wp-admin/   user: admin   password: in ${SECRETS}"
echo "Log:    ${SITE}/wp-content/debug.log"
echo "Customer site: ${CLIENT_URL}/  (same admin user and password)   Log: ${CLIENT}/wp-content/debug.log"
