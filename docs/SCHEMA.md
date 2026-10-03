# WPLicense It 2.0: Schema and Migration

Status: **approved design**. Nothing here is implemented yet.

This follows `docs/DECISIONS.md`: a licensing core with payment adapters (decision 3: activation limits,
decision 7: migrate 1.x data and keep the old API working).

## What is wrong with the 1.x schema

| Problem | 1.x | Effect |
|---|---|---|
| Small IDs | `mediumint(9)` | Overflows at about 8.3M rows, and does not match WordPress post/user IDs (`bigint unsigned`). |
| Short columns | `email varchar(48)`, `license_key varchar(48)` | Long emails get truncated or rejected. |
| Zero dates | `DEFAULT '0000-00-00 00:00:00'` | Rejected by MySQL strict mode. "No expiry" is encoded as a magic date. |
| Mixed time zones | `current_time('mysql')` (site-local) | Expiry depends on the site's time zone and DST. |
| Money as text | `order_total varchar(16)` | No arithmetic, no currency, USD hardcoded. |
| Product secret copied per license | `product_api_key` on every license row | A product key rotation does not reach existing rows. |
| No site binding | none | One key works on any number of sites. |
| No indexes | only `UNIQUE KEY id` | Every API check scans the table. |
| Status unused | `license_status` is written but never read | A license cannot be revoked. |
| Billing data in core | full address and phone per order | GDPR exposure, and duplicated if WooCommerce is the seller. |
| No audit trail | none | No way to explain why a license is invalid. |

## Principles

- **Core is payment-agnostic.** Orders in the core table only record where a license came from. Billing details stay with the payment adapter (WooCommerce keeps its own).
- **UTC everywhere.** All `DATETIME` columns store UTC. `NULL` means "not set" (for example no expiry).
- **Integers for money.** Minor units (cents) plus a currency code.
- **WordPress IDs.** `BIGINT UNSIGNED` for anything that refers to a post or user.
- **Index for the queries we actually run** (see "Query patterns").
- **New table names**, so 1.x tables stay untouched until migration is verified.
- Created with `dbDelta()`, so follow its formatting rules (two spaces after `PRIMARY KEY`, one column per line).

## Tables

Prefix is `{$wpdb->prefix}wplit_`. The 1.x tables are `wplit_product_licenses` and `wplit_orders`. The 2.0 tables use different names
(`wplit_licenses`, `wplit_activations`, `wplit_license_orders`, `wplit_license_events`), so both generations can coexist during migration.

### `wplit_licenses`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` PK, auto-increment | |
| `product_id` | `BIGINT UNSIGNED` NOT NULL | Post ID of the `wplit_product` post. |
| `user_id` | `BIGINT UNSIGNED` NULL | NULL allows guest purchases later. |
| `order_id` | `BIGINT UNSIGNED` NULL | Row in `wplit_license_orders`. |
| `license_key` | `VARCHAR(64)` NOT NULL | Random, 128 bits or more. Stored as-is (see Resolved questions). |
| `email` | `VARCHAR(190)` NOT NULL | Lower-cased on write. |
| `status` | `VARCHAR(20)` NOT NULL | `active`, `expired`, `revoked`, `refunded`. |
| `activation_limit` | `INT UNSIGNED` NOT NULL DEFAULT 1 | `0` means unlimited. |
| `expires_at` | `DATETIME` NULL | NULL means lifetime. |
| `legacy_id` | `BIGINT UNSIGNED` NULL | `id` in `wplit_product_licenses`, used to make migration idempotent. |
| `created_at`, `updated_at` | `DATETIME` NOT NULL | UTC. |

Indexes: `UNIQUE (license_key)`, `UNIQUE (legacy_id)`, `KEY (product_id, status)`, `KEY (user_id)`, `KEY (email)`, `KEY (status, expires_at)`.

`product_api_key` is **not** stored here. It lives in product meta, so rotating it takes effect for every license immediately.

### `wplit_activations`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` PK | |
| `license_id` | `BIGINT UNSIGNED` NOT NULL | |
| `site` | `VARCHAR(190)` NOT NULL | Normalised site URL (see below). |
| `status` | `VARCHAR(20)` NOT NULL | `active` or `deactivated`. |
| `product_version` | `VARCHAR(32)` NULL | Version the client last reported. |
| `activated_at` | `DATETIME` NOT NULL | |
| `deactivated_at` | `DATETIME` NULL | |
| `last_checked_at` | `DATETIME` NULL | Updated by the status and update checks. |

Indexes: `UNIQUE (license_id, site)`, `KEY (site)`.

**Site normalisation:** lower-case host, no scheme, no `www.`, no trailing slash, keep the path (so `example.com/blog` and `example.com` are different installs). Local and staging hosts (`localhost`, `*.local`, `*.test`, `*.localhost`, and common staging patterns) are recorded but do not count against the limit.

The limit is enforced as `COUNT(*) WHERE license_id = ? AND status = 'active'` inside a transaction, so two simultaneous activations cannot both take the last slot.

### `wplit_license_orders`

Minimal record of where a license came from.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` PK | |
| `source` | `VARCHAR(20)` NOT NULL | `free`, `woocommerce`, `legacy_stripe`, `manual`. |
| `external_id` | `VARCHAR(100)` NULL | WooCommerce order ID or Stripe charge ID. |
| `order_number` | `VARCHAR(48)` NOT NULL | Human-readable number. |
| `user_id` | `BIGINT UNSIGNED` NULL | |
| `product_id` | `BIGINT UNSIGNED` NOT NULL | |
| `currency` | `CHAR(3)` NOT NULL | ISO 4217. |
| `total_minor` | `BIGINT` NOT NULL | Cents. |
| `status` | `VARCHAR(20)` NOT NULL | `completed`, `refunded`. |
| `legacy_id` | `BIGINT UNSIGNED` NULL | `id` in 1.x `wplit_orders`. |
| `legacy_billing` | `LONGTEXT` NULL | JSON of the 1.x billing fields, only for migrated orders (see Resolved questions). |
| `created_at`, `updated_at` | `DATETIME` NOT NULL | UTC. |

Indexes: `UNIQUE (order_number)`, `UNIQUE (source, external_id)`, `UNIQUE (legacy_id)`, `KEY (user_id)`, `KEY (product_id)`.

### `wplit_license_events`

Append-only audit log: `issued`, `activated`, `deactivated`, `renewed`, `expired`, `revoked`, `refunded`, `key_regenerated`.

| Column | Type |
|---|---|
| `id` | `BIGINT UNSIGNED` PK |
| `license_id` | `BIGINT UNSIGNED` NOT NULL |
| `type` | `VARCHAR(30)` NOT NULL |
| `data` | `LONGTEXT` NULL (JSON: site, old and new expiry, actor) |
| `created_at` | `DATETIME` NOT NULL |

Index: `KEY (license_id, created_at)`. Pruned by a daily event with a filterable retention (default 2 years).

## Product data (post meta on `wplit_product`)

| 1.x meta | 2.0 | Change |
|---|---|---|
| `wplit_product_price` (string) | `wplit_price_minor` (int) | Converted on migration. |
| (none) | `wplit_currency` | Default `USD`. |
| `wplit_expire`, `wplit_expire_time` (`yes`, `1-month`, `1-year`) | `wplit_period` (`lifetime`, `P1M`, `P1Y`, any ISO 8601 interval) | Mapped on migration. |
| (none) | `wplit_default_activation_limit` | Default 1 for new products. |
| `wplit_product_api_key` | `wplit_product_api_key` | Unchanged. Now the only place it is stored. |
| `wplit_product_version`, `wplit_tested_wp_version`, `wplit_required_wp_version`, `wplit_product_description`, `wplit_product_name` | same | Unchanged. |
| `file_dir_path`, `file_dir_location`, `file_name` | `wplit_package` (attachment ID or private path) | Handled with the file-delivery design; not part of this migration. |

## Migration (1.x to 2.0)

Goals: no customer loses access, running the migration twice does nothing, and the old data is never modified.

### Field mapping

`wplit_product_licenses` to `wplit_licenses`:

| 1.x | 2.0 | Rule |
|---|---|---|
| `id` | `legacy_id` | Used for idempotency. |
| `user_id` | `user_id` | `0` becomes NULL. |
| `product_id` | `product_id` | |
| `license_key` | `license_key` | Copied unchanged, so existing keys keep working. |
| `email` | `email` | Lower-cased. |
| `product_api_key` | (dropped) | Compared against the product meta instead. A mismatch is logged. |
| `license_status` | `status` | `active` becomes `active`, or `expired` if the expiry has passed. Anything else is logged and becomes `revoked`. |
| `valid_until` | `expires_at` | `0000-00-00 00:00:00` becomes NULL. Other values are converted from the site time zone to UTC (see Resolved questions). |
| `created_at`, `updated_at` | same | Converted to UTC. Zero values become the migration time. |
| (none) | `activation_limit` | **`0` (unlimited)** for migrated licenses. 1.x never limited sites, and a new limit would break existing customers. |

`wplit_orders` to `wplit_license_orders`:

| 1.x | 2.0 | Rule |
|---|---|---|
| `id` | `legacy_id` | |
| `order_number` | `order_number` | Already unique per day plus random suffix; a collision gets a numeric suffix. |
| `order_total` | `total_minor` | `round(floatval * 100)`. |
| (none) | `currency` | `USD`, since 1.x hardcoded it. |
| `order_status` | `status` | Empty becomes `completed`. |
| (none) | `source` | `legacy_stripe` for paid orders, `free` for a total of 0. |
| billing columns | `legacy_billing` JSON | Only if the retention option is on (see Resolved questions). |
| (none) | `wplit_licenses.order_id` | The license is linked to its order by matching `user_id`, `product_id` and the closest `created_at`. Unmatched orders are kept without a link. |

### Procedure

1. On update, compare `wplit_db_version` with `2.0.0`. Create the new tables with `dbDelta()`. Do not touch the 1.x tables.
2. Copy in batches of 200 rows by ascending `legacy_id` (`INSERT ... ON DUPLICATE KEY` on `legacy_id`), so it can stop and resume. A time and memory budget per request, continued through WP-Cron, plus `wp wplit migrate` for CLI.
3. Keep progress in an option (`wplit_migration_state`: `pending`, `running`, `done`, last `legacy_id`, counts, errors).
4. Convert product meta (price, period) in the same job.
5. When done, compare counts and run a spot check (N random licenses validate through both the 1.x and 2.0 lookup). Show the result in the admin.
6. Only then mark `wplit_db_version = 2.0.0`. Until it is done, the legacy API alias keeps reading the 1.x tables.

### Legacy API alias after migration

- `/api/wplicense-it-api/v1/{info|get|status}` reads from `wplit_licenses` using `(p, e, l, k)` with the same semantics as 1.x.
- It does not require or record an activation (old clients send no site), and is not subject to `activation_limit`.
- Responses are unchanged in shape. It is marked deprecated in the docs and logs a notice at most once a day.

### Rollback and cleanup

- The 1.x tables are not modified, so rolling the plugin back to 1.0.2 works if the database version option is restored.
- The 1.x tables are removed in a later 2.x release, with a notice and a one-click export. `uninstall.php` drops both generations.

## Query patterns (what the indexes serve)

| Query | Index |
|---|---|
| Validate a key: `license_key = ?` then check product, email, status and expiry | `UNIQUE license_key` |
| A customer's licenses: `user_id = ?` | `user_id` |
| Admin search by email | `email` |
| Daily expiry sweep: `status = 'active' AND expires_at < NOW()` | `status, expires_at` |
| Count active sites for a license | `UNIQUE (license_id, site)` |
| Order lookup from WooCommerce: `source = 'woocommerce' AND external_id = ?` | `UNIQUE (source, external_id)` |

## Resolved questions

1. **License key storage:** keys are stored as-is with a unique index and compared with `hash_equals()`, so customers can always see their key. The table is treated as sensitive.
2. **Time zone of migrated dates:** 1.x dates are treated as site-local and converted to UTC.
3. **Billing data in migrated orders:** kept as `legacy_billing` JSON behind a setting, and covered by the WordPress privacy exporter and eraser.
4. **Guest purchases:** not at launch. `user_id` stays nullable so the WooCommerce adapter can add guest checkout later.
5. **Event retention:** two years, filterable, pruned by a daily job.
