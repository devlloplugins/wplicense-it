# Security review of WPLicense It 2.0

Scope: everything on the `2.0` branch (licensing core, REST API, rate limiting, signed downloads, admin screens,
WooCommerce adapter, 1.x migration, client SDK, and the 1.x code that is still loaded: the product edit screen and,
until the migration finishes, the 1.x license API). Method: reading the code, searching for dangerous patterns
(superglobals, file operations, SQL, output), and writing tests for each fix. **Not done:** running the code
against a live WordPress (none was available), PHPCS/PHPStan (the dev tools could not be installed; CI runs
them), and a penetration test.

## Threat model

| Who | Can | Must not be able to |
|---|---|---|
| Anyone on the internet | Call the public REST API and the old `/api/...` URLs | Learn whether a key exists, guess keys, download a package without a valid license, break the site by sending bad input |
| A customer with a valid key | Activate, deactivate, download, renew | Use a license beyond its limit, see or change anyone else's license, create unbounded data |
| A logged-in customer (WooCommerce) | See and manage their own licenses | Touch another customer's licenses |
| A site that embeds the client SDK | Talk to the license server | Be tricked into downloading code from somewhere else |
| An administrator | Everything | (Trusted) but every change is logged |

## Findings fixed in this review

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | **High** | Product packages were stored at predictable addresses (`uploads/wplit-files/<slug>/v<version>/<file>.zip`) and protected only by an `.htaccess` rule. On nginx, or any server that ignores `.htaccess`, anyone could download paid packages without a license. | Each upload now goes into a folder with a random 128-bit name, with index files at every level. Packages uploaded by 1.x are moved into random folders automatically on upgrade (the stored path is updated, a package that cannot be moved stays where it is). Uploads must also start with the zip signature, and a failed move is now an error instead of being recorded as a success. |
| 2 | Medium | The product post type was public: product pages, an archive and a REST endpoint exposed product descriptions and made the editor use the block editor, where the file uploads do not work. | Not public, not in the REST API, no archive. Products are looked up by ID only. |
| 3 | Medium | The REST API let an unexpected exception (for example a database error) escape as a PHP error. | Every callback catches errors, writes the details to the log and returns a generic 500 JSON message. |
| 4 | Medium | Local and staging sites do not use a license slot, so one key could create unlimited activation rows. | At most 25 active local/staging sites per license. |
| 5 | Low | A site path with odd characters was stored as sent; a reported version was stored with whatever characters it had. | Paths may only contain folder-name characters (anything else is an invalid site); versions are reduced to `A-Za-z0-9._+-`. Both are escaped on output as well. |
| 6 | Low | The client SDK followed whatever download URL the server sent. | The link must be on the configured server's host, or the update is refused. |
| 7 | Low | Sites-per-license accepted any integer, beyond what the database column holds. | Limited to 0-100000. |
| 8 | Low (docs) | Docs and a settings help text claimed the WordPress privacy export and erase tools cover migrated billing details. They do not yet. | Text corrected. The hooks are on the release checklist. |
| 9 | Low (availability) | Rate limiting uses the client IP. Behind a proxy or CDN all customers share one address, so one attacker could lock everyone out. | The settings page and `docs/API.md` now say so, with the `wplicense_it_client_ip` filter and a Cloudflare example. |

## Checked and found sound

- **SQL:** every query uses prepared values. The list search builds its `WHERE` from fixed fragments, and
  sorting uses whitelists (a test confirms a hostile `orderby` never reaches the SQL).
- **Cross-site request forgery:** every state-changing admin action is an `admin-post.php` request with a nonce tied
  to the action and the license, plus a capability check. Bulk actions use the list table's nonce. The SDK's license
  page and the renewal link are nonce protected too.
- **Cross-site scripting:** output is escaped at the point of printing. Notices after an action use short codes that
  map to fixed messages, never text from the URL. Data sent by clients (site, version) is escaped wherever shown.
- **Authorization:** admin screens need `manage_wplicense_it`. A WooCommerce customer can only see, download,
  renew and deactivate licenses whose `user_id` is theirs. A renewal request is re-checked when the order completes
  (owner, product, not revoked) and cannot touch someone else's license.
- **Keys:** generated with `random_int` (150 bits). Compared with `hash_equals`, after trimming, so the database's
  case-insensitive collation cannot make `abc` match `ABC`.
- **Information leaks:** a wrong key, a wrong product and a wrong email give the same answer. Error messages are
  fixed strings. Details go to the log, and private order notes, not to clients.
- **Downloads:** signed, expire after 15 minutes, and the license is checked again when the link is used. The file
  path is resolved with `realpath` and must be inside the packages folder, so a symlink or `..` cannot escape it.
  The file name is sanitised in the header.
- **Rate limiting:** counts only guesses (unknown keys), not expired or revoked licenses. Client IPs are hashed in the
  transient name.
- **Uploads:** names are sanitised and validated, folders are built from sanitised slugs and versions, and only
  `is_uploaded_file` paths are moved.
- **Dependencies:** the old vendored Stripe library (7.103, from 2021) is gone with the checkout. The runtime has no
  third-party code. Dev tools are not shipped.
- **Client SDK:** HTTPS enforced (plain HTTP only for localhost, `.test`, `.local`), certificates verified, only the key,
  product, site address and version are sent, the key is shown masked, nothing is downgraded because of a network error.

## Accepted risks and open items

| Item | Why it stays | Mitigation |
|---|---|---|
| **1.x API URLs carry the key, email and product key in the query string.** | Plugins already in the field call them this way. Until the 1.x data is migrated, the 1.x class answers them without rate limiting (the 2.0 handler has it). | Keys are 140+ bits. Use the 2.0 routes and the SDK for new releases. The old URLs go away in a later major version. |
| **Download links are bearer tokens**, usable by anyone who has them for 15 minutes. | A one-time link would break retries and resumed downloads. | Short lifetime, license re-checked on use. |
| **The "local or staging" check is a name heuristic** (`localhost`, `*.test`, `staging.`, `dev.`...). A customer can run production sites on such names. | Detecting real staging sites reliably is not possible from the outside. | The cap of 25 bounds it. Sellers can set stricter limits per product. |
| **License keys are stored as plain text** (database and the SDK's option). | A decision made for this release: customers must be able to see their key again. | Treat the database as sensitive. Keys can be revoked, and the SDK masks the key on screen. |
| **Privacy export and erase hooks are missing.** | Planned for the compliance step before release. | Docs say so. Billing details from 1.x can be switched off before migrating. |
| **The product edit screen is still 1.x code.** | It has a nonce, a capability check, escaped output and checked uploads now, but it is long and old. | To be rewritten with the native admin screens. |
| **No live testing yet.** | No WordPress or MySQL in the build environment. | Run the CI checks, a manual pass on staging (activation, download, WooCommerce flows, migration) and a penetration test of the REST API before release. |

## Suggested checks before release

1. Let CI run PHPCS (WordPress Coding Standards, which include the security sniffs for escaping, nonces and SQL),
   PHPStan and the unit tests, and fix what they report.
2. On a staging copy of a real 1.x site: run the migration, then try to fetch an old package URL directly (it must fail
   on nginx too), try wrong keys until the rate limit triggers, and try another customer's license IDs in the My Account
   deactivate form (it must refuse).
3. Test the REST API with a fuzzer (invalid types, huge values, unicode, repeated parameters).
4. Add the privacy export and erase hooks.
