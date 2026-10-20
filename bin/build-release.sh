#!/usr/bin/env bash
#
# Builds the release zip: only what runs. Everything .distignore lists (tests, docs, development tools, Git files) is left out.
#
#   bin/build-release.sh            builds build/wplicense-it-<version>.zip and checks it
#   bin/build-release.sh --check    also builds it a second time and compares, to prove the zip is reproducible
#
# The zip has one folder, wplicense-it/, as WordPress expects. It is the same for the same commit: files are written in a fixed
# order, with fixed permissions and the time of the commit, so two builds give the same bytes.
# Needs: bash, rsync, zip, unzip, php. Exits with 1 if the zip is missing something or holds something it should not.

set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="wplicense-it"
OUT="$DIR/build"
VERSION="$(sed -n 's/^[[:space:]]*Version:[[:space:]]*//p' "$DIR/$SLUG.php" | head -1 | tr -d '\r')"
[ -n "$VERSION" ] || { echo "No Version header in $SLUG.php"; exit 1; }
EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$DIR" log -1 --format=%ct 2>/dev/null || date +%s)}"

# build NAME: stages the files and writes build/NAME.zip.
build() {
	local zip="$OUT/$1.zip" stage="$OUT/stage-$1"
	rm -rf "$stage" "$zip"
	mkdir -p "$stage/$SLUG"
	rsync -a --exclude-from="$DIR/.distignore" --exclude=/build "$DIR"/ "$stage/$SLUG"/
	find "$stage" -type d -exec chmod 755 {} +
	find "$stage" -type f -exec chmod 644 {} +
	find "$stage" -exec touch -h -d "@$EPOCH" {} +
	(cd "$stage" && find "$SLUG" | LC_ALL=C sort | zip -X -q -@ "$zip")
	rm -rf "$stage"
}

mkdir -p "$OUT"
build "$SLUG-$VERSION"
ZIP="$OUT/$SLUG-$VERSION.zip"
FAIL=0
fail() { echo "FAIL: $*"; FAIL=1; }

unzip -tq "$ZIP" >/dev/null || fail "the zip is damaged"
LIST="$(unzip -Z1 "$ZIP")"

# One folder at the top, named like the plugin.
if echo "$LIST" | grep -qv "^$SLUG/"; then fail "files outside the $SLUG/ folder: $(echo "$LIST" | grep -v "^$SLUG/" | head -3 | tr '\n' ' ')"; fi

# What must be in it.
for file in "$SLUG.php" readme.txt license.txt uninstall.php src/Plugin.php src/Autoloader.php admin/wplicense-it-product-post.php includes/wplicense-it-api.php sdk/wplicense-it-client.php sdk/src/Updater.php languages/wplicense-it.pot; do
	echo "$LIST" | grep -qx "$SLUG/$file" || fail "missing from the zip: $file"
done

# What must not be.
BAD="$(echo "$LIST" | sed "s#^$SLUG/##" | grep -E '^(tests|docs|bin|vendor|node_modules|build|\.git|\.github)(/|$)|^(composer\.(json|lock)|phpcs.*|phpstan.*|phpunit.*|README\.md|LICENSE|screenshot.*|\.[^/]+)$|(^|/)\.(DS_Store|phpunit\.result\.cache)$|\.(log|cache|orig|rej|swp)$|/phpcs\.xml' || true)"
[ -z "$BAD" ] || fail "development files in the zip: $(echo "$BAD" | head -5 | tr '\n' ' ')"

# Every PHP file must at least parse (the SDK targets PHP 7.4, but its syntax is checked here with the PHP that is installed).
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP" -d "$TMP"
while IFS= read -r file; do
	php -l "$file" >/dev/null 2>"$TMP/lint.err" || fail "PHP syntax error in ${file#"$TMP"/}: $(head -1 "$TMP/lint.err")"
done < <(find "$TMP/$SLUG" -name '*.php' | LC_ALL=C sort)

# The main file's version, the readme's stable tag and the constant must agree (Plugin Check fails otherwise).
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$TMP/$SLUG/readme.txt" | head -1 | tr -d '\r')"
CONST="$(sed -n "s/^define( 'WPLICENSE_IT_VERSION', '\(.*\)' );/\1/p" "$TMP/$SLUG/$SLUG.php" | head -1)"
[ "$STABLE" = "$VERSION" ] || fail "readme.txt Stable tag ($STABLE) is not the plugin version ($VERSION)"
[ "$CONST" = "$VERSION" ] || fail "WPLICENSE_IT_VERSION ($CONST) is not the plugin version ($VERSION)"

# "Tested up to" may only claim the WordPress version the browser tests run on (tests/env/setup.sh), as major.minor.
TESTED="$(sed -n 's/^Tested up to:[[:space:]]*//p' "$TMP/$SLUG/readme.txt" | head -1 | tr -d '\r')"
WP_TAG="$(sed -n 's/^WP_TAG="${WPLIT_WP_TAG:-\(.*\)}"$/\1/p' "$DIR/tests/env/setup.sh" | head -1)"
if [ -n "$WP_TAG" ]; then
	[ "$TESTED" = "$(echo "$WP_TAG" | cut -d. -f1,2)" ] || fail "readme.txt says Tested up to $TESTED, but the browser tests run on WordPress $WP_TAG"
fi

FILES="$(echo "$LIST" | grep -vc '/$' || true)"
echo "Built $ZIP"
echo "  version $VERSION, $FILES files, $(du -h "$ZIP" | cut -f1), sha256 $(sha256sum "$ZIP" | cut -c1-16)"

if [ "${1:-}" = "--check" ]; then
	build "$SLUG-$VERSION-again"
	if [ "$(sha256sum < "$ZIP")" = "$(sha256sum < "$OUT/$SLUG-$VERSION-again.zip")" ]; then
		echo "  a second build is identical (reproducible)"
	else
		fail "a second build is not identical, so the zip is not reproducible"
	fi
	rm -f "$OUT/$SLUG-$VERSION-again.zip"
fi

if [ "$FAIL" -ne 0 ]; then echo "The release zip is not good."; exit 1; fi
echo "  checks passed"
