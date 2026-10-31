#!/usr/bin/env bash
#
# Runs every browser test in order against the scripted test site, keeps going after a failure, and prints a summary.
# The site must be up (tests/env/setup.sh and tests/env/setup-woocommerce.sh). Needs Node with Playwright and Chromium:
# set NODE_PATH to the folder that holds the playwright package if it is installed globally.
#
#   tests/e2e/run-all.sh            # all tests
#   tests/e2e/run-all.sh 05 06      # only the tests whose file names start with these numbers

set -uo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
FAILED=()
RAN=0

if [ "$#" -gt 0 ]; then
	FILES=()
	for n in "$@"; do FILES+=("$DIR"/"$n"-*.cjs); done
else
	FILES=("$DIR"/[0-9][0-9]-*.cjs)
fi

for file in "${FILES[@]}"; do
	[ -f "$file" ] || { echo "No such test: $file"; FAILED+=("$(basename "$file") (missing)"); continue; }
	name="$(basename "$file")"
	echo
	echo "=== $name"
	RAN=$((RAN + 1))
	if ! timeout 600 node "$file"; then
		FAILED+=("$name")
	fi
done

echo
echo "=== Summary: ran $RAN, failed ${#FAILED[@]}"
for name in "${FAILED[@]:-}"; do [ -n "$name" ] && echo "  FAILED: $name"; done
[ "${#FAILED[@]}" -eq 0 ]
