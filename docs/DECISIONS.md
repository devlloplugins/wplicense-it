# WPLicense It: Roadmap Decisions

Decisions made while reviewing 1.0.1. They shape the 1.0.2 security release and the 2.0 rewrite.

## Architecture

**Licensing core plus payment adapters.** The core knows nothing about payment providers. It exposes
`issue_license()`, `renew_license()` and `revoke_license()`. A WooCommerce adapter calls these on order
events. The custom Stripe checkout is dropped in 2.0.

## Decisions

| # | Topic | Decision |
|---|---|---|
| 1 | Distribution | Free core on wordpress.org, paid Pro later. Must follow wordpress.org rules (no bundled `vendor/`, no external calls without consent, GPL-compatible). |
| 2 | Release plan | `1.0.2` security fix release first, then `2.0`. |
| 3 | Activation limits | Add per-license site activation limits (activate and deactivate calls). |
| 4 | API style | WP REST API (`/wp-json/wplicense-it/v1/`). Old `/api/wplicense-it-api/v1/...` endpoints stay as a deprecated alias. |
| 5 | Client SDK | Ship a drop-in PHP class for sellers' plugins and themes (activation, license checks, update hooks). |
| 6 | Minimum versions | PHP 8.0+, WordPress 6.2+. |
| 7 | Existing data | Migrate 1.x licenses and orders to the new schema and keep the old API working for a while. |
| 8 | Renewals | See below. |
| 9 | Admin UI | Native WP admin screens (Settings API, `WP_List_Table`). No Bootstrap, no React. |
| 10 | Tooling | PHPCS (WordPress Coding Standards), PHPStan, PHPUnit with the WP test suite, GitHub Actions CI. |
| 11 | Branching | Rewrite on a `2.0` branch. `1.x` stays maintained for security fixes. |

### Decision 8: renewals

Sellers must not need a paid plugin to use WPLicense It, so renewals are layered:

1. **Core:** licenses have an expiry date and expire when it passes. Reminder emails before expiry.
2. **Free WooCommerce:** renewal by repurchase. The adapter extends the existing license when the customer buys again.
3. **Optional integration:** if a subscriptions plugin (such as WooCommerce Subscriptions) is active, each successful renewal payment extends the license, and a cancelled or failed subscription expires it. Nothing breaks if none is installed.
4. **Pro, later:** built-in automatic renewal (for example on Stripe Billing), if there is demand.

## Revamp decisions (product setup)

| # | Topic | Decision |
|---|---|---|
| 12 | Revamp scope | This round redesigns process 1 only (adding a product). Purchase, license checking, updates, renewal and the other processes stay as built and are reviewed one at a time afterwards. |
| 13 | WooCommerce product type | One simple WooCommerce product per plan. A variable product is not used. |
| 14 | Price conflicts | The plugin's Plans table wins. A price edited in WooCommerce is flagged on the Plans screen, with a choice to accept it or restore the plugin's price. |
| 15 | WooCommerce product status | Products are created as drafts. A Publish button appears after the test purchase passes. |

## Test purchase decisions

| # | Topic | Decision |
|---|---|---|
| 16 | Kind of test | A dry run unlocks Publish. It runs the real purchase code on an order built in memory, with in-memory storage, so it writes nothing and needs no cleanup. A live test order is not built; it can be added later as an extra. |
| 17 | API reachability check | If this site's own license API does not answer a request from the server, the test shows a warning but does not block Publish, because some hosts block requests from the server to itself while the public can reach the site. |
| 18 | When a pass stops counting | A pass is tied to a fingerprint of the product link, sites, period and zip file. Changing any of them cancels the pass. Price changes do not. |

## Selling and renewal decisions

| # | Topic | Decision |
|---|---|---|
| 19 | Selling without WooCommerce | Not supported. Selling stays on WooCommerce, which takes the payment; a site without it issues licenses by hand (Add license). Expiry reminders, renewal invoices and the renewal button are built on WooCommerce. An issue-a-license API for other payment services, a built-in checkout and an Easy Digital Downloads adapter were considered and not chosen for now. |
| 20 | Automatic renewal | No separate Pro add-on and no need to buy another premium plugin such as WooCommerce Subscriptions: the plugin gets its own subscription function. Phase 1 is renewal invoices (an unpaid renewal order with a pay link, any payment method); phase 2, charging a saved card automatically for gateways that support it, is not built yet. |
| 21 | wordpress.org | 2.0 is not hosted on wordpress.org: everything in it is the Pro plugin, distributed from its own site. This supersedes decision 1 (free core on wordpress.org). The SDK's updater is not allowed in wordpress.org-hosted plugins, so Plugin Check's one error for it is accepted. The other Plugin Check rules are still kept and run in CI. See `WORDPRESS-ORG.md`. |
| 22 | Removing the 1.x leftovers | The migration window is one full release: 2.0 keeps the 1.x API file (`includes/wplicense-it-api.php`, used only until a site has switched over), the 1.x tables and the migration tools, because the readme and `UPGRADING.md` promise that old API addresses keep working and that rolling back to 1.0.2 loses nothing before the switch-over. They are removed in 2.1. Nothing is deleted before then. The checklist is in `MIGRATION.md`. |
| 23 | Key after a late fix (card 100) | When an order had a problem and Try again issues its license, the customer is emailed the key at once (WooCommerce's email layout, "Your license for order #…"), and the order note says so. Their completed email went out without it. |
| 24 | Accounts (card 100) | Buying a license needs an account: when the cart holds a licensed product, checkout creates one (or asks to log in), whatever the store's guest checkout setting; other carts follow the store. Licenses are downloaded, renewed and managed in My Account. Sellers who want guests return false from `wplicense_it_require_account`. This makes card 85 (no guest purchases at launch) true in practice. |
| 25 | License email (card 100) | The licenses section of the processing and completed emails and of the order page shows, per key, the product, the sites it covers and the expiry, then one line on how to activate and a link to My Account, Licenses. While an order waits for its licenses (on hold, or processing when licenses are issued on completion) its emails and order page say the key will be emailed once the payment is confirmed. |
| 26 | Quantity (card 100) | Kept: one license per unit bought (3 of a 5-site plan gives 3 keys of 5 sites). Each can go to a different site owner, and a refund of one unit ends one key. |
| 27 | Deleting a licensed plugin (card 101) | The SDK frees the site on the license server when the plugin is deleted (one request, 3 seconds at most; best effort). Not on deactivation, which people do while troubleshooting. |
| 28 | Moved or copied sites (card 101) | The SDK remembers the address it was activated for. On another address the license is not active there until the customer presses **Activate here** (uses a site); the license page and an admin notice explain it and point to freeing the old address in My Account. Nothing moves on its own, so copying a site cannot multiply a license. |
| 29 | Unreachable license server (card 101) | Still never downgrades a license. After 7 days without a definite answer, the license page and a dismissible (for a week) admin notice say updates may be missing. |
| 30 | Full license (card 101) | The activation answer `limit_reached` lists the sites that use a slot and the My Account link, and the SDK shows them. Only the key holder gets it. |
| 31 | PHP requirement (card 102) | The PHP version a release needs (read from the zip, or set on the product) is sent with every update answer, so WordPress does not offer the update to a site on an older PHP and says why. |
| 32 | Updates for an expired license (card 102) | The customer still sees that a new version exists, with a line under the plugin (Plugins screen) and on the theme's details page: "Your license has expired. Renew it to get version x", with a Renew link to My Account, Licenses. It cannot be installed: no download link is given. Automatic updates are off for it meanwhile (they would fail and email the site's administrator at every check); the administrator's own setting is kept and applies again after renewing. Revoked, refunded or unknown keys learn nothing. |
| 33 | Automatic updates (card 102) | WordPress's own Enable auto-updates switch works for licensed plugins and themes, also when they are up to date. The background update fetches a fresh download link the same way the Update button does. A beta or early-access channel is not built for now. |
