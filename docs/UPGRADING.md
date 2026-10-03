# Upgrading from 1.x to 2.0

2.0 is a rewrite. Your licenses and orders are migrated automatically (see `docs/MIGRATION.md`) and customers'
licenses keep working throughout. **The built-in checkout is gone**, so the way you take payments changes.

## What was removed

| 1.x | 2.0 |
|---|---|
| Built-in Stripe checkout (`[wplit-checkout]`) | Sell with **WooCommerce** and any of its payment gateways. |
| Product cards with a buy button (`[wplit-product]`) | WooCommerce product pages. |
| Order and license emails from the plugin | WooCommerce's emails, with the license keys added. |
| The 1.x Dashboard and Settings screens | **Licenses**, **Add license** and **Licensing settings** (see `docs/ADMIN.md`). |
| Price and expiry fields on the product | The price lives on the WooCommerce product. Default sites and period are on the license product. |

`[wplit-licenses]` still works: it lists the logged-in customer's licenses with a download link. With WooCommerce
the same information is in **My Account > Licenses**.

Pages that used `[wplit-product]` or `[wplit-checkout]` now print nothing to visitors, so they do not show raw
shortcode text. Administrators see a note telling them to replace it.

## What to do

1. Install and set up WooCommerce, and a payment gateway (for Stripe, WooCommerce's own Stripe gateway).
2. Your old Stripe keys are still stored. Copy what you need into the gateway, then use **Delete the stored Stripe
   keys** in the notice at the top of the admin screens.
3. For each license product, create a WooCommerce product and choose the license product under **WPLicense It**
   on its General tab (see `docs/WOOCOMMERCE.md`). Tick **Virtual**.
4. Replace the old pages: use your WooCommerce shop, and remove the `[wplit-product]` and `[wplit-checkout]` shortcodes.
5. Plugins and themes you sell that check for updates keep working through the old `/api/wplicense-it-api/...`
   URLs. For new releases, use the client SDK (`sdk/README.md`).

## Existing customers

Nothing to do. Their keys, expiry dates and download links are unchanged. They get unlimited activations, as in 1.x.
New purchases get the site limit you set on the product.

## If you cannot move to WooCommerce yet

Stay on 1.0.2 until you can. 2.0 has no built-in way to take payments, but you can issue licenses by hand
(**Licenses > Add license**) or build your own integration on the licensing core.
