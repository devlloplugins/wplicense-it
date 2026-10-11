# Admin screens

Built with native WordPress components (the Settings API, `WP_List_Table` and standard admin styles).
They live under **License Products** in the admin menu and need the `manage_wplicense_it` capability,
which administrators get automatically. Add roles with the `wplicense_it_manage_roles` filter.

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

## Add license

Issue a license by hand (gifts, support, licenses sold elsewhere). Choose the product, the customer's email
and optionally a WordPress user (so it shows in their account), sites per license, expiry (never, after a
period, or through a date), and whether to email the key to the customer.

## The product screen (tabs)

A product (**License Products**, add or edit) is still a WordPress post, so saving and the file upload work as
before. Under the title and description, a tab bar shows one section at a time:

| Tab | What is on it |
|---|---|
| **Software** | Name, short description, minimum and tested WordPress versions, logo and banner. Defaults for licenses issued by hand (sites and period). |
| **Plans** | The plans table, the WooCommerce sync, and **Test this plan** / **Publish**. |
| **Releases** | Upload a new zip, the version, and the stored file with what was read from it. |
| **Connect** | The checklist and the code to paste into the seller's plugin or theme. The old 1.x API key is here too. |

The last tab is remembered per product, and a link such as `post.php?post=7&action=edit#wplit-plans` opens a
given tab (the Plans actions return there). Without JavaScript every box is shown and the tab bar stays hidden.
The old 1.x boxes were removed; the save code in `admin/wplicense-it-product-admin.php` is unchanged, and a test
checks that every field it reads has an input on these tabs.

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
| Failed lookups allowed / time window | How many wrong keys a client may send before it is blocked, and for how long. |
| Keep license history for | Years of audit history kept. `0` keeps it forever. |
| Billing details from 1.x orders | Whether the migration keeps address and phone numbers. |

The page also shows the API address and the state of the 1.x data migration.

## Daily maintenance

A daily WP-Cron job marks licenses past their expiry as expired (in batches, so a large backlog cannot slow
a request) and deletes history older than the retention setting. Licenses are checked against their expiry date
every time they are used, so this only keeps the stored status and the list accurate.

## Security

Every action is a nonce-protected request to `admin-post.php` that also checks the capability. Notices after an
action carry a short code in the URL and show fixed messages, never text from the request. Sorting and filtering
use whitelists, and values are prepared before they reach SQL.
