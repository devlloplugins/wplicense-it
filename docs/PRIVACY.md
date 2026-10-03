# Privacy

WPLicense It plugs into WordPress's **Tools > Export Personal Data** and **Tools > Erase Personal Data**, and adds suggested
text for the site's privacy policy (**Settings > Privacy > Policy Guide**). The tools work on an email address; the plugin also
finds licenses linked to the WordPress account that uses that address.

## What is stored about a person

| Where | What | Why |
|---|---|---|
| Licenses | The email address and WordPress account the license was issued to, the license key, the product, status and dates | To provide the license and updates, and to support the customer |
| Activations | The addresses of the sites the license is activated on, the plugin version they reported, the dates | To enforce the site limit and let customers move a license |
| License history | What happened to the license (issued, activated, renewed, edited, revoked) with dates | Support, and answering "why does my license not work" |
| License orders | Order number, total, currency, status, date, and the account | Accounting |
| Orders migrated from 1.x | Also the name, company, address, phone number and email from the checkout (can be switched off before migrating, **Licensing settings**) | Keeping the seller's existing records |
| The 1.x tables | The same data as above, kept after the migration until you remove them | A way back to 1.x |
| Rate limiting | Client IP addresses, only as a hash in a temporary record, for a few minutes, and only after wrong license keys | Stopping key guessing |

Payment details (cards, billing addresses entered at WooCommerce checkout) are handled by WooCommerce and your payment
gateway, not by this plugin. WooCommerce has its own export and erase tools for them.

## Exporting

The export lists, for the person: every license (key, email, product, status, sites allowed, dates), each site it was activated
on, its history, the order records with totals and any billing details, and the old 1.x records. Licenses are exported in
pages of 25.

## Erasing

Erasing **removes the identity and keeps the license**:

- the email on each license becomes a placeholder (`erased-<id>@erased.invalid`) and the account link is removed;
- email addresses are removed from the license history;
- name, company, address, phone and email are removed from order records, and the account link is removed;
- the same is done to the 1.x tables.

**Kept:** the license key, product, status and dates, the addresses of activated sites, and the order numbers and totals.
They are needed to support the license and for accounting, and the tool tells the administrator that it kept them. If a
person asks for the license itself to stop, **revoke** it too (Licenses > Manage > Revoke).

After erasing, the license still validates (its key is the credential), but 1.x clients that also send an email address no
longer match, because the email is gone.

## History retention

License history older than the retention period (default 2 years, **Licensing settings**) is deleted by a daily job.
`0` keeps it as long as the license exists.

## The client SDK

Plugins and themes that embed the SDK send the license key, the product, the site address and the installed version to the
seller's license server when the license is activated, checked (daily) and when updates are looked up. Nothing else about the
site is sent. Sellers should mention this in their own privacy policy; the SDK's `README.md` says so.
