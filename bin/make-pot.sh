#!/usr/bin/env bash
#
# Writes languages/wplicense-it.pot, the template translators start from, with WP-CLI's "wp i18n make-pot", and
# sdk/languages/wplicense-it-client.pot for the client SDK's texts (text domain wplicense-it-client).
#
#   bin/make-pot.sh           # write the file
#   bin/make-pot.sh --check   # fail if the file is not up to date (CI runs this)
#
# The SDK has its own template: it runs inside sellers' plugins, under its own text domain. Tests, tools and docs are left
# out of both.
# A fixed version of WP-CLI is used (versions write the file slightly differently, for example whether strings with
# placeholders are flagged php-format), and downloaded to $WPLIT_ENV_ROOT (default /opt/wp-test) if it is not there yet.

set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="${WPLIT_ENV_ROOT:-/opt/wp-test}"
WPCLI_VERSION="2.12.0"
WPCLI="$ROOT/wp-cli-$WPCLI_VERSION.phar"
POT="$DIR/languages/wplicense-it.pot"

if [ ! -f "$WPCLI" ]; then
	mkdir -p "$ROOT"
	curl -fsSL -o "$WPCLI.part" "https://github.com/wp-cli/wp-cli/releases/download/v$WPCLI_VERSION/wp-cli-$WPCLI_VERSION.phar"
	mv "$WPCLI.part" "$WPCLI"
fi

make() {
	php "$WPCLI" --allow-root i18n make-pot "$DIR" "$1" \
		--slug=wplicense-it --domain=wplicense-it \
		--exclude=tests,vendor,node_modules,build,bin,docs,sdk \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/devlloplugins/wplicense-it/issues"}' \
		--skip-audit >/dev/null
}

make_sdk() {
	php "$WPCLI" --allow-root i18n make-pot "$DIR/sdk" "$1" \
		--slug=wplicense-it-client --domain=wplicense-it-client --package-name="WPLicense It client SDK" \
		--exclude=languages \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/devlloplugins/wplicense-it/issues"}' \
		--skip-audit >/dev/null
}

# The creation date and the source line references change without any text changing, so they are left out of the comparison.
strip() { grep -v '^"POT-Creation-Date:' "$1" | grep -v '^"X-Generator:' | grep -v '^#: '; }

SDK_POT="$DIR/sdk/languages/wplicense-it-client.pot"

if [ "${1:-}" = "--check" ]; then
	TMP="$(mktemp)"
	TMP_SDK="$(mktemp)"
	trap 'rm -f "$TMP" "$TMP_SDK"' EXIT
	make "$TMP"
	make_sdk "$TMP_SDK"
	status=0
	for pair in "$POT:$TMP" "$SDK_POT:$TMP_SDK"; do
		file="${pair%%:*}"
		fresh="${pair#*:}"
		name="${file#"$DIR"/}"
		if [ ! -f "$file" ] || ! diff <(strip "$file") <(strip "$fresh") >/dev/null; then
			echo "$name is out of date. Run bin/make-pot.sh and commit the result. Differences:"
			diff <(strip "$file" 2>/dev/null || true) <(strip "$fresh") | head -40
			status=1
		else
			echo "$name is up to date ($(grep -c '^msgid ' "$file") strings)."
		fi
	done
	exit "$status"
fi

mkdir -p "$DIR/languages" "$DIR/sdk/languages"
make "$POT"
make_sdk "$SDK_POT"
echo "Wrote languages/wplicense-it.pot ($(grep -c '^msgid ' "$POT") strings) and sdk/languages/wplicense-it-client.pot ($(grep -c '^msgid ' "$SDK_POT") strings)."
