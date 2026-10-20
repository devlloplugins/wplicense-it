# Building a release

`bin/build-release.sh` makes the zip that is released: **only what runs**.

```
bin/build-release.sh            # build/wplicense-it-<version>.zip, then checks it
bin/build-release.sh --check    # also builds it twice and compares the two
```

Every push builds it in CI (job **Release zip**) and keeps the zip as a downloadable artifact, so a release candidate exists for every
commit.

## What is in the zip, and what is not

The zip has one folder, `wplicense-it/`, with the plugin file, `readme.txt`, `license.txt`, `uninstall.php` and the `src`, `admin`,
`includes` and `sdk` folders (the client SDK is part of the plugin: the **Download SDK** button builds its zip from it).

`.distignore` lists what is left out: tests, `docs`, `bin`, development tools and settings (`composer.*`, `phpcs`, `phpstan`,
`phpunit`), Git and GitHub files, `README.md`, the duplicate `LICENSE`, screenshots, `vendor` and `node_modules`. The plugin has no
third-party code at runtime, so nothing from `vendor` is needed.

## What the script checks

It stops with an error if:

- the zip is damaged, or has files outside the `wplicense-it/` folder;
- a required file is missing (the plugin file, readme, licence, `uninstall.php`, the autoloader, the SDK and its updater, and the
  two legacy files);
- a development file is in the zip (tests, docs, tools, hidden files, caches, logs);
- any PHP file in the zip does not parse;
- the plugin header's `Version`, the `WPLICENSE_IT_VERSION` constant and the readme's `Stable tag` differ;
- the readme's `Tested up to` is not the WordPress version the browser tests run on (`tests/env/setup.sh`, as major.minor).

With `--check` it also builds the zip a second time and compares: the files are written in a fixed order, with fixed permissions and
the time of the commit, so the same commit always gives the same zip (same sha256). That makes a release zip something anyone can
rebuild and compare.

The whole browser test suite was also run against the plugin unpacked from the zip, with the repository's own files not in the way:
all 19 test files passed, so nothing the plugin needs was left out.

## Cutting a release

1. Change `Version` in `wplicense-it.php`, `WPLICENSE_IT_VERSION` in the same file, and `Stable tag` in `readme.txt` to the release
   number (for example `2.0.0`). The script fails if they differ.
2. If the browser tests now run on a newer WordPress (`WPLIT_WP_TAG` in `tests/env/setup.sh`), raise `Tested up to` in `readme.txt` to match. Do not raise it without testing.
3. Add the changelog entry to `readme.txt`.
4. Run `bin/make-pot.sh` if any text changed (CI fails while `languages/wplicense-it.pot` is out of date), then
   `bin/build-release.sh --check` and `tests/env/plugin-check.sh`.
5. Tag the commit and publish the zip. Rebuilding from the tag gives the same bytes.

See `WORDPRESS-ORG.md` for the Plugin Check results and why 2.0 is not hosted on wordpress.org.
