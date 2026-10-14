# WPLicense It client SDK

Add license activation and automatic updates to your own WordPress plugin or theme, backed by your WPLicense It server.

You get, with a few lines of code:

- a license page where customers enter, activate and deactivate their key;
- updates in the normal WordPress **Updates** screen, with the "View details" popup, delivered only to customers with an active license;
- a daily license check, and a notice on the Plugins screen when the license is not active;
- nothing else: no tracking, no ads, no extra settings.

Requires PHP 7.4+ and WordPress 5.8+ on the customer's site. Your server (the site running WPLicense It 2.0) needs HTTPS.

## Install

1. Copy this `sdk` folder into your plugin or theme (for example `my-plugin/vendor/wplicense-it-client/`).
2. In your main plugin file (or the theme's `functions.php`), include it and register your product:

```php
require_once __DIR__ . '/vendor/wplicense-it-client/wplicense-it-client.php';

wplicense_it_client( array(
	'file'       => __FILE__,                  // The main plugin file.
	'server'     => 'https://your-shop.com',   // Your WPLicense It site.
	'product_id' => 7,                         // The product's ID (Products > edit > the number in the URL).
	'name'       => 'My Plugin',
	'version'    => '1.2.0',                   // The version being installed. Keep it in sync with your plugin header.
) );
```

Themes use `'type' => 'theme'` and `'slug' => 'my-theme'` (the theme folder name) instead of `'file'`.

That is all. Customers now find **Settings > My Plugin License**.

## Options

| Option | Meaning |
|---|---|
| `file` | Main plugin file (`__FILE__`). Plugins only. |
| `type`, `slug` | Themes: `'type' => 'theme'` and the theme's folder name. |
| `server` | Your shop's URL. HTTPS only (plain HTTP is accepted for `localhost`, `*.test`, `*.local` while developing). |
| `product_id` | The product's ID on your server. |
| `name` | Name shown to customers. |
| `version` | Installed version. |
| `menu` | `false` for no license page (build your own, see below), or `array( 'parent' => 'tools.php', 'title' => 'License' )`. Default: a page under Settings. |

## Checking the license in your code

```php
if ( wplicense_it_license_active( 'my-plugin' ) ) {
	// Pro features.
}
```

The argument is your plugin's folder name (or the theme's folder name). The result is what the site last learned from your server. A license that has passed its expiry date counts as inactive immediately.

## Your own settings screen

If you do not want a separate page, pass `'menu' => false` and print the form inside your own settings page:

```php
$client = \WPLicenseIt\Client\Client::get( 'my-plugin' );
$client->render_license_form();
```

`$client->activate( $key )`, `->deactivate()`, `->license()` and `->is_active()` are available for fully custom screens.

## Cleaning up

In your plugin's `uninstall.php`, remove what the SDK stored:

```php
// The option and cached data are named after your plugin's folder.
delete_option( 'wplit_client_my-plugin' );
delete_transient( 'wplit_c_my-plugin_update' );
```

## Rules the SDK follows

- **It never downgrades a customer because of a network problem.** A license only becomes inactive when your server says so (expired, revoked, refunded, unknown key, or the site was deactivated). If your server is down or slow, nothing changes.
- **Renewing just works.** An expired license keeps its key. When the customer renews, the next daily check (or **Check now**) makes it active again.
- **Download links never expire on the customer.** Update data only holds a placeholder. The real, 15-minute download link is requested when the customer clicks Update.
- **The update replaces the installed copy.** If your zip unpacks to a differently named folder, the SDK renames it to your plugin's folder name.
- **Several plugins can include the SDK.** Only the newest copy on the site is loaded.

## What is sent to your server

Only: the license key, the product ID, the site's address (`home_url()`) and the installed version. Nothing about the WordPress site, its users or its content. If you publish a privacy policy, mention that the plugin contacts your license server to activate the license and check for updates.

## Packaging your updates

Upload the zip for each release on the product's edit screen in WPLicense It, and set its version. The zip should unpack to a folder named like your plugin (`my-plugin/`), with the plugin header's version matching.
