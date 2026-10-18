# Trying WPLicense It 2.0 on a real site

Nothing in 2.0 has run in WordPress yet. The code is tested against stand-ins only, so expect to find problems.
This script goes in the order things depend on each other. Stop at the first step that fails badly and send the
result: a fix usually unblocks several later steps.

## An environment that is already scripted

`tests/env/setup.sh` builds two local WordPress 6.7 sites on a Debian or Ubuntu machine: the license server (port
8080, with this plugin symlinked in) and a customer site (port 8081). It installs MariaDB, clones WordPress from
GitHub, installs both sites and starts them. Run it again at any time: it skips what is done. It needs `apt`, PHP 8
with `mysqli`, `git` and network access to the package archive and to GitHub.

`tests/e2e/` has browser tests that drive those sites with Playwright (`NODE_PATH=$(npm root -g) node
tests/e2e/01-admin.cjs`, then `02`, `03`, `04`). They cover steps 2 to 9, 18, 23 to 28 and 30 to 31 below, and
they were what found the problems fixed in the commits that added them. They need no WooCommerce, so steps 10 to 17,
19, 20 and 32 to 34 still need a site with WooCommerce.

`tests/e2e/11-migration.cjs` seeds a 1.x database and runs the migration end to end. `php tests/env/migration-load.php`
measures it on a big one (100,000 licenses by default, which takes about two minutes; add a second argument for the
PHP memory limit, a third for the number of rows that cannot be migrated, and `stop` to leave it waiting for the
owner's decision). Run `php tests/e2e/php/legacy.php restore` afterwards.

`tests/env/nginx-check.sh` checks package protection on nginx, which ignores the `.htaccess` file that protects packages
on Apache. It puts a 1.x package at the old predictable address, serves the site's files with a real nginx and PHP-FPM
(the usual WordPress configuration, with nothing about the packages folder), and checks that the old address serves the
zip before the upgrade and not after, that the product then points at a random folder, that the file is unchanged, and
that the folders above the package list nothing and give no token away. It needs `apt install nginx php-fpm` and uses
port 8082. It was also run with the move broken on purpose, and failed as it should.

`tests/env/setup-multisite.sh` builds a multisite network (subdirectory type, three sites) in its own database, and
`php tests/env/multisite-check.php` checks the plugin on it: network activation, per-site tables and files, who may
manage licenses where, that one site's licenses are invisible to another, migrating a subsite, deleting a site, and
uninstalling. The network is not served over HTTP, because WordPress refuses to run a network on a port other than 80 or
443, so each site is loaded from the command line with its own host and path.

`php tests/env/query-check.php` builds a million licenses and prints the time and EXPLAIN of the plugin's queries (see the
last section of `docs/SCHEMA.md`). It empties the test site's licenses when it is done.

`tests/e2e/14-accessibility.cjs` scans each admin screen and each product tab with axe-core (WCAG 2.1 A and AA) and checks
by hand what a scan cannot judge: the controls reached and used with the keyboard alone, a visible keyboard focus, one main
heading per screen, and messages that announce themselves. It needs `axe-core` next to Playwright (`npm install -g axe-core`
locally; CI installs it with Playwright).

The plugin that updates itself must run on a different site from the license server. WordPress puts a site into
maintenance mode while it updates a plugin, and a license server in maintenance mode cannot hand out the download:
the update then fails with "The license server had a problem".

## What you need

- A WordPress site (6.2 or newer, PHP 8.0 or newer) with **WooCommerce** active, `WP_DEBUG` and `WP_DEBUG_LOG` on,
  and a backup. A throwaway site is best: this is pre-release code.
- The plugin zip `wplicense-it-2.0.0-dev.zip` and the two sample plugin zips `wplit-sample-1.0.0.zip` and
  `wplit-sample-1.1.0.zip` (a tiny plugin that shows whether its license is active).
- Ideally HTTPS. Plain HTTP works only on `localhost`, `*.test` and `*.local`.
- A second site for the sample plugin is nicer, but the same site works.

## What to send back

For every step: **pass**, **fail** or **skipped**, and for a fail what you saw (a screenshot, or the text of the
notice) plus any new lines in `wp-content/debug.log`. A PHP fatal error with its file and line is the most useful
thing of all.

## A. Does it start

1. **Install and activate** `wplicense-it-2.0.0-dev.zip`. It should activate with no error. (A fatal error here
   stops everything: send it.)
2. **Menu.** A **License Products** menu and a licenses screen appear. Open each one.
3. **Tables.** In the database, `wplit_licenses`, `wplit_activations`, `wplit_license_orders` and
   `wplit_license_events` exist (with your table prefix).
4. **Settings.** Open the licensing settings page and save it once.
5. **Log.** `debug.log` has no PHP errors or warnings from `wplicense-it`.

## B. Setting up a product

6. **New product.** License Products, Add New. Give it a title and **Publish** it (an unpublished product is
   unknown to the API). Under the title you should see four tabs: **Software, Plans, Releases, Connect**. Click
   each. Reload: the tab you were on should be remembered.
7. **Upload a release.** Releases tab: upload `wplit-sample-1.0.0.zip`, notes empty, **Update**. Expect a green
   notice (name, version 1.0.0, plugin, needs WordPress 6.2 and PHP 7.4) and the version filled in. The release
   history shows one **Current** release. The file should exist under `wp-content/uploads/wplit-files/p<ID>/`.
8. **Bad uploads.** Try a zip that is not a plugin (for example a zip of a text file), and a zip bigger than your
   server's upload limit. Each should give a red message and change nothing else.
9. **Logo.** Software tab: upload a small PNG as logo. It should show a preview after saving.
10. **Plans.** Plans tab: add `One site`, price `49`, sites `1`, 1 year, and `Five sites`, `99`, `5`, 1 year.
    **Update.** Expect two **draft** products in WooCommerce (Products), each virtual, with the right price. Open
    one: the **WPLicense It** section should show this product, the sites and the period.
11. **Price conflict.** In WooCommerce change the price of one plan product and save it. Back on the Plans tab,
    update the product: that row should warn and offer **Keep the plan price** / **Use the WooCommerce price**.
    Try both.
12. **Remove a plan.** Clear a row and update. Its WooCommerce product should go back to draft.
13. **Test this plan.** Click **Test this plan** on a plan. Expect a results list. All seven checks should pass
    (the last may warn on hosts that block requests to themselves). Then **Publish in the shop** should appear.
    Click it: the WooCommerce product becomes published.
14. **Stale test.** Change the sites of that plan and update. Publish should no longer be offered until you test
    again.
15. **Connect tab.** Check the checklist matches reality, and **Copy the code** copies the code.

## C. A customer buys

16. **Buy.** As a customer (a test account), buy a published plan with a test payment method (cheque, cash on
    delivery or a 100% coupon). Complete the order (or mark it **Completed** as an admin).
17. **License issued.** The order notes should say a license was issued. **My Account, Licenses** should show the
    key, status, expiry and sites. The customer's order email should show the key.
18. **Licenses list.** The admin licenses screen lists it. Open it: history, sites, actions.
19. **Refund.** Refund the order fully. The license should become **refunded**.
20. **Renew.** Buy again with a second license; in My Account use **Renew** and complete the order. The same
    license should be extended.

## D. A plugin that uses the license

21. **Install the sample.** `wplit-sample-1.0.0.zip` already contains the client SDK. Install it and activate it.
    It shows a warning that the license code has not been pasted.
22. **Paste the code.** On the product's Connect tab copy the code and paste it into `wplit-sample.php` where it
    says STEP 1. Reload the admin: the notice should now say the license is **not active**. A new page, **Settings,
    WPLit Sample License**, should exist.
23. **Activate.** Enter the license key from step 17. It should say active. On the server, the license shows that
    site as active.
24. **Limit.** Activate on more sites than the license allows (or lower the sites on the license to 1 in the
    admin and activate on a second site). The extra one should be refused.
25. **Deactivate** from the sample plugin. The slot should be free again.

## E. Releases and updates

26. **Release 1.1.0.** Releases tab: upload `wplit-sample-1.1.0.zip`, notes empty. The history should now list
    1.1.0 (Current) and 1.0.0. The notes should be read from the zip's readme.
27. **Update appears.** On the site with the sample plugin, **Dashboard, Updates** (click **Check again**): WPLit
    Sample 1.1.0 should be offered. **View details** should show a **Changelog** tab.
28. **Update.** Run the update. It should install and the notice should say 1.1.0. (This is the delivery path:
    signed link, download, install.)
29. **Roll back.** On the product, **Make current** on 1.0.0. The version setting should become 1.0.0. Sites on
    1.1.0 are not downgraded (expected).
30. **Delete.** Delete the 1.1.0 release. Its zip should be gone from `uploads/wplit-files/p<ID>/`.
31. **Expired license.** Set the license to expire in the past in the admin: the update should no longer be
    offered, and the sample plugin should report the license as not active after its next check.

## F. Housekeeping (if you have time)

32. **Privacy.** Tools, Export Personal Data for a customer email: the file should include their licenses. Erase
    it: the license key stays, the identity is removed.
33. **From 1.x.** If you have a site with 1.x data, update it with this zip and check that licenses and orders
    appear and old customers' update checks still work.
34. **Uninstall.** Deleting the plugin removes its tables and settings. (Do this last, on a throwaway site.)


## WooCommerce in the scripted environment

Run `tests/env/setup-woocommerce.sh` after `tests/env/setup.sh`. It installs WooCommerce 9.4.2 and a mail-capture plugin. Then run `tests/e2e/05-woocommerce.cjs` (plans, test purchase, Publish) and `tests/e2e/06-purchase.cjs` (completed order, email, My Account, refunds, cancellation, renewal).

Run every browser test in order with `tests/e2e/run-all.sh` (or only some: `tests/e2e/run-all.sh 05 06`). It keeps going after a failure and prints which tests failed. The same script runs in the `browser` job of `.github/workflows/ci.yml`.
