#!/usr/bin/env bash
#
# Adds WooCommerce to the license server site made by tests/env/setup.sh. Safe to run again.
#
#   tests/env/setup.sh && tests/env/setup-woocommerce.sh
#
# WooCommerce comes from a read-only git clone of its repository (only plugins/woocommerce), because the release zip
# is on wordpress.org, which a sandbox may not reach. Its PHP dependencies come from composer. Its JavaScript is not
# built, so WooCommerce's own admin pages are mostly blank and nag about it; the PHP side, which this plugin uses, works.
#
# Also installs a small must-use plugin that keeps outgoing email in a file instead of sending it, so tests can read it.

set -euo pipefail

ROOT="${WPLIT_ENV_ROOT:-/opt/wp-test}"
WOO_TAG="${WPLIT_WOO_TAG:-9.4.2}"
SITE="$ROOT/wordpress"
SRC="$ROOT/woocommerce-src"
PLUGIN="$SRC/plugins/woocommerce"

say() { printf '\n== %s\n' "$*"; }

[ -f "$SITE/wp-load.php" ] || { echo "Run tests/env/setup.sh first." >&2; exit 1; }

say "WooCommerce ${WOO_TAG} source"
if [ ! -d "$SRC/.git" ]; then
	GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 --branch "$WOO_TAG" --filter=blob:none --sparse https://github.com/woocommerce/woocommerce "$SRC"
fi
# plugins/woocommerce is the plugin; packages/php holds the local composer packages it depends on.
(cd "$SRC" && GIT_LFS_SKIP_SMUDGE=1 git sparse-checkout set plugins/woocommerce packages/php)

say "Composer dependencies"
if [ ! -f "$PLUGIN/vendor/autoload.php" ]; then
	(cd "$PLUGIN" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 composer install --no-dev --prefer-source --no-scripts --no-progress)
fi

say "Generated file the build normally makes"
[ -f "$PLUGIN/includes/react-admin/feature-config.php" ] || php "$PLUGIN/bin/generate-feature-config.php"

say "Link and activate"
ln -sfn "$PLUGIN" "$SITE/wp-content/plugins/woocommerce"
php -d memory_limit=512M -r '
	define( "WP_ADMIN", true );
	require "'"$SITE"'/wp-load.php";
	require_once ABSPATH . "wp-admin/includes/plugin.php";
	if ( ! is_plugin_active( "woocommerce/woocommerce.php" ) ) {
		$result = activate_plugin( "woocommerce/woocommerce.php" );
		echo is_wp_error( $result ) ? "ERROR: " . $result->get_error_message() . "\n" : "WooCommerce activated\n";
	} else {
		echo "WooCommerce already active\n";
	}
	update_option( "woocommerce_currency", "USD" );
	update_option( "woocommerce_default_country", "US:CA" );
	update_option( "woocommerce_cheque_settings", array( "enabled" => "yes", "title" => "Check payments" ) );
	update_option( "woocommerce_enable_signup_and_login_from_checkout", "yes" );
	update_option( "woocommerce_enable_myaccount_registration", "yes" );
	flush_rewrite_rules();
'

say "Mail capture"
mkdir -p "$SITE/wp-content/mu-plugins"
cat > "$SITE/wp-content/mu-plugins/wplit-test-mail.php" <<'PHP'
<?php
/**
 * Test environment only: keeps outgoing email in wp-content/mail-log.jsonl instead of sending it. It runs last, and an
 * email that something else already stopped (the demo store does) is not kept, as a real mailer would not send it.
 */
add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}
		file_put_contents( WP_CONTENT_DIR . '/mail-log.jsonl', wp_json_encode( $atts ) . "\n", FILE_APPEND );
		return true;
	},
	PHP_INT_MAX,
	2
);
PHP

say "Ready"
echo "WooCommerce ${WOO_TAG} is active on the license server."
