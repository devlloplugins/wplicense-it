# Quick start for sellers

From nothing to a customer with a working license and updates. About 20 minutes. The details of each screen are in
`ADMIN.md` and `WOOCOMMERCE.md`; the code side is in `sdk/README.md`.

**You need:** WordPress 6.2+ on PHP 8.0+, served over HTTPS (your customers' sites refuse a license server without it),
WooCommerce to sell, and your plugin or theme as a zip.

## 1. Install

**Plugins > Add New Plugin > Upload Plugin**, choose `wplicense-it.zip`, **Install Now**, then **Activate**. Open **License Products > Overview**: the
**Getting started** list there follows the same steps as this page and ticks them off as you go.

## 2. Add your product

**License Products > Add New Product.** Give it the name customers will see and click **Publish**. Four tabs appear
under the title: Software, Plans, Releases and Connect.

1. **Releases** tab: under **Upload a new release (zip)** choose your zip and add a line of notes (or leave them empty
   to use the changelog in the zip's `readme.txt`). Click **Update**. The version, folder name and WordPress and PHP
   requirements are read from the zip; any problem is shown at the top of the page.
2. **Software** tab: check what was filled in, add a short description, logo and banner if you like.

## 3. Sell it

1. **Plans** tab: one row per thing customers can buy, for example *Single site*, 49, 1 site, 1 year and *Agency*, 199,
   0 sites (unlimited), 1 year. Click **Update**. Each plan becomes a WooCommerce product, as a draft.
2. Click **Test this plan** on each row. It runs the real purchase and license code on an order that is never saved
   and lists seven checks. Fix anything it reports, then click **Publish in the shop**. The plan is now for sale.

When a customer pays online (card, PayPal), WooCommerce completes the order and the license is issued straight away:
the key is in the order email, on the order page and in **My Account > Licenses**, with the download. Orders paid by
bank transfer, check or cash on delivery get their license when you complete the order. Refunds and cancellations end
the license on their own.

No WooCommerce? Licenses can still be issued by hand under **License Products > Add license**.

## 4. Connect your plugin or theme

**Connect** tab of the product. The quickest way:

1. Click **Download an example plugin**. It is a small working plugin for this product, with the client SDK and the
   code already filled in.
2. Install it on a test site and activate it. Issue yourself a license (**License Products > Add license**, this
   product, your email) and enter the key on the example's license page. The license screen on your server now lists
   the test site.
3. Copy the `require_once` and the `wplicense_it_client( ... )` call (both are shown in the Connect tab, ready to
   paste) into your own plugin's main file or your theme's `functions.php`, and put the SDK folder
   (**Download the SDK**) next to it. Use `wplicense_it_license_active()` wherever a feature needs a license.

Your customers then get a license page (**Settings > *Your Plugin* License**), a daily license check, and updates in
the normal **Dashboard > Updates** screen.

## 5. Ship an update

Raise the version in your plugin header (and `Stable tag` in its readme), zip it, and upload it on the product's
**Releases** tab. Customers with an active license are offered it within a day (or at once when they click **Check
again** on the Updates screen). If a release is bad, **Make current** on the previous one in the Release history.

## Before you go live

- **Licensing settings**: expiry reminder emails and renewal invoices are off until you turn them on.
- Behind Cloudflare or another proxy, set **Visitors reach this site** in Licensing settings, so the wrong-key limit
  counts each customer separately.
- Coming from WPLicense It 1.x: the Overview shows the data migration. Old licenses keep working; see `UPGRADING.md`.
