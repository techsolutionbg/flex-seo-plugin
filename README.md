# Flex SEO

SEO fields for Flex CMS pages: SEO title, meta description, focus keyword, Open Graph title, canonical URL and a no-index option.

Version: **0.1.3** · ID: `flex/seo` · Status: **development**.

GitHub repository: `techsolutionbg/flex-seo-plugin`.

> This plugin is in development. It stores SEO values but does not output them in the public `<head>` yet. That requires a platform head extension hook, which will be added before the plugin is used in production.

## Requirements and installation

Requires an installed Flex CMS with PHP 8.3+ and Extension API V1 page fields, page settings and events. No database tables, other plugins or npm build are required.

Place the package in `plugins/flex/seo/`, with `plugin.json` directly inside that folder. From the Flex CMS root:

```sh
php bin/flex plugin:install flex/seo
php bin/flex plugin:activate flex/seo
```

With Docker, prefix the commands with `docker compose exec app`. The plugin needs write access to its own `data/` folder.

## What it adds

Fields in the page editor:

| Field | Type | Maximum characters |
| --- | --- | ---: |
| `seo_title` | Text | 190 |
| `meta_description` | Text area | 320 |
| `focus_keyword` | Text | 190 |
| `og_title` | Text | 190 |
| `canonical_url` | Text | 500 |

A page setting:

| Setting | Type | Default |
| --- | --- | --- |
| `no_index` | Checkbox | off |

The field labels and help texts in the editor are in Bulgarian.

## Storage

Values are saved in `data/seo.json`, keyed by page ID. The plugin listens to the page events:

| Event | Action |
| --- | --- |
| `PAGE_CREATED` | Save the submitted SEO values |
| `PAGE_UPDATED` | Merge the submitted values with the stored ones |
| `PAGE_DELETED` | Remove the page's values |

Saved values are loaded back into the editor when a page is opened. The file is written with an exclusive lock. `data/seo.json` is site data and is not part of the repository; include it in your backups.

Deactivating or uninstalling the plugin does not delete `data/seo.json`.

## Structure

```text
plugin.json          Version, permissions and autoload
src/SeoPlugin.php    Fields, events and JSON storage
data/                Plugin-local data (seo.json is created at runtime)
```

## Package boundaries

The plugin uses Flex CMS classes and services and is not a standalone PHP application. It has no automated tests yet. Public `<head>` output, sitemaps and structured data are not implemented.

This plugin is licensed under the GNU General Public License, version 2 or (at your option) any later version (`GPL-2.0-or-later`). The full text of version 2 is in [`LICENSE`](LICENSE).
