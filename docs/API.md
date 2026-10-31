# WPLicense It 2.0: REST API

Base URL: `https://your-site.com/wp-json/wplicense-it/v1`

All routes are public. The license key is the credential. Requests that change anything use `POST`
with a JSON or form body, so keys never appear in URLs or server logs. Responses are JSON and are
never cached (`Cache-Control: no-store`).

## Responses

Success: `{ "success": true, ... }`. Failure: `{ "success": false, "code": "...", "message": "..." }`.

| Status | Codes | Meaning |
|---|---|---|
| 200 | `ok`, `activated`, `already_active`, `deactivated` | Done. |
| 400 | `invalid_request`, `invalid_site` | Missing or malformed parameter. |
| 403 | `expired`, `revoked`, `refunded`, `limit_reached`, `invalid_token` | The license (or link) is not usable. |
| 404 | `not_found`, `not_active`, `no_package` | Unknown key, a site that is not activated, or no file uploaded. |
| 429 | `rate_limited` | Too many invalid keys. See the `Retry-After` header. |

A wrong product or email is reported as `not_found`, the same as an unknown key, so the API does not
reveal whether a key exists. Failed lookups are counted per client IP: 20 failures in 15 minutes
blocks that client for the rest of the window. Expired, revoked and refunded licenses are not
counted, because the key was right.

The license object: `status`, `expires_at` (ISO 8601 UTC, or `null` for lifetime), `activation_limit`
(`0` is unlimited) and `activations_used`.

## Endpoints

### `POST /licenses/validate`
`license_key`, `product_id`, `email` (optional), `site_url` (optional). Returns `{ success, code: "ok", license }`. When `site_url` is sent the response also has `site_active`: whether that site is still activated on the license (and the check-in is recorded), and `site_local`: whether the server treats the site as a local or staging site, which does not use a slot.

### `POST /licenses/activate`
`license_key`, `product_id`, `site_url`, `product_version` (optional). Uses one slot, unless the site
is already active (`already_active`) or is a local or staging site, which never uses a slot. At the
limit it returns 403 `limit_reached`, with `sites` (the addresses that use a slot; local and staging sites do not) and `account_url` (My Account, Licenses, or empty without WooCommerce): the caller has the key, so it may know where the license is used. Returns `{ success, code, license, activation: { site, status, local } }`.

### `POST /licenses/deactivate`
`license_key`, `site_url`. Frees the slot. Works on expired licenses too, so customers can always move a license.

### `POST /updates/check`
`license_key`, `product_id`, `current_version` (optional). For a valid license returns the product
(`name`, `version`, `requires`, `tested`, `description`, `changelog` (plain text lines for this version, empty if none), `banners`), `update_available` (`null` if no
`current_version` was sent), a `download_url` valid for 15 minutes, and `expires_in`. The product also has
`requires_php` (empty if not set).

For an expired license the answer is `403` with `code` `expired`, and it still has the `product` (so the customer can be
told which version they would get) and a `renew_url` (My Account, Licenses), but no `download_url`. Other unusable
licenses get the plain `403` without the product.

### `GET /download?token=...`
Streams the zip. The token comes from `/updates/check`. It is signed, expires after 15 minutes, and the
license is checked again when it is used, so revoking a license stops downloads that were already linked.

## Deprecated: 1.x endpoints

`/api/wplicense-it-api/v1/{info|get|status}?p=&e=&l=&k=` keep working with the same response shapes
(always HTTP 200 except for rate limiting and a missing file). They are served from the 2.0 tables once
the 1.x data has been migrated, until then by the 1.x code. They do not record sites and ignore
activation limits, because 1.x clients send no site. New integrations should use the routes above.

Before and after the migration, wrong keys count against the client's limit (an expired right key does not), calls are counted
per product for the last 30 days (shown in **Licensing settings > Status** and on the Overview), and the site owner can switch
the addresses off (**Licensing settings > API protection > Old 1.x API addresses**). Switched off, every 1.x address answers
`410 Gone` with `{"error":"This address is no longer available. Update the plugin or theme to keep receiving updates."}`.

## Configuration

- **Licensing settings > API protection > Visitors reach this site** decides which address a client is counted as.
  **Set it if the license server is behind a proxy or CDN such as Cloudflare.** Otherwise every customer shares the
  proxy's address, and one attacker sending wrong keys can block everyone on that address.
  - *Directly* (default): `REMOTE_ADDR`. Forwarding headers are ignored, because anyone can send them.
  - *Through Cloudflare*: `CF-Connecting-IP`, read only when the request came from one of Cloudflare's published
    ranges (built in; replace them with the `wplicense_it_cloudflare_ranges` filter). A request from anywhere else is
    counted as its own address.
  - *Through another proxy or load balancer*: `X-Forwarded-For`, read only when the request came from one of the
    proxies listed (addresses or ranges such as `10.0.0.0/8`). The client is the rightmost address in the header that
    is not a listed proxy, so what a client writes on the left of the header does not matter.
  The settings screen shows the address it counts the current request as, to check the choice.
- `wplicense_it_client_ip` filter: has the last word on the address, for setups the setting does not cover.
