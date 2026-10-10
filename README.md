# Flex SEO

Search engine optimisation for Flex CMS: titles, meta descriptions, canonical URLs, Open Graph and X (Twitter) cards, Schema.org structured data, `sitemap.xml`, `robots.txt` and content checks for pages and Flex Commerce products.

Version: **0.2.0** · ID: `flex/seo` · Minimum Flex CMS version: **0.1.59**.

GitHub repository: `techsolutionbg/flex-seo-plugin`.

## Requirements and installation

Requires an installed Flex CMS 0.1.59 or later with PHP 8.3+ and its database. Version 0.1.59 adds the `public.title` filter used for the document title. **Flex Commerce 0.2.5+** is optional: when it is active, product, category and catalogue pages get their own tags and appear in the sitemap. A theme must print `head_tags` in `<head>` and use `document_title` for `<title>`; Flex Starter 1.0.7 does both.

Place the package in `plugins/flex/seo/`, with `plugin.json` directly inside that folder. From the Flex CMS root:

```sh
php bin/flex plugin:install flex/seo
php bin/flex plugin:activate flex/seo
```

With Docker, prefix the commands with `docker compose exec app`. Installation, activation and updates create the `flex_seo_meta` and `flex_seo_settings` tables when they are missing. The plugin requests the `admin.ui`, `events.listen`, `routes.admin`, `routes.public` and `frontend.assets` permissions.

### Updating from 0.1.x

Version 0.1.x stored values in `data/seo.json`. On update the values are imported into the database once and the file is renamed to `data/seo.json.migrated`. Its separate "no index" option is moved to the core page setting `no_index`, which is now the only one. Deactivate the plugin before updating so the new permissions can be approved.

## What it adds

- SEO fields in the page editor.
- An administration page **SEO** in the content group, with an overview, an editor and settings.
- Tags in the public `<head>` of pages and storefront pages.
- `/sitemap.xml` and `/robots.txt` at the site root.

## Fields

Each page and product can have:

| Field | Maximum | When empty |
| --- | ---: | --- |
| SEO title | 190 | The title template from the settings |
| Meta description | 320 | The start of the page content or the product description, up to 160 characters |
| Focus keyword | 190 | Used only by the checks |
| Social title (`og:title`) | 190 | The SEO title |
| Social description (`og:description`) | 320 | The meta description |
| Social image | media library image | The featured image, the first product image or the default image |
| Canonical URL | 500 | The page's own public address |

The canonical URL accepts a site path such as `/about` or an HTTP/HTTPS address. Products are edited only on the **SEO** page; pages can also be edited in the page editor. Whether a page is indexed comes from the core page setting `no_index` (the "do not index" checkbox in the page settings). Pages whose core setting `show_in_sitemap` is `false` are left out of the sitemap.

## Output in `<head>`

Through the `public.head` and `public.title` filters the plugin adds:

- `<title>` from the SEO title or the title template, for example `{title} | {site}`
- `<meta name="description">`
- `<link rel="canonical">` with an absolute URL
- `<meta name="robots">` for pages with `no_index`, filtered or searched catalogue listings, error pages, cart, favourites, checkout and order pages
- Open Graph: `og:type`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:locale` and `og:image` with size and alternative text
- `twitter:card` and the optional `twitter:site` account
- JSON-LD:
  - `WebSite` on the home page
  - `BreadcrumbList` on other pages, categories and products
  - `Product` with `Offer` (price, currency, availability, SKU, images) on product pages

The tags are wrapped in the HTML comments `<!-- This site is optimized with Flex SEO 0.2.0 -->` and `<!-- / Flex SEO 0.2.0 -->`, with the plugin name and version from `plugin.json`. All values are escaped. JSON-LD is encoded so that text cannot close the `<script>` element. Every tag carries `data-flex-seo`, so themes with client-side navigation can replace them on page change; Flex Starter 1.0.7 does this. An error while building the tags is logged and never breaks the page.

Absolute URLs use the **Site address** setting, then `APP_URL`, then the address of the request.

## Sitemap and robots.txt

`/sitemap.xml` lists:

- published pages that are indexable, shown in the sitemap and not pointing to a different canonical URL
- the catalogue, active categories and active products when Flex Commerce is active

Each type can be switched off in the settings. `lastmod` comes from the last update. Responses carry an `ETag`; a matching `If-None-Match` returns 304. While the site is closed the sitemap returns 404.

`/robots.txt` disallows `/admin/`, `/cart`, `/checkout`, `/order-received`, `/favourites` and `/account`, adds the extra rules from the settings and points to the sitemap. While the site is closed it disallows everything.

## Administration

The **SEO** page shows every page and product with a rating (**Good**, **Warnings**, **Problems**), filters by type and rating, and a search. Opening an item shows its fields next to:

- a search result preview
- a social card preview
- the list of checks

Both previews update while typing, without saving.

The checks cover:

- title length (30–60 characters) and description length (70–160)
- a missing description
- titles or descriptions shared with another page or product
- the focus keyword in the title, description, H1, start of the content and the address; Cyrillic keywords also match transliterated addresses
- a missing social image
- a page hidden from search engines but shown in the navigation
- a canonical URL on another domain

**Settings** cover:

- the title template and home page title
- the default social image
- the X (Twitter) account
- the site address
- the sitemap sections and extra `robots.txt` rules
- deleting the data on uninstall

## Administrative API

Routes are relative to `/admin/plugins/flex/seo` and protected by the platform authentication, super-admin and CSRF middleware:

| Method and path | Action |
| --- | --- |
| `GET /items` | Overview with `type`, `status`, `q` and `page` parameters |
| `GET /items/{page\|product}/{id}` | Values, previews and checks |
| `PUT /items/{page\|product}/{id}` | Save values |
| `POST /items/{page\|product}/{id}/preview` | Previews and checks for unsaved values |
| `GET /settings` / `PUT /settings` | Read or save settings |

Validation errors return 422 and missing items 404. JSON responses use `Cache-Control: no-store`.

## Data and uninstalling

Values are stored in `flex_seo_meta`, one row per page or product. Settings are stored in `flex_seo_settings`. Deleting a page removes its row. Deactivation keeps all data and stops the output. Uninstalling drops both tables only when **Delete SEO data on uninstall** is enabled. Back up these tables together with the platform database.

## Structure

```text
plugin.json                 Version, permissions, autoload and assets
src/SeoPlugin.php           Registration, page fields, events, filters and routes
src/SeoStore.php            Schema, validation, settings and 0.1.x migration
src/SeoSite.php             Site name, locale, addresses, images and page paths
src/SeoResolver.php         Effective values, Open Graph and JSON-LD
src/HeadRenderer.php        Escaped <head> markup
src/SeoAnalyzer.php         Content checks
src/SeoItems.php            Pages and products for the administration and sitemap
src/SitemapController.php   /sitemap.xml
src/RobotsController.php    /robots.txt
src/AdminController.php     Administrative JSON API
assets/admin.js             Administration page
assets/admin.css            Administration styles
tests/                      PHP and JavaScript tests
```

## Development checks

Run from the Flex CMS root with Composer dependencies installed:

```sh
docker compose exec -T app vendor/bin/phpunit plugins/flex/seo/tests/SeoTest.php
node --test plugins/flex/seo/tests/admin.test.mjs
```

The PHP tests use SQLite in memory. When this README was written, **12 PHP tests with 100 assertions** and **4 JavaScript tests** passed.

## Package boundaries

The plugin uses Flex CMS classes and services and is not a standalone PHP application. Product and category output needs Flex Commerce 0.2.5 or later, which passes the storefront context to the filters. Multilingual `hreflang` tags, image sitemaps, redirects and search engine submission are not implemented.

This plugin is licensed under the GNU General Public License, version 2 or (at your option) any later version (`GPL-2.0-or-later`). The full text of version 2 is in [`LICENSE`](LICENSE).
