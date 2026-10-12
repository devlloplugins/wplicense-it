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
PORT="${WPLIT_PORT:-8080}"
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SITE="$ROOT/wordpress"
SECRETS="$ROOT/secrets.env"
URL="http://127.0.0.1:${PORT}"

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

if [ ! -f "$SITE/wp-config.php" ]; then
	say "wp-config.php"
	SALTS="$(php -r 'foreach (["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k) { echo "define( \"$k\", \"" . bin2hex(random_bytes(32)) . "\" );\n"; }')"
	cat > "$SITE/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'wp_test' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', '${DB_PASSWORD}' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
${SALTS}
\$table_prefix = 'wp_';
define( 'WP_HOME', '${URL}' );
define( 'WP_SITEURL', '${URL}' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
fi

say "Install"
# Ask the database: loading WordPress on an empty database exits cleanly, so its own check cannot be relied on here.
if [ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_test' AND table_name='wp_options'")" = "0" ]; then
	ADMIN_PASSWORD="$ADMIN_PASSWORD" php -r '
		define( "WP_INSTALLING", true );
		require "'"$SITE"'/wp-load.php";
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		$result = wp_install( "WPLit Test", "admin", "admin@example.test", true, "", getenv( "ADMIN_PASSWORD" ) );
		echo "installed, admin id " . $result["user_id"] . "\n";
	' 2>&1 | tail -2
	ADMIN_PASSWORD="$ADMIN_PASSWORD" php -r '
		require "'"$SITE"'/wp-load.php";
		update_option( "permalink_structure", "/%postname%/" );
		update_option( "blogdescription", "Local test site" );
	'
fi

say "Plugin"
mkdir -p "$SITE/wp-content/plugins"
ln -sfn "$PLUGIN_DIR" "$SITE/wp-content/plugins/wplicense-it"

say "Router and web server"
cat > "$ROOT/router.php" <<PHP
<?php
// Pretty permalinks for PHP's built-in server: real files and folders are served as they are, everything else goes to WordPress.
\$path = parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
\$file = '${SITE}' . \$path;
if ( '/' !== \$path && file_exists( \$file ) ) {
	return false;
}
\$_SERVER['SCRIPT_NAME']     = '/index.php';
\$_SERVER['SCRIPT_FILENAME'] = '${SITE}/index.php';
chdir( '${SITE}' );
require '${SITE}/index.php';
PHP

if ! curl -s -o /dev/null -m 3 "$URL/wp-login.php"; then
	# Several workers: the site calls itself (the test purchase and the client code do), which one worker cannot serve.
	# The whole group is detached with its output in a file, so a caller that reads this script's output is not kept waiting.
	(cd "$SITE" && PHP_CLI_SERVER_WORKERS=6 setsid nohup php -S "127.0.0.1:${PORT}" -t "$SITE" "$ROOT/router.php") > "$ROOT/server.log" 2>&1 < /dev/null &
	for _ in $(seq 1 20); do curl -s -o /dev/null -m 2 "$URL/wp-login.php" && break; sleep 1; done
fi

say "Ready"
echo "Site:   ${URL}/"
echo "Admin:  ${URL}/wp-admin/   user: admin   password: in ${SECRETS}"
echo "Log:    ${SITE}/wp-content/debug.log"
