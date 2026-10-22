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

`bin/build-release.sh --check` builds the release zip, checks what is in it and that two builds are identical (see `RELEASE.md`). It runs in CI.
The browser suite was also run once against the plugin unpacked from that zip, with all 19 files passing.

`tests/e2e/26-translations.cjs` (12 checks) makes a small German translation from `languages/wplicense-it.pot`, compiles it with
WP-CLI into the plugin's languages folder, switches the admin user to German, and checks the menu (registered with the product type)
and the Licensing settings screen are in German, and that nothing is logged about translations loading too early. It fails without
the plugin's own translation loading.

`tests/e2e/34-expiry.cjs` (18 checks) runs the sample plugin with the client SDK on the customer site: after the daily
job expires its license and Check now, `wplicense_it_license_active()` stays true, the status is `expired` and the
license page says the plugin keeps working without updates; registered with `'expired' => 'stop'` the plugin's licensed
part turns off and the page says so; the expired key cannot be activated on another site; a revoked license is
`inactive`. The Overview lists the license that expired 5 days ago with the renewal rate (checked against the server's
own count) and the link to all expired licenses; renewing it counts as renewed and removes it from the list.

`tests/e2e/33-renewal.cjs` (19 checks) uses a product with an Agency and a newer Single site plan: Renew in My Account,
the cart and a renewal invoice use the Agency plan the license was bought with, and a link edited to the Single site
plan renews nothing (4 checks fail with the lookup used before); refunding the renewal order puts the expiry back with
the license still active, noted on the order and in the license history; completing it again with the refund recorded
keeps it taken back; a cancelled renewal order paid after all renews again, once; with the plan unpublished there is no
Renew button until a plan with the same sites exists.

`tests/e2e/32-update-delivery.cjs` (18 checks) runs the sample plugin with the client SDK on the customer site: the
Enable auto-updates link is there while it is up to date and turning it on is saved; a release needing PHP 99 is not
offered and the background update skips it; a fitting release is installed by the background update (run as WordPress's
cron runs it) and the plugin stays active; with the license expired the next version is shown with "Renew it to get
version 1.3.0" and the Renew link, automatic updates show as disabled, the background update does not try it and the
setting is kept; after renewing, the background update installs it.

`tests/e2e/31-license-checking.cjs` (17 checks) runs the sample plugin with the client SDK on the customer site: a changed
address is told on the dashboard and the license page and the license is not active until Activate here; with the license
server unreachable (a test mu-plugin blocks it) and no answer for 8 days, a notice says so while the license keeps working,
Dismiss for a week hides it, Check now clears it; a full license answers with the site using it and the My Account link;
deleting the plugin from the Plugins screen frees the site on the server.

`tests/e2e/30-checkout-account.cjs` (10 checks) buys through the real checkout with guest checkout allowed: a licensed cart
makes an account and a mug alone stays a guest order; the cash on delivery email and the order received page say the key
follows; the completed email has the key, its sites, how to activate and the My Account link. Test 19 also checks the
customer is emailed the key after Try again.

`tests/e2e/06-purchase.cjs` also completes orders again (card 74): after a cancellation and after being set to
Refunded the same licenses come back with the order named in their history, a unit refunded with its quantity keeps its
license refunded, and a license revoked by hand stays revoked.

`tests/e2e/06-purchase.cjs` also pays an order the way an online payment service does (`payment_complete()`): a plan
product alone completes at once and gets its license; with a physical item in the same order it waits in Processing.

`tests/e2e/27-import-export.cjs` (22 checks) exports a product's licenses from the Licenses list link and by status from
Import and export, checks the columns and that a formula-looking email is written as text, then imports the export with
new lines added (one by product name, one with a bad email, one with a bad status): the check lists skipped and wrong
lines by number and writes nothing, the import adds exactly the good lines with their dates and an "imported" history entry,
sending the same check again is refused, and an editor can neither download the export nor open the screen.

`tests/e2e/25-example-plugin.cjs` (14 checks) downloads the example plugin from a product's Connect box, installs it on the customer site
without editing it, and checks: the licensed feature is off and its notice links to the license page, a license turns it on and the server
records the site, and deleting the plugin removes what the SDK stored.

`tests/e2e/24-sdk-cleanup.cjs` (11 checks) puts two small plugins with the client SDK on the customer site and deletes them through the real
Plugins screen: one without an uninstall.php (and with a slug that is not its folder name) has its license, update cache and daily check
removed by the uninstall hook the SDK registered; one with its own uninstall.php does the same with the one call from the SDK README;
another product's data is left alone.

`tests/e2e/21-order-licenses-box.cjs` (14 checks) opens the order screen of an order for two products and checks the licenses box: both licenses
with full keys, product lines, Active, sites used of allowed, a working View link; after a cancellation both are listed as Revoked; a
licensed order that has not issued says so; an order for an ordinary product has no box; a shop manager sees neither box nor keys.

**Two order storages.** `tests/env/order-storage.sh classic` (and `hpos` to go back) switches the test store between WooCommerce's
high-performance order storage and classic post storage. Tests 06, 17, 19 and 21 are run on both (CI does it after the main run).
Running on classic storage found a real bug: WooCommerce ignores a meta query on orders there and answers with every order, so
completing a renewal order cancelled every unpaid order of the shop. Test 17 now checks that an unrelated unpaid order is left alone.

`tests/e2e/20-several-products-order.cjs` (10 checks) makes two products with plans and one order with lines for both: each line gets its
licenses, all linked to the one order record, the record names no single product (0), the products are read back from the licenses, and an
order for one product still records that product. It fails with the old behaviour (the first product).

`tests/e2e/19-order-problems.cjs` (25 checks) pays an order for a product whose license period is nonsense and checks: no license is made, the order
remembers the problem, the administrator gets one email with the order's address (WooCommerce's own new-order email is not
counted), the order screen shows an alert with a Try again button, the Overview lists the order with a link, trying again while
it is still broken changes nothing and sends no second email, and after the product is fixed Try again issues the license,
clears the problem and the Overview entry, and an order note says so. A subscriber cannot use the retry address.

`tests/e2e/18-overview.cjs` (41 checks) starts from a site with no products and no licenses and builds it up: Overview is second in the
menu, every required step is still to do, making a product ticks its step and a missing release is a warning with a link,
uploading a release ticks that and clears the warning, twelve licenses in different states give the right four numbers, the two
that expire within 30 days are listed soonest first with the days left, the newest eight are listed newest first and a key opens
that license, a number opens the list in that state, downloading the SDK and turning on reminders tick their steps, the
WP-Cron warning replaces the late-job warning, and a subscriber is refused. The rules behind the checklist and the problems are
unit tested too (`OverviewReportTest`); the stale-job and migration warnings cannot be made to appear on the test site, so
only the unit tests cover them.

`tests/e2e/17-renewal-invoices.cjs` (37 checks) covers renewal invoices with real WooCommerce: off by default and the days setting, the
daily job run through a real `wp-cron.php` request, an unpaid order made for each license inside the window and none for
one outside it (with the renewal mark, the customer or a guest, the price), WooCommerce's invoice email and its pay link, the
history entry, no second invoice or email, a guest paying from the link, the account customer logging in from the link and
paying with cash on delivery, the license renewed by a year only when the order is completed and no second license made, and an
unpaid invoice cancelled when its license is renewed by another order.

`tests/e2e/16-reminders.cjs` (77 checks) covers expiry reminder emails: off by default, stopping them (the link and headers in
every email, the link only asks until Stop is pressed, a wrong code is refused, no more reminders to that address, the
owner's list and Turn back on, the one-click request, the privacy export and erase, the My Account switch), the email after expiry (off by
default, sent once to a license that ran out 4 days ago and not to one 40 days ago, recorded, its test button), the test email button (sent only to
the administrator, while reminders are off, with the customer's subject marked [Test] and link, nothing recorded, refused
without its security token), junk cleaned from the days setting, the
daily job run through a real `wp-cron.php` request, who is mailed and who is not (far away, lifetime, revoked), the subject, no
license key in the email, the link (a customer logs in and sees the license, a guest license goes to the home page), the history
entry, no second email, a changed or renewed expiry starting a new cycle, and a custom renewal address (a `javascript:` address
is dropped). Mail is kept by the site's mail capture, so nothing is sent.

`tests/e2e/15-api-fuzz.cjs` (about 790 checks, no browser) sends a hostile or broken client's requests to the REST API and the
1.x endpoints: every field of every route with the wrong type, huge values (up to 8 MB), unicode and invalid bytes, repeated
and array parameters, malformed and mistyped bodies, wrong methods and routes, key variants that must not match (case, wildcards,
partial keys, SQL), guessing until the rate limit blocks, tampered and revoked download links, and many requests at the same
moment (12 activations of one site use one slot; 12 different sites never exceed the license's slots). Every answer must be a
clean JSON error with no PHP message, path, SQL or stack trace, and nothing may be added to debug.log. It sets the rate limit to
the documented 20 in 15 minutes while it runs and puts the old values back. It found the two problems listed as 10 and 11 in
`docs/SECURITY.md`.

Two behaviours it reports but does not fail on: a POST route also reads a key sent in the URL's query string (the body wins when
both are sent), and a one megabyte URL is refused by the web server before WordPress sees it.

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

## A demo store

To look around a store that already has data (or to show the plugin to someone), run on a **test site** with
WooCommerce active:

    wp wplit demo create     # asks first; --yes to skip the question
    wp wplit demo status
    wp wplit demo remove     # deletes exactly what create made, and nothing else

It goes through the plugin's own code, as a seller and customers would: two products (Demo Backup Pro, a plugin with
releases 1.0.0 and 1.1.0 made from the example plugin; Demo Starter Theme 2.0.0), four plans that pass their test
purchase and are published, eight customers (`demo-<name>@example.com`) and nine real WooCommerce orders dated over the
past 400 days. The licenses cover every state: active with sites in use (and a staging site that uses no slot), one
expiring in about 10 days, one expired a month ago, a lifetime theme license, one renewed early, one refunded, one
revoked by a cancelled order, an order waiting for a bank transfer with no license yet, and one license issued by
hand. No email is sent while it runs (a plugin that replaces `wp_mail()` entirely could still send some; the
addresses are at example.com, which accepts no mail). It refuses to run twice, or when one of its addresses belongs
to an existing user. Everything it makes is written down as it goes (option `wplit_demo`), so `remove` also cleans up
after a run that stopped half way. The orders show in WooCommerce's reports until the demo is removed, so do not use it
on a live store. Customers have random passwords; set one (Users, Edit) to see My Account as them.

`tests/e2e/29-theme-updates.cjs` (18 checks) does for a theme what test 04 does for a plugin, on the customer site: the
Connect code for a theme, the license page, the update offered, the "View version details" popup (a page on the customer
site with version, requirements and what is new; an author is refused it), and installing the update into the same
folder.

`tests/e2e/28-demo-store.cjs` (35 checks) runs create, checks every license state, the releases, the published plans,
the Licenses list, a customer's My Account and download, that no email was sent and a second run is refused, then
runs remove and checks nothing of it is left (licenses, sites, history, order records, orders and refunds, products,
release files, customers) and everything else is unchanged.

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
    delivery or a 100% coupon). With a 100% coupon, or a card payment in Stripe's test mode, the order should
    complete on its own and the license be issued at once. With cheque or cash on delivery it waits: mark it
    **Completed** as an admin.
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
