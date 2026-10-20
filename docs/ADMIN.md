# Admin screens

Built with native WordPress components (the Settings API, `WP_List_Table` and standard admin styles).
They live under **License Products** in the admin menu and need the `manage_wplicense_it` capability,
which administrators get automatically. Add roles with the `wplicense_it_manage_roles` filter.

## Overview

**License Products > Overview** (second in the menu, after All Products) is the screen to start from. Only people who may manage
licenses can open it. It shows:

- **Problems**, when there are any: paid orders that could not be given their license (listed with links, see
  `WOOCOMMERCE.md`); the 1.x data migration needs attention; the packages folder cannot be written to;
  published products with no release uploaded (customers cannot download or update them); WP-Cron switched off
  (`DISABLE_WP_CRON`), which stops marking expired licenses, reminders and renewal invoices unless a real cron job calls
  `wp-cron.php`; and the daily job not running (not for two days, or never although it was due more than a day ago).
  When WP-Cron is switched off only that warning is shown, not also that the job is late.
- **Getting started**, a checklist: make a product, upload a release, issue a license, then the optional steps (add a plan with a
  price so customers can buy, or install WooCommerce; download the client SDK; turn on expiry reminders; turn on renewal
  invoices). Done steps are ticked. Once the three required steps are done the heading becomes **Setup**, and only the optional
  steps still to do are listed.
- **Licenses**: how many are active, expired, revoked and refunded, each a link to the list filtered by that status.
- **Expiring in the next 30 days**: the ten that expire soonest, with the days left.
- **Newest licenses**: the last eight issued. A license key opens that license.

The daily job writes down when it last ran (`wplit_last_maintenance`), and downloading the SDK is remembered
(`wplit_sdk_downloaded`), for the warnings and the checklist.

## Licenses

A searchable, filterable, sortable list of every license.

- **Views:** All, Active, Expired, Revoked, Refunded, each with a count.
- **Search:** by key or customer email. **Filter:** by product.
- **Sort:** customer, status, expiry, created. **Per page:** a screen option.
- **Bulk actions:** revoke, reinstate. Row actions: manage, revoke or reinstate.

Open a license (**Manage**) to see and change everything about it:

| Section | What you can do |
|---|---|
| Summary | Key, status, product, customer (with a link to their profile), expiry, sites used, created. |
| Actions | Revoke, mark as refunded, reinstate, renew for 1 month to 2 years. |
| Edit | Customer email, sites per license, expiry (keep, never, or valid through a date). |
| Active sites | Each active site with a Deactivate button, to free a slot for a customer. |
| History | Everything that happened to the license: issued, activated, renewed, edited, revoked, with details. |

Rules worth knowing:

- A date you type is valid **through the end of that day** in the site's time zone.
- Setting a future expiry on an expired license makes it active again. A past expiry on an active license
  expires it. A revoked or refunded license is never brought back by an edit: use **Reinstate**.
- Reinstating a license whose expiry has passed leaves it expired until it is renewed.
- Lowering the site limit does not deactivate sites that are already active. It blocks new ones.
- Revoking, refunding and reinstating ask for no extra confirmation in bulk, but single revoke and
  refund ask first. Every change is recorded in the license's history.

The product filter and the search box work like the status links: they change the address, so a view can be bookmarked
or shared, and bulk Revoke and Reinstate bring you back to the same view (same filter, search, sort and page).

## Add license

Issue a license by hand (gifts, support, licenses sold elsewhere). Choose the product, the customer's email
and optionally a WordPress user (so it shows in their account), sites per license, expiry (never, after a
period, or through a date), and whether to email the key to the customer.

## The product screen (tabs)

A product (**License Products**, add or edit) is still a WordPress post, so saving and the file upload work as
before. Under the title, a tab bar shows one section at a time:

| Tab | What is on it |
|---|---|
| **Software** | Name, short description, minimum and tested WordPress versions, logo and banner. Defaults for licenses issued by hand (sites and period). |
| **Plans** | The plans table, the WooCommerce sync, and **Test this plan** / **Publish**. |
| **Releases** | Upload a new zip with notes, the version, and the release history (make an older release current, edit the notes of any release, delete old ones). |
| **Connect** | The checklist and the code to paste into the seller's plugin or theme. The old 1.x API key is here too. |

The last tab is remembered per product, and a link such as `post.php?post=7&action=edit#wplit-plans` opens a
given tab (the Plans actions return there). Without JavaScript every box is shown and the tab bar stays hidden.
The old 1.x boxes and the 1.x save code are gone. Saving is done by `ProductSaver`
(`src/Products`), behind a thin WordPress hook (`ProductSaveHook`) that checks the nonce and the user's rights.

## Releases

Every uploaded zip is kept as a **release**: its version, file, size, upload time, what the zip said about itself,
and notes for customers. The **Release history** box lists them newest first.

- **Notes.** Type what is new in the upload box. If you leave it empty, the entry for this version in the zip's
  `readme.txt` changelog (`= 1.4.2 =` under `== Changelog ==`) is used. Customers see the notes on the
  current release's notes. To fix the notes of any release later, use **Edit notes** on its row in the Release history. Editing the current release changes what customers see at once.
  current release's notes.
- **Current.** Customers always get the **Current** release. A new upload becomes current. The settings the license
  API reads (version, file, requirements, notes) always describe the current release.
- **Roll back.** **Make current** on an older release. New downloads and update checks then serve it. Sites that
  already updated to the newer version stay on it: WordPress does not downgrade on its own, so a bad release also
  needs a fixed newer version.
- **Delete.** Removes an older release and its zip from the server. The current release cannot be deleted, and a zip
  that another release still uses is kept.
- **Deleting a product.** Moving a product to the trash keeps its files, so it can be restored. Deleting it permanently
  (from the trash, or when WordPress empties the trash) removes all its release zips, its logo and its banner from the
  server. Licenses already sold stay in the license list.
- **Warnings.** Uploading a version that already exists, or one older than the current release, still works but says
  that sites on the newer version will not be offered it.
- **Products from before this change** show their one existing release (size and date unknown). It joins the
  history the first time anything is saved.

## Saving a product

Everything typed is cleaned: text has no tags or control characters and is cut to a length, versions keep only
letters, digits and `. _ + -`, and a WordPress version must look like `6.2` or `6.7.1`. A field that is not in the
form is left alone. One thing going wrong never stops the rest of the form from saving; it becomes a message at the
top of the next page (red for what failed, green for what was done, yellow for what is worth a look).

| Upload | What happens |
|---|---|
| **Release zip** | Read first (see above). A zip that is not a plugin or theme is refused and nothing about the release changes. A good one is stored in `uploads/wplit-files/p<ID>/v<version>/<random token>/`, and the version, name, type, folder and requirements are filled in from it. The old release files are kept on disk. |
| **Logo, banner** | PNG, JPEG or WebP, checked from the file's content, stored in `uploads/wplit-files/p<ID>/logo` or `banner`. |
| **Too big** | A file over the server's `upload_max_filesize` or `post_max_size` is reported with the limit. The old code dropped it without a word. |

Products are stored under their ID (`p7`), not their slug, because a draft has no slug yet. Releases uploaded before
this change stay where they are and keep working. The old API key, needed only by 1.x clients, is kept when its
field is left empty, and a product with none gets one. The old code replaced it with a new random key.

The browser test for the tab bar is `tests/js/product-tabs.cjs` (needs Playwright with Chromium and PHP):
`NODE_PATH=$(npm root -g) node tests/js/product-tabs.cjs`.

## Connect box (on the product edit screen)

Shows a checklist of what is left before the product can be sold (zip uploaded, a plan added, a WooCommerce
product for every plan, HTTPS, test purchase, on sale) and the code to paste into the seller's own plugin or
theme. The code has this site's address and the product ID already filled in, and the product name is written
safely, so a name with quotes or backslashes still gives code that runs. The type (plugin or theme), name and
folder come from the uploaded zip when there is one. The plugin version is read from the plugin's own header
when the plugin runs, so it never goes stale. Themes read theirs from `style.css`.

The seller still has to copy the `sdk` folder from the WPLicense It plugin into their own plugin or theme as
`vendor/wplicense-it-client`. There is no download button for it yet.

## Licensing settings

| Setting | Meaning |
|---|---|
| Issue licenses when an order is | WooCommerce: Completed (default) or Processing. |
| Expiry reminders | Email customers before their license expires (off by default), on which days (default `30, 7`), and the renewal address the email links to. See below. |
| Renewal invoices | Create an unpaid renewal order and email WooCommerce's invoice before a license expires (off by default), and how many days before. See below. |
| Failed lookups allowed / time window | How many wrong keys a client may send before it is blocked, and for how long. |
| Visitors reach this site | Directly (default), through Cloudflare, or through another proxy (with the proxies' addresses), so that wrong keys are counted per customer and not per proxy. The screen shows which address the current request is counted as. See `docs/API.md`. |
| Old 1.x API addresses | Whether the `/api/wplicense-it-api/v1/` addresses that plugins released with 1.x use are answered (on by default). Below it, and in Status, how many calls they got in the last 30 days, when the last came and for which products. Switch it off when no calls come: they then answer `410 Gone`. |
| Keep license history for | Years of audit history kept. `0` keeps it forever. |
| Billing details from 1.x orders | Whether the migration keeps address and phone numbers. |
| When the plugin is deleted | **Delete all license data when the plugin is deleted.** Off by default: deleting the plugin from the Plugins page keeps every license, activation, order record and setting, so installing it again picks up where you left off. Turned on, deleting the plugin drops its tables (including the 1.x ones) and settings for good. Products and uploaded release files are WordPress content and stay either way. On a network each site chooses for itself. |

The page also shows the API address and the state of the 1.x data migration.

## Expiry reminders

Off until the site owner turns it on in **Licensing settings > Expiry reminders**, so upgrading never emails customers by surprise.
Once a day (with the daily maintenance below) every active license that expires within the largest number of days gets one
email for the smallest window it has reached: with `30, 7`, a license gets one email within 30 days of its expiry and another
within 7 days, and a license first seen at 5 days gets only the 7 day email. The email is plain text, names the product and the
expiry date, says what expiry means (no more updates) and links to the renewal address. It does not contain the license key.

- **Where the link goes:** the address set in the settings; otherwise, for a license with a customer account and WooCommerce
  active, **My Account > Licenses** (the customer logs in and presses Renew); otherwise the home page.
- **Nothing is sent twice.** Each reminder is recorded in the license history ("Expiry reminder emailed", with the window and the
  expiry date, not the customer's address). A renewal changes the expiry date and starts a new cycle.
- **Skipped:** licenses that never expire, are revoked, refunded or expired, have no valid address, or were erased with the
  WordPress privacy tool.
- **Limits:** at most 500 emails per run; the rest go out the next day. If WordPress cannot send an email it is tried again the
  next day (nothing is recorded).
- **Filters:** `wplicense_it_send_reminder` (return `false` to skip a license), `wplicense_it_reminder_email` (change the
  recipient, subject, message or headers) and `wplicense_it_reminder_renew_url`.

## Renewal invoices

Off until the site owner turns it on in **Licensing settings > Renewal invoices** (needs WooCommerce and a product that sells the
license, with a price). A set number of days before a license expires (default 14, from 1 to 90), once a day, the plugin:

1. creates an **unpaid WooCommerce order** for the renewal, for the same customer (or a guest order for a license without an
   account), with one line for the product that sells the license, marked as a renewal of that license;
2. emails the customer **WooCommerce's own invoice email**, which has the order and a link to pay it. Edit the wording under
   WooCommerce > Settings > Emails > Customer invoice. The customer pays with any payment method the store offers;
3. when the order is paid and reaches the status that issues licenses (Completed, or Processing if you chose that), the
   existing renewal logic extends the license from its current expiry, or from today if it has already expired. **No second
   license is made.**

Good to know:

- **One invoice per expiry date.** It is recorded in the license history ("Renewal invoice sent", with the order number). A
  renewal changes the date and starts a new cycle. An invoice you cancel or delete is not made again for the same date.
- **Renewed another way?** If the license is renewed by a different order (for example the customer used the Renew button), its
  unpaid invoice is cancelled, with a note on the order, so nobody is asked to pay twice.
- **Unpaid after expiry:** the order stays open and paying it later still renews the license (from today, since it expired).
  WooCommerce's automatic cancelling of unpaid orders does not touch these orders.
- **Skipped:** licenses that never expire, are revoked, refunded or expired, have no valid address, or were erased with the
  privacy tool; and licenses whose product has no purchasable WooCommerce product with a price above zero.
- **Limits:** at most 100 invoices per run; the rest are made the next day. A failure to make one is logged, nothing is recorded,
  and it is tried again the next day.
- **With expiry reminders:** the two are independent. The reminder emails link to My Account, the invoice email has the pay link.
  Turn on both for a reminder at 30 days and an invoice at 14, or only one of them.
- **Filter:** `wplicense_it_create_renewal_invoice` (return `false` to skip a license).

This is not automatic charging: the customer still pays each time. Charging a saved card automatically is a separate step.

## Daily maintenance

A daily WP-Cron job marks licenses past their expiry as expired (in batches, so a large backlog cannot slow
a request), sends expiry reminders and makes renewal invoices when they are on, and deletes history older than the retention setting. Licenses are checked against their expiry date
every time they are used, so this only keeps the stored status and the list accurate.

## Accessibility

The screens are scanned with axe-core in the tests (`tests/e2e/14-accessibility.cjs`), and the plugin's own markup has no
findings: every form control has a name (the checkbox of each license row says which license it selects, plan fields say
"Plan 2: Price"), groups of radio buttons have a legend, the status colors and links meet the contrast ratio, the product
tab bar is a named navigation that marks the current section, and success and error messages are announced to screen
readers. Everything can be used with the keyboard alone. Revoking a license asks first, from the license screen, from the
list, and in bulk.

## On a multisite network

Every site of a network is its own license server: it has its own products, licenses, settings, package files and
tables (`wp_2_wplit_licenses` for site 2, and so on), and a license on one site is unknown to the others.

- **Network activation** works. A site's tables are made the first time the site is opened, not when the plugin is
  activated, so a site made later needs nothing.
- **Who may manage licenses:** the administrators of each site (a super admin is not needed). The permission is given
  the first time an administrator opens that site's admin, and only for that site.
- **Deleting a site** deletes its plugin tables, with its licenses and customer emails, and its package folder.
- **Deleting the plugin** from the network removes the tables and settings of every site.
- **WooCommerce** has to be active on the site that sells; the SDK download and the product screen work without it.

## Security

Every action is a nonce-protected request to `admin-post.php` that also checks the capability. Notices after an
action carry a short code in the URL and show fixed messages, never text from the request. Sorting and filtering
use whitelists, and values are prepared before they reach SQL.
