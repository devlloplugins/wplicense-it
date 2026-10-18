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
