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

## Not yet replaced

The 1.x dashboard and settings pages (checkout page selection and Stripe keys for the old checkout) are still
there until the 1.x checkout is removed.
