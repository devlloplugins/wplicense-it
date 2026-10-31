# Translating WPLicense It

Everything a site owner or shop manager sees in the WordPress admin, in order notes and in emails is translatable, with the
text domain `wplicense-it`.

## Making a translation

1. Start from `languages/wplicense-it.pot` (for example in Poedit, "New from POT/PO file"), and save it as
   `wplicense-it-<locale>.po`, for example `wplicense-it-de_DE.po`.
2. Compile it to `wplicense-it-<locale>.mo` (Poedit does this when it saves; with WP-CLI:
   `wp i18n make-mo wplicense-it-de_DE.po`).
3. Put the `.mo` file in the plugin's `languages` folder. WordPress shows it to anyone whose site or profile language is that
   locale.

2.0 is not hosted on wordpress.org (decision 21), so WordPress does not download translations for it; the plugin loads the
files in its own `languages` folder (on `init`, before anything registers labels).

## What stays in English

- **The license API's answers to client plugins and themes** (error messages in the REST API and the 1.x addresses). Those
  are read by customers' sites, in whatever language they use, so they keep one stable language. Clients that show them to
  people should translate them by their `code`.
- **The client SDK** (`sdk/`). It runs inside sellers' plugins and themes, under their own text domain.
- **WP-CLI output** (`wp wplit migration ...`), and internal errors that are only written to the PHP error log.
- **The example plugin** made from the Connect box: it is code for the seller to edit.

## Keeping the template current

`bin/make-pot.sh` writes `languages/wplicense-it.pot` with WP-CLI's `wp i18n make-pot` (tests, tools, docs and the SDK are
left out). CI runs `bin/make-pot.sh --check` and fails when the file is out of date, so run the script and commit the result
whenever a text changes. Every text with a placeholder has a `translators:` comment, which ends up in the template for the
translator (phpcs checks this).
