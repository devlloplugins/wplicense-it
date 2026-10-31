#!/usr/bin/env bash
#
# Runs WordPress.org's Plugin Check on the plugin as it would be released: the files that .distignore does not exclude, copied to a
# folder named like the plugin. Prints one line per finding and exits with 1 if there is an ERROR that is not in the ignore list.
#
#   tests/env/plugin-check.sh
#   WPLIT_PC_IGNORE="" tests/env/plugin-check.sh        # accept nothing (by default two are accepted, see docs/WORDPRESS-ORG.md)
#
# Needs the test site (tests/env/setup.sh), git, composer, rsync and python3. It downloads Plugin Check and WP-CLI into
# $WPLIT_ENV_ROOT (default /opt/wp-test), once.

set -uo pipefail

ROOT="${WPLIT_ENV_ROOT:-/opt/wp-test}"
SITE="$ROOT/wordpress"
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
IGNORE="${WPLIT_PC_IGNORE-plugin_updater_detected,outdated_tested_upto_header}"
DIST="$ROOT/dist/wplicense-it"

mkdir -p "$ROOT"

[ -d "$ROOT/plugin-check/includes" ] || git clone -q --depth 1 https://github.com/WordPress/plugin-check "$ROOT/plugin-check" || exit 2
[ -f "$ROOT/plugin-check/vendor/autoload.php" ] || (cd "$ROOT/plugin-check" && composer install --no-dev --no-interaction --quiet) || exit 2
WPCLI="$ROOT/wp-cli-2.12.0.phar"
if [ ! -f "$WPCLI" ]; then
	curl -sL -o "$WPCLI" https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar || exit 2
fi

rm -rf "$ROOT/dist" && mkdir -p "$DIST"
rsync -a --exclude-from="$PLUGIN_DIR/.distignore" "$PLUGIN_DIR"/ "$DIST"/

ln -sfn "$ROOT/plugin-check" "$SITE/wp-content/plugins/plugin-check"
cd "$SITE" || exit 2
php "$WPCLI" --allow-root plugin activate plugin-check >/dev/null 2>&1
php "$WPCLI" --allow-root plugin check "$DIST" --format=json > "$ROOT/plugin-check.out" 2> "$ROOT/plugin-check.err"
STATUS=$?
php "$WPCLI" --allow-root plugin deactivate plugin-check >/dev/null 2>&1

if [ "$STATUS" -ne 0 ] && [ ! -s "$ROOT/plugin-check.out" ]; then
	echo "Plugin Check did not run:"; head -c 600 "$ROOT/plugin-check.err"
	exit 2
fi

python3 - "$ROOT/plugin-check.out" "$IGNORE" <<'PY'
import json, sys
text = open(sys.argv[1]).read()
ignore = {c for c in sys.argv[2].split(',') if c}
current, errors, total = None, 0, 0
for line in text.splitlines():
    if line.startswith('FILE: '):
        current = line[6:].strip()
        continue
    line = line.strip()
    if not line.startswith('['):
        continue
    for item in json.loads(line):
        total += 1
        ignored = item['code'] in ignore
        if item['type'] == 'ERROR' and not ignored:
            errors += 1
        print('%-7s %-45s %s%s' % (item['type'], item['code'], current, '  (accepted)' if ignored else ''))
        print('        ' + item['message'][:160])
print('\n%d findings, %d errors that are not accepted' % (total, errors))
sys.exit(1 if errors else 0)
PY
