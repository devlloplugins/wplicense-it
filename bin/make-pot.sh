#!/usr/bin/env bash
#
# Writes languages/wplicense-it.pot, the template translators start from, with WP-CLI's "wp i18n make-pot".
#
#   bin/make-pot.sh           # write the file
#   bin/make-pot.sh --check   # fail if the file is not up to date (CI runs this)
#
# The SDK is left out: it runs inside sellers' plugins, under their text domain. Tests, tools and docs are left out too.
# WP-CLI is downloaded to $WPLIT_ENV_ROOT (default /opt/wp-test) if it is not there yet.

set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="${WPLIT_ENV_ROOT:-/opt/wp-test}"
WPCLI="$ROOT/wp-cli.phar"
POT="$DIR/languages/wplicense-it.pot"

if [ ! -f "$WPCLI" ]; then
	mkdir -p "$ROOT"
	curl -fsSL -o "$WPCLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

make() {
	php "$WPCLI" --allow-root i18n make-pot "$DIR" "$1" \
		--slug=wplicense-it --domain=wplicense-it \
		--exclude=tests,vendor,node_modules,build,bin,docs,sdk \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/devlloplugins/wplicense-it/issues"}' \
		--skip-audit >/dev/null
}

# The creation date and the source line references change without any text changing, so they are left out of the comparison.
strip() { grep -v '^"POT-Creation-Date:' "$1" | grep -v '^"X-Generator:' | grep -v '^#: '; }

if [ "${1:-}" = "--check" ]; then
	TMP="$(mktemp)"
	trap 'rm -f "$TMP"' EXIT
	make "$TMP"
	if ! diff <(strip "$POT") <(strip "$TMP") >/dev/null; then
		echo "languages/wplicense-it.pot is out of date. Run bin/make-pot.sh and commit the result. Differences:"
		diff <(strip "$POT") <(strip "$TMP") | head -40
		exit 1
	fi
	echo "languages/wplicense-it.pot is up to date ($(grep -c '^msgid ' "$POT") strings)."
	exit 0
fi

mkdir -p "$DIR/languages"
make "$POT"
echo "Wrote languages/wplicense-it.pot ($(grep -c '^msgid ' "$POT") strings)."
