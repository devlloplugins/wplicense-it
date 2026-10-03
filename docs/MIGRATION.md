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
  migration runs. WordPress's privacy export and erase tools do not cover them yet (planned before release), so remove them by hand if a customer asks.
- A license whose 1.x product API key no longer matches the product's current key is reported as a warning:
  1.x clients using the old key will stop validating after the switch.

## If something goes wrong

Rows that cannot be migrated (empty key, no product, duplicate key) are skipped and listed in the admin
notice. The switch-over then waits for you. Fix the data and click **Run the migration now**, or click
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
