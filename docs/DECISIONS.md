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
