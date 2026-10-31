# Migrating from 1.x to 2.0

Upgrading the plugin does the migration for you. Nothing is deleted: the 1.x tables stay exactly as
they were, so you can roll back to 1.0.2.

## What happens

1. On the first request after the update, the 2.0 tables are created next to the 1.x ones.
2. A background job (WP-Cron) copies licenses, then orders, in batches of 200, then converts the product
   settings (price in cents, expiry period). A notice on the admin screens shows progress.
3. It then **verifies** the copy: license and order counts must match, and a sample of recent licenses
   must still validate.
4. Only if verification passes, it **switches over**: the old `/api/wplicense-it-api/...` URLs are served
   from the 2.0 tables.
5. A last pass copies anything written to the 1.x tables meanwhile, then the migration is done.

Customers' licenses work throughout. Until the switch-over the 1.x code answers the API; after it, 2.0 does.

## What changes for existing licenses

- Keys, emails, expiry dates and products are unchanged. Dates are converted from the site's time zone to UTC.
- Existing licenses get **unlimited** activations (`activation_limit` 0). 1.x never limited sites, and a new
  limit would lock out customers. New products default to 1.
- Expired licenses are migrated as `expired`. A 1.x status other than `active` is migrated as `revoked`.
- Orders are converted to cents in USD (1.x charged in USD only). Each order is linked to the license of the
  same customer and product that was created closest to it. Colliding order numbers get a suffix (`-2`).
- Billing details (name, address, phone) are kept as JSON on migrated orders so your records are complete.
  Turn this off with `add_filter( 'wplicense_it_keep_legacy_billing', '__return_false' );` before the
  migration runs. They are included in WordPress's personal data export and erase tools (see `docs/PRIVACY.md`).
- A license whose 1.x product API key no longer matches the product's current key is reported as a warning:
  1.x clients using the old key will stop validating after the switch.

## How long it takes on a big site

Measured with `tests/env/migration-load.php` on a test machine (4 cores, MariaDB on the same machine, PHP limited to
64 MB): **100,000 licenses and 25,000 orders migrated in 5 cron runs, about 100 seconds of work, about 3 minutes with
the 10 second gap cron leaves between runs.** Every row arrived, nothing was copied twice, and the 1.x tables were
unchanged. Each run stops after 20 seconds, so it fits a host with a 30 second PHP time limit. The migration needs
about 2 MB on top of what WordPress and the other plugins already use, and that did not grow from run to run. A slower
host will take longer (the job is not faster than the database), but it does not need more memory.

Until the switch-over the old API URLs read the 1.x table, which has no index: with 100,000 licenses one lookup took
about 17 ms more than a page that does not touch licenses. After the switch they use the indexed 2.0 table.

## If something goes wrong

Rows that cannot be migrated (empty key, no product, duplicate key) are skipped and listed in the admin
notice, which also says how many licenses and orders were left behind, what verification found, and then the first
reasons (up to 50 are kept, plus up to 10 informational notes). The switch-over then waits for you. Fix the data and click **Run the migration now**, or click
**Switch to 2.0 anyway** to leave those rows behind.

## WP-CLI

    wp wplit migration status
    wp wplit migration run [--batch=200] [--accept-errors] [--restart]
    wp wplit migration verify

`--restart` starts again from the first row. Rows that were already migrated are recognised, so nothing is
duplicated. It is refused after the switch-over.

## Rolling back

Before the switch-over: deactivate 2.0 and reinstall 1.0.2. Nothing in the 1.x tables was changed.
After it: licenses issued or changed only in 2.0 (new sales, renewals, revocations, activations) are not
copied back, so rolling back means losing those changes.

## Removing the 1.x leftovers (planned for 2.1)

2.0 keeps everything needed to migrate and to roll back (decision 22). Nothing here is done yet. When 2.1 is
prepared, do these together, and only if the site has switched over (`wplit_db_version` 2.0.0 or newer):

1. Delete `includes/wplicense-it-api.php` and the `require_once` for it in `Plugin::load_product_screens()`. Until
   then it is loaded only on sites that have not switched over; after the switch-over the 2.0 `LegacyEndpoint`
   answers the old `/api/wplicense-it-api/v1/` addresses from the 2.0 tables, so those addresses keep working.
2. Decide what happens to a site that updates straight from 1.x to 2.1 without ever running 2.0: either 2.1 still
   migrates it (keep `src/Migration/`), or 2.1 refuses to update and says to install 2.0 first. Pick one before
   deleting `src/Migration/`.
3. Drop the 1.x tables and the `legacy_id` / `legacy_billing` columns only through an explicit administrator
   action (a button with a confirmation, like **Delete the stored Stripe keys**), never silently. Dropping them
   ends rollback to 1.0.2.
4. Update `uninstall.php` (it still drops the 1.x tables and options), `UpgradeNotice`, `LegacyShortcodes`, and the
   readme's "old API addresses keep working" and `UPGRADING.md` sentences to match.
5. Keep the old `/api/wplicense-it-api/v1/` addresses working for as long as customers' sites still run a client
   that uses them. Removing the 2.0 `LegacyEndpoint` is a separate decision, not part of this one. Since 2.0 the site owner
   can see how many calls they get (Licensing settings > Status) and switch them off there; removing the code is safe once
   they have been off, or have had no calls, for a while.

