=== WPLicense It ===
Contributors: devlloplugins, femiyb
Tags: license, license key, software license, woocommerce, updates
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell and manage license keys, activations, renewals and updates for your WordPress plugins and themes, with WooCommerce.

== Description ==
WPLicense It is for developers who sell WordPress plugins and themes. It issues license keys, limits how many sites each key may be used on, renews and ends licenses, and lets your customers' sites check their license and receive updates from your own site.

= What it does =

* **Licenses**: issue, renew, revoke, refund and reinstate licenses, with an activation limit per license, local and staging sites that do not use a slot, and a history of what happened to each license.
* **Selling with WooCommerce**: add plans (price, sites, duration) to a product and WPLicense It creates the WooCommerce products. Paid orders become licenses, refunds and cancellations end them.
* **Renewals**: a Renew button in My Account, expiry reminder emails, and optional renewal invoices (an unpaid renewal order with a pay link, paid with any payment method your store offers).
* **Updates**: upload releases (zip files) to a product. Customers' sites receive updates through a drop-in PHP client (SDK) that you add to your plugin or theme. Download links are signed and expire after 15 minutes, and the license is checked again when one is used.
* **REST API**: validate, activate and deactivate licenses and check for updates at `/wp-json/wplicense-it/v1/`.
* **Overview screen**: what needs attention (for example a paid order that could not get its license), the setup steps that are left, how many licenses are in each state, and the ones about to expire.
* **Privacy**: works with the WordPress personal data export and erase tools, and adds suggested text for your privacy policy.
* **Upgrading from 1.x**: licenses and orders are moved to the new tables in the background, and the old API addresses keep working.

= Links =

[Documentation](https://wplicenseit.com/docs/) and [website](https://wplicenseit.com/).

= Requirements =

PHP 8.0 or newer and WordPress 6.2 or newer. WooCommerce is needed to sell licenses from your store; without it you can still issue licenses by hand. The client SDK in the `sdk` folder runs on PHP 7.4 and newer, because it is added to your customers' sites.

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/wplicense-it` directory, or install the plugin through the WordPress Plugins page.
2. Activate the plugin through the Plugins page.
3. Open **License Products > Overview** and follow the steps: create a product, upload a release, then issue a license to try it.
4. To sell with WooCommerce, add a plan to the product. To let your plugin or theme check licenses and update itself, download the SDK from the product's **Connect** box and add it to your code.

== External services ==
WPLicense It does not send any data to the plugin authors or to any third party.

* When you press **Test this plan** in the product's Plans box, the plugin requests this site's own license API address (a request from the site to itself) to check that it answers. Nothing leaves your site.
* The client SDK that you can download is for **your** plugin or theme. When it runs on a customer's site it contacts **your** license server, which is the site where WPLicense It is installed: it sends the license key, the product, the customer's site address and the installed version, so that the license can be checked and updates found. The SDK is not active on the site where WPLicense It is installed.

== Screenshots ==
1. Add a license product

== Frequently Asked Questions ==

= Who can use WPLicense It? =
Developers and companies that sell WordPress plugins or themes and want to manage licenses and updates for them.

= Does it work with themes as well as plugins? =
Yes. The same product can be a plugin or a theme.

= Do I need to write code? =
Selling and managing licenses needs no code. To let your plugin or theme check its license and update itself you add the client SDK to it, which needs a few lines of PHP. See the Connect box on the product screen.

= Can I sell without WooCommerce? =
Not through this plugin. Licenses can be issued by hand (**License Products > Add license**), but selling and renewals use WooCommerce.

= What happens to my 1.x data? =
It is moved to the new tables automatically in the background, with a notice if anything could not be moved. The old API addresses keep working.

= I found a bug in the plugin. =
Please post it in the [WordPress support forum](https://wordpress.org/support/plugin/wplicense-it/) and we will look into it as soon as possible.

== Changelog ==

= 2.0.0 =
* New licensing core with activation limits, renewals, refunds, and a history for each license.
* New REST API and a drop-in client SDK for plugins and themes.
* WooCommerce plans, renewal button, expiry reminder emails and renewal invoices.
* New admin screens: Overview, Licenses, Add license and Licensing settings.
* Paid orders that could not get their license are shown on the order and on the Overview, with a Try again button.
* Works with the WordPress personal data export and erase tools.
* Safer package storage: files are kept in random folders, so they stay out of reach on servers that ignore .htaccess.
* Your 1.x licenses and orders are migrated automatically and keep working.
* Removed: the built-in Stripe checkout and the [wplit-product] and [wplit-checkout] shortcodes. Sell licenses with WooCommerce instead. Your stored Stripe keys are kept until you delete them, with the button in the notice shown after the upgrade.
* Requires PHP 8.0 and WordPress 6.2.

= 1.0.2 =
Security and stability release.
* Fix: payments are only treated as successful when Stripe confirms the charge.
* Fix: checkout no longer fails with an undefined redirect, and redirects are validated.
* Fix: license API validates input, handles unknown products, and only serves files from the WPLicense It folder.
* Fix: product edits now require permission to edit the product, and file uploads work on PHP 8.
* Fix: uploaded product files are no longer copied to the public uploads folder.
* Fix: Stripe secret keys are no longer printed in the settings page.
* Fix: escaped output on product, license and checkout pages.
* Fix: emails no longer change the content type of other emails sent by the site.
* Fix: .htaccess protection for product files and database version check.

= 1.0.1 =
Bug fix: Activation Functions.

= 1.0 =
Fix issue with Checkout and Stripe Tokens

= 0.9 =
Initial release.

== Upgrade Notice ==

= 2.0.0 =
A large update. Your 1.x licenses and orders are moved to new tables in the background. Back up your database before updating. The built-in Stripe checkout and the [wplit-product] and [wplit-checkout] shortcodes are gone: sell with WooCommerce instead.
