# WooCommerce adapter

WPLicense It sells nothing itself in 2.0. WooCommerce takes the payment, and the adapter turns paid
orders into licenses. It loads only when WooCommerce is active, and works with the classic and the
high-performance (HPOS) order storage.

## Plans (the recommended way)

Open a product under **License Products**. The **Plans** box lists what customers can buy, one row each:
name, price, sites per license (0 is unlimited) and how long the license lasts.

Saving the product creates **one simple WooCommerce product per plan**, as a **draft**, marked virtual and
linked to the license product with the plan's terms. Later saves keep the price and terms current.

| Situation | What happens |
|---|---|
| You change a plan's price or terms | They are sent to WooCommerce on save. |
| Someone changes a price in WooCommerce | Nothing is overwritten. The row shows both prices and asks: **keep the plan price** or **use the WooCommerce price**. |
| Both prices changed | The plan's price wins. |
| Someone edits a plan's license terms in WooCommerce | They are put back to the plan's terms on the next save. |
| A plan's WooCommerce product is deleted | The row says so. **Create it again** makes a new draft. |
| You clear a plan's row | Its WooCommerce product is set back to **draft**, so it can no longer be bought. It is not deleted. Existing licenses keep working. |
| A save is refused (bad price, duplicate name) | Nothing is saved, and every mistake is listed. |

A plan can be **published** in the shop only after a test purchase of it has passed. The test purchase button
is not built yet, so for now publish the draft from WooCommerce itself.

Prices are compared in minor units (cents) using the store currency, so 49 and 49.00 are the same price.
A sale price set in WooCommerce is ignored: only the regular price is managed.

## Setting up a product by hand

Edit a WooCommerce product, General tab, **WPLicense It** section:

| Field | Meaning |
|---|---|
| WPLicense It product | The plugin or theme this product sells a license for. Empty means not a licensed product. |
| Sites per license | How many sites one license covers. Empty uses the license product default (1). `0` is unlimited. |
| License period | Lifetime, 1/3/6 months, 1/2 years. Empty uses the license product's period. |

Variations have their own **Sites per license** and **License period**, so one product can offer
"1 site", "5 sites" and "Unlimited" plans. The most specific setting wins: variation, then product,
then the license product's defaults, then 1 site and lifetime. Tick **Virtual** on licensed products so
WooCommerce does not ask for shipping.

The terms are copied onto the order line when the order is created, so editing a product later never
changes what earlier customers were sold.

## What happens to an order

| Order event | Result |
|---|---|
| Reaches **Completed** (or **Processing** if the `wplit_woo_issue_on` option is `processing`) | One license per unit bought. Keys appear on the order page, in the customer's email, and in My Account. |
| Completed again, or the event fires twice | Nothing. Licenses are recorded per order line, so none are issued twice. |
| **Refunded** | The order's licenses become `refunded` and stop validating. |
| **Cancelled** | The order's licenses are revoked. |
| Partial refund | As many licenses as units refunded are ended, newest first. |

Every action is written to the order as a note. A failure never breaks checkout or the status change:
it is logged, noted on the order, and changing the status again retries it.

A license that was revoked by hand is never overwritten by a later refund or cancellation. Moving a
refunded order back to Completed does not bring the licenses back: issue a new one by creating a new order.

Developers can change the trigger statuses with the `wplicense_it_woo_issue_statuses` filter.

## Renewals

Renewal is by repurchase. In **My Account > Licenses** each expiring license has a **Renew** button.
It adds the product to the cart marked as a renewal. When that order completes, the existing license is
extended instead of a new one being issued:

- from its current expiry, or from today if it had already expired (an expired license becomes active again);
- only for the license's owner, for the product it belongs to, and with a nonce tied to that license;
- a renewal that stopped being valid by the time the order completes (for example the license was
  revoked) falls back to issuing a new license, and says so in the order note;
- buying a product again from the shop (not through Renew) always issues a new license, so customers can
  buy a second license on purpose.

Renew buttons are shown for simple products. For variable products the customer renews from the product page.
Automatic recurring billing is not part of the free core (see `docs/DECISIONS.md`, decision 8).

## My Account

**Licenses** shows each license's key, status, expiry, sites in use, a **Download** button (a link that
expires after 15 minutes) and a **Deactivate** button per site, so customers can move a license themselves.

## Data

One row per WooCommerce order in `wplit_license_orders` (`source` = `woocommerce`, `order_number` =
`WC-<number>`). The table has a single product column, so an order with several licensed products
records the first; every license points back to its order.
