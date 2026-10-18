# Releasing on wordpress.org

`tests/env/plugin-check.sh` runs WordPress.org's own **Plugin Check** tool on the plugin as it would be released (the files that
`.distignore` does not exclude). Run it before every release. `WPLIT_PC_IGNORE=code1,code2` accepts error codes that were decided on.

## State at the compliance pass (2.0.0-dev)

Plugin Check first reported 15 findings. These were fixed:

| Finding | Fix |
|---|---|
| `readme.txt` said Requires 5.0, PHP 7.0, Stable tag 1.0.2 and had more than 5 tags | Rewritten for 2.0: matching headers, 5 tags, a one-line description, what the plugin does, an **External services** section, FAQ, changelog (newest first, with the 1.x entries kept) and an upgrade notice |
| Two legacy files had no direct-access guard | `if ( ! defined( 'ABSPATH' ) ) exit;` added |
| `Domain Path: /languages` pointed at a folder that does not exist | Header removed, and the `load_plugin_textdomain()` call with it: WordPress loads translations itself since 4.6 |
| Hidden file (`.phpunit.result.cache`) | `.distignore` keeps development files out of the release |

Still reported, and waiting for a decision:

| Finding | Why it is there | Options |
|---|---|---|
| **Error: plugin updater detected** in `sdk/src/Updater.php` (plus two warnings about update routines) | The client SDK that sellers copy into their own plugins and themes contains an updater. wordpress.org does not allow updater code in any plugin it hosts, even when it is not active on the site | Do not host 2.0 on wordpress.org; or release a wordpress.org build without the `sdk` folder and hand the SDK out from your own site |
| Warning: the name and slug contain the restricted term "wp" | The name is "WPLicense It" | The plugin already has this slug in the directory, so it was accepted before. Rename only if the review team asks |

## What was checked by hand

- **No calls to third parties.** The only request the plugin itself makes is the test purchase asking this site's own REST address
  (a loopback). The SDK contacts the seller's own license server from customers' sites, and the readme says so.
- **No obfuscated or minified code, no bundled libraries, no `eval`.** The plugin has no third-party code at runtime.
- **Uninstall** removes the plugin's tables and settings (see `uninstall.php`).
- **Privacy:** personal data export and erase tools, and suggested policy text (`PRIVACY.md`).
- Escaping, nonces and capability checks are enforced by the project's PHPCS rules (`composer lint`) on every change.

## Still to do by hand before submitting

- **Version:** the plugin header says `2.0.0-dev`, and `readme.txt` has the same Stable tag so they match. Change both to `2.0.0` for
  the release (Plugin Check fails if they differ).
- **Assets** go in the SVN `assets` folder, not in the plugin: a banner (772x250 and 1544x500), an icon (128x128 and 256x256) and the
  screenshots named in the readme (`screenshot-1.png` is in the repository root and is left out of the release by `.distignore`).
- **Tested up to** is 6.7 (the version the browser tests run on). Raise it when newer versions are tested.
