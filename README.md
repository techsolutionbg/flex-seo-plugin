# Flex SEO

Search engine optimisation for Flex CMS: titles, meta descriptions, canonical URLs, Open Graph and X (Twitter) cards, Schema.org structured data, `sitemap.xml`, `robots.txt` and content checks for pages and Flex Commerce products.

Version: **0.5.1** · ID: `flex/seo` · Minimum Flex CMS version: **0.1.60**.

GitHub repository: `techsolutionbg/flex-seo-plugin`.

## Requirements and installation

Requires an installed Flex CMS 0.1.60 or later with PHP 8.3+ and its database. Version 0.1.59 adds the `public.title` filter used for the document title, and 0.1.60 the admin extension points. **Flex Commerce 0.2.5+** is optional: when it is active, product, category and catalogue pages get their own tags and appear in the sitemap. A theme must print `head_tags` in `<head>` and use `document_title` for `<title>`; Flex Starter 1.0.7 does both.

Place the package in `plugins/flex/seo/`, with `plugin.json` directly inside that folder. From the Flex CMS root:

```sh
php bin/flex plugin:install flex/seo
php bin/flex plugin:activate flex/seo
```

With Docker, prefix the commands with `docker compose exec app`. Installation, activation and updates create the `flex_seo_meta` and `flex_seo_settings` tables when they are missing. The plugin requests the `admin.ui`, `events.listen`, `routes.admin`, `routes.public` and `frontend.assets` permissions.

### Updating from 0.1.x

Version 0.1.x stored values in `data/seo.json`. On update the values are imported into the database once and the file is renamed to `data/seo.json.migrated`. Its separate "no index" option is moved to the core page setting `no_index`, which is now the only one. Deactivate the plugin before updating so the new permissions can be approved.

## What it adds

- An **SEO** section in the page editor, the Flex Commerce product editor and the category editor, with live previews and checks.
- An **SEO** column with the rating of every page in the pages list.
- A **Settings → SEO** tab with the global settings and a site check.
- Tags in the public `<head>` of pages and storefront pages.
- `/sitemap.xml` and `/robots.txt` at the site root.

## Fields

Each page, product and category can have:

| Field | Maximum | When empty |
| --- | ---: | --- |
| SEO title | 190 | The title template from the settings |
| Meta description | 320 | The start of the page content or the product description, up to 160 characters |
| Focus keyword | 190 | Used only by the checks |
| Social title (`og:title`) | 190 | The SEO title |
| Social description (`og:description`) | 320 | The meta description |
| Social image | media library image | The featured image, the first product image or the default image |
| Canonical URL | 500 | The page's own public address |

The canonical URL accepts a site path such as `/about` or an HTTP/HTTPS address. All values are edited in the SEO section of the record's own editor. Whether a page is indexed comes from the core page setting `no_index` (the "do not index" checkbox in the page settings). Pages whose core setting `show_in_sitemap` is `false` are left out of the sitemap.

## Output in `<head>`

Through the `public.head` and `public.title` filters the plugin adds:

- `<title>` from the SEO title or the title template, for example `{title} | {site}`
- `<meta name="description">`
- `<link rel="canonical">` with an absolute URL
- `<meta name="robots">`: `noindex` for pages with `no_index`, filtered or searched catalogue listings, error pages, cart, favourites, checkout and order pages; every other page gets `index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1` (0.5.0+)
- Open Graph: `og:type`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:locale`, `og:updated_time` (pages) and `og:image` with size and alternative text. The image is the SEO image, else the featured image, else the default image, else the organisation logo
- X (Twitter): `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:image:alt` and the optional `twitter:site` account
- JSON-LD:
  - CMS pages (0.5.0+): one connected `@graph` — `WebSite`, `Organization` or `Person` (name, logo, `sameAs` profiles), `WebPage` (description, image, `datePublished`, `dateModified`, language) and `BreadcrumbList` on pages other than the home page. Pages hidden from search engines get no structured data
  - `BreadcrumbList` on categories and products
  - `Product` with `Offer` (price, currency, availability, SKU, images) on product pages

The tags are wrapped in the HTML comments `<!-- This site is optimized with Flex SEO 0.5.0 -->` and `<!-- / Flex SEO 0.5.0 -->`, with the plugin name and version from `plugin.json`. All values are escaped. JSON-LD is encoded so that text cannot close the `<script>` element. Every tag carries `data-flex-seo`, so themes with client-side navigation can replace them on page change; Flex Starter 1.0.7 does this. An error while building the tags is logged and never breaks the page.

Absolute URLs use the **Site address** setting, then `APP_URL`, then the address of the request.

## Sitemap and robots.txt

`/sitemap.xml` lists:

- published pages that are indexable, shown in the sitemap and not pointing to a different canonical URL
- the catalogue, active categories and active products when Flex Commerce is active

Each type can be switched off in the settings. `lastmod` comes from the last update. Responses carry an `ETag`; a matching `If-None-Match` returns 304. While the site is closed the sitemap returns 404.

`/robots.txt` disallows `/admin/`, `/cart`, `/checkout`, `/order-received`, `/favourites` and `/account`, adds the extra rules from the settings and points to the sitemap. While the site is closed it disallows everything.

## Administration

Flex SEO has no separate page. It registers its screens with the platform extension points (`$context->admin->extend()`, Flex CMS 0.1.60+), so administrators work where they edit content:

- **Page, product and category editors:** an **SEO** section with the fields, a rating summary, a search result preview, a social card preview and the list of checks. The previews and checks use the unsaved title, address, content, price and images while the user types, also for a record that is not saved yet. The SEO values are saved with the editor's own **Save** button, right after the record; a record without SEO changes writes nothing. The title, description, social and canonical fields show the generated text as real, editable text; it keeps following the template and the content until the user edits it, and **Върни автоматичния текст** switches a field back.
- **Pages list:** an **SEO** column with the rating; the tooltip lists the remarks, and the column can be sorted. Each row's actions menu has **Редактирай SEO**, which opens the page editor.
- **Settings → SEO:** the global settings and **Site check**, every page, product and category with its rating, filters by type and rating and a search. **Edit SEO** opens the record's editor.

The checks cover:

- title length (30–60 characters) and description length (70–160)
- a missing description
- titles or descriptions shared with another page, product or category
- the focus keyword in the title, description, H1, start of the content and the address; Cyrillic keywords also match transliterated addresses
- a missing social image
- a page hidden from search engines but shown in the navigation
- a canonical URL on another domain

**Settings → SEO** covers:

- the title template and home page title
- the organisation (0.5.0+): company or person, name, logo and social profiles for structured data
- the default social image
- the X (Twitter) account
- the site address
- the sitemap sections and extra `robots.txt` rules
- deleting the data on uninstall

## Administrative API

Routes are relative to `/admin/plugins/flex/seo` and protected by the platform authentication, super-admin and CSRF middleware:

| Method and path | Action |
| --- | --- |
| `GET /items` | Overview with `type` (`page`, `product`, `category`), `status`, `q`, `page` and `per_page` (up to 1000) |
| `GET /items/{type}/{id}` | Values, previews and checks |
| `PUT /items/{type}/{id}` | Save values |
| `POST /items/{type}/{id|new}/preview` | Previews and checks for unsaved SEO values; `source` carries the unsaved record values ||product}/{id}` | Values, previews and checks |
| `PUT /items/{page\|product}/{id}` | Save values |
| `POST /items/{page\|product}/{id}/preview` | Previews and checks for unsaved values |
| `GET /settings` / `PUT /settings` | Read or save settings |

Validation errors return 422 and missing items 404. JSON responses use `Cache-Control: no-store`.

## Data and uninstalling

Values are stored in `flex_seo_meta`, one row per page, product or category. Settings are stored in `flex_seo_settings`. Deleting a page removes its row; permanently deleting a product or category in Flex Commerce 0.2.6+ removes its row through the `commerce.catalog.deleted` action. Deactivation keeps all data and stops the output. Uninstalling drops both tables only when **Delete SEO data on uninstall** is enabled. Back up these tables together with the platform database.

## Structure

```text
plugin.json                 Version, permissions, autoload and assets
src/SeoPlugin.php           Registration, admin panels, events, filters and routes
src/SeoStore.php            Schema, validation, settings and 0.1.x migration
src/SeoSite.php             Site name, locale, addresses, images and page paths
src/SeoResolver.php         Effective values, Open Graph and JSON-LD
src/HeadRenderer.php        Escaped <head> markup
src/SeoAnalyzer.php         Content checks
src/SeoItems.php            Pages, products and categories for the administration and sitemap
src/SitemapController.php   /sitemap.xml
src/RobotsController.php    /robots.txt
src/AdminController.php     Administrative JSON API
assets/panels.js            Admin panels: editor section, settings tab and pages list column
assets/components.js        Shared administration components
assets/admin.css            Administration styles
tests/                      PHP and JavaScript tests
```

## Development checks

Run from the Flex CMS root with Composer dependencies installed:

```sh
docker compose exec -T app vendor/bin/phpunit plugins/flex/seo/tests/SeoTest.php
node --test plugins/flex/seo/tests/panels.test.mjs
```

The PHP tests use SQLite in memory. When this README was written, **15 PHP tests with 121 assertions** and **8 JavaScript tests** passed.

## Package boundaries

The plugin uses Flex CMS classes and services and is not a standalone PHP application. Product and category output needs Flex Commerce 0.2.5 or later, which passes the storefront context to the filters; the SEO section in the Commerce editors needs Flex Commerce 0.2.6. Multilingual `hreflang` tags, image sitemaps, redirects and search engine submission are not implemented.

This plugin is licensed under the GNU General Public License, version 2 or (at your option) any later version (`GPL-2.0-or-later`). The full text of version 2 is in [`LICENSE`](LICENSE).
