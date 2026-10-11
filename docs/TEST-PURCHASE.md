# Test purchase (design)

Status: the seven checks are built (`src/TestPurchase`). The Test link, results panel and Publish gate are not
(board card 122). Decisions 16 to 18 in `DECISIONS.md`.

## Purpose

Before a plan goes on sale, show that a buyer ends up with a working license. A **Test this plan** link in each
row of the Plans table runs a dry run and lists the result in plain words. A pass unlocks **Publish in the shop**
for that plan.

## Dry run

The dry run uses the real purchase code with nothing saved: an order built in memory (never saved to WooCommerce)
and in-memory copies of the storage (`src/DryRun`). The real license service, order processor and API logic run
over that storage. It writes no order, no license rows and no emails, appears in no sales
report, and needs no cleanup.

| # | Check | Fails when |
|---|---|---|
| 1 | The WooCommerce product exists, is a simple product, and is linked to this software | It was deleted, changed to another type, or points elsewhere |
| 2 | The product's sites and period equal the plan's | They were edited in WooCommerce |
| 3 | The software has a zip and a version; the file exists and opens as a zip | No upload, file missing from disk, or not a zip |
| 4 | Buying one unit issues one license with the plan's sites and the right expiry | The order code finds the wrong terms |
| 5 | The license validates, activates up to its limit, refuses the next site, and frees a slot on deactivation (skipped for unlimited) | The activation rules misbehave |
| 6 | A signed download link verifies and points at the real file | The file path or signing is wrong |
| 7 | This site's own license API answers a request made by the server itself | A firewall, security plugin or host blocks it. **Warning only.** |

Each failure says what to fix. Checks 1 to 6 must pass; check 7 only warns.

## What it does not test

The payment gateway, checkout, email delivery, real database writes, or that a customer's site runs the client
code. Database writes are covered by trying the plugin on a real site (board card 118).

## When Publish unlocks

A pass stores a fingerprint: a hash of the software ID, the WooCommerce product ID, sites, period and the package
file name, with the time. Publish works only while the stored fingerprint equals the current one. Changing any of
those, or uploading a different zip file, cancels the pass. Changing the price does not.

## How it was built

- **The order.** `WcOrderBuilder` reads the license terms of the real WooCommerce product with
  `OrderReader::current_terms()`, the same code a real order uses, and builds the order data itself. It does not
  use a WooCommerce order object: adding a product to one normally saves it, and a test must write nothing. This
  replaces the design's "unsaved `WC_Order`" idea, so that assumption is no longer needed.
- **Check 7.** The probe requests the license API's route index (`/wp-json/wplicense-it/v1`) from the server. It
  needs no license key and counts as no failed lookup, so it can never lock anyone out. A wrong-key request would
  have counted against the server's own address.
- **Check 3** also fails if the license product is not published, because the API reports an unpublished product
  as unknown.
- **Checks 5 and 6** run through the real `ApiService` over the sandbox, with a throwaway download signing secret.
  The site limit is tried only for plans of 1 to 10 sites. Unlimited plans, and plans of more than 10 sites, get
  one activation and the report says the limit was not tried.
- A check whose earlier steps failed is shown as skipped, and a skipped check counts as not passed.

## Not built

A live test order (a real WooCommerce order that is completed and deleted). It would also touch reports, emails
and webhooks, and licenses cannot be deleted today.
