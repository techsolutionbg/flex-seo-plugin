<?php

declare(strict_types=1);

namespace Flex\Seo;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Computes the effective title, description, canonical URL, robots value, Open Graph data and JSON-LD
 * for CMS pages and Flex Commerce storefront pages.
 */
final class SeoResolver
{
    /** Commerce page types that must never be indexed. */
    private const PRIVATE_TYPES = ['cart', 'favourites', 'checkout', 'order', 'error', 'closed'];

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private SeoStore $store, private SeoSite $site, private string $signature = 'Flex SEO') {}

    /**
     * @param array<string, mixed> $context Filter context: ['page' => array] or ['commerce' => array], plus 'request'.
     * @return array<string, mixed>|null
     */
    public function resolve(array $context): ?array
    {
        $request = ($context['request'] ?? null) instanceof ServerRequestInterface ? $context['request'] : null;
        if (is_array($context['page'] ?? null)) {
            $key = 'page:' . (int) ($context['page']['id'] ?? 0) . ':' . ($request === null ? '' : spl_object_id($request));

            return $this->cache[$key] ??= $this->page($context['page'], $request);
        }
        if (is_array($context['commerce'] ?? null)) {
            $commerce = $context['commerce'];
            $key = 'commerce:' . ($commerce['type'] ?? '') . ':' . ($commerce['path'] ?? '') . ':' . ($request === null ? '' : spl_object_id($request));

            return $this->cache[$key] ??= $this->commerce($commerce, $request);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $page Public page array (Page::toPublicArray()).
     * @param array<string, mixed>|null $meta Unsaved values for the admin preview.
     * @return array<string, mixed>
     */
    public function page(array $page, ?ServerRequestInterface $request = null, ?array $meta = null): array
    {
        $settings = $this->store->settings();
        $id = (int) ($page['id'] ?? 0);
        $meta ??= $id > 0 ? $this->store->get('page', $id) : $this->store->get('page', 0);
        $pageSettings = is_array($page['settings'] ?? null) ? $page['settings'] : [];
        $home = $id > 0 && $id === $this->site->homePageId();
        $base = $this->site->baseUrl((string) $settings['site_url'], $request);
        $path = $this->site->pagePath($page);
        $name = (string) ($page['title'] ?? '');
        $title = $meta['seo_title'] !== ''
            ? $meta['seo_title']
            : ($home && $settings['home_title'] !== '' ? $settings['home_title'] : $this->template((string) $settings['title_template'], $name));
        $description = $meta['meta_description'] !== '' ? $meta['meta_description'] : self::excerpt(self::text((string) ($page['content'] ?? '')));
        if ($description === '' && $home) {
            $description = self::excerpt($this->site->description());
        }
        $canonical = $meta['canonical_url'] !== '' ? $this->site->absolute($base, $meta['canonical_url']) : $base . $path;
        $image = $this->site->image($meta['og_image_media_id'])
            ?? $this->site->image((int) ($page['featured_media_id'] ?? 0))
            ?? $this->site->image((int) $settings['default_image_media_id']);
        $jsonLd = [];
        if ($home) {
            $jsonLd[] = array_filter(['@type' => 'WebSite', 'name' => $this->site->name(), 'url' => $base . '/', 'description' => $this->site->description()]);
        } else {
            $trail = [['title' => $this->site->name(), 'path' => '/'], ...$this->site->ancestors($page), ['title' => $name, 'path' => $path]];
            $jsonLd[] = $this->breadcrumbs($base, $trail);
        }

        return [
            'type' => 'page',
            'id' => $id,
            'name' => $name,
            'path' => $path,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => filter_var($pageSettings['no_index'] ?? false, FILTER_VALIDATE_BOOL) ? 'noindex, follow' : null,
            'og_type' => 'website',
            'og_title' => $meta['og_title'] !== '' ? $meta['og_title'] : $title,
            'og_description' => $meta['og_description'] !== '' ? $meta['og_description'] : $description,
            'image' => $image === null ? null : ['url' => $this->site->absolute($base, $image['url'])] + $image,
            'json_ld' => $jsonLd,
            'meta' => $meta,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, mixed> $commerce The Flex Commerce storefront context.
     * @param array<string, mixed>|null $meta Unsaved product values for the admin preview.
     * @return array<string, mixed>
     */
    public function commerce(array $commerce, ?ServerRequestInterface $request = null, ?array $meta = null): array
    {
        $settings = $this->store->settings();
        $type = (string) ($commerce['type'] ?? 'page');
        $status = (int) ($commerce['status'] ?? 200);
        $base = $this->site->baseUrl((string) $settings['site_url'], $request);
        $path = is_string($commerce['path'] ?? null) ? $commerce['path'] : '';
        $default = (string) ($commerce['title'] ?? '');
        $product = is_array($commerce['product'] ?? null) ? $commerce['product'] : null;
        $category = is_array($commerce['category'] ?? null) ? $commerce['category'] : null;
        $meta ??= $product !== null ? $this->store->get('product', (int) ($product['id'] ?? 0)) : array_fill_keys(array_keys(SeoStore::FIELDS), '') + ['og_image_media_id' => null];
        $robots = null;
        if (in_array($type, self::PRIVATE_TYPES, true) || $status >= 400) {
            $robots = in_array($type, ['checkout', 'order'], true) ? 'noindex, nofollow' : 'noindex, follow';
        } elseif (($commerce['filtered'] ?? false) === true) {
            $robots = 'noindex, follow';
        }
        $name = $product['name'] ?? $category['name'] ?? $default;
        $title = $meta['seo_title'] !== '' ? $meta['seo_title'] : $this->template((string) $settings['title_template'], (string) $name);
        $description = '';
        if ($meta['meta_description'] !== '') {
            $description = $meta['meta_description'];
        } elseif ($product !== null) {
            $description = self::excerpt(self::text((string) ($product['excerpt'] ?? $product['description'] ?? '')));
        } elseif ($category !== null) {
            $description = self::excerpt(self::text($this->categoryDescription($category)));
        }
        // Filtered listings point to the unfiltered listing; private pages get no canonical URL.
        $indexablePath = $path !== '' && ($robots === null || ($commerce['filtered'] ?? false) === true);
        $canonical = $meta['canonical_url'] !== '' ? $this->site->absolute($base, $meta['canonical_url']) : ($indexablePath ? $base . $path : '');
        $image = $this->site->image($meta['og_image_media_id']);
        if ($image === null && $product !== null && is_array($product['images'][0] ?? null) && ($product['images'][0]['url'] ?? '') !== '') {
            $first = $product['images'][0];
            $media = $this->site->image((int) ($first['id'] ?? 0));
            $image = ['url' => (string) $first['url'], 'width' => $media['width'] ?? 0, 'height' => $media['height'] ?? 0, 'alt' => (string) ($first['alt'] ?? '')];
        }
        $image ??= $this->site->image((int) $settings['default_image_media_id']);
        $jsonLd = [];
        if ($product !== null && $robots === null) {
            $jsonLd[] = $this->productSchema($product, $base, $canonical !== '' ? $canonical : $base . $path, $description);
        }
        if (in_array($type, ['product', 'category', 'catalog'], true) && $robots === null) {
            $trail = [['title' => $this->site->name(), 'path' => '/'], ['title' => $this->site->locale() === 'en' ? 'Products' : 'Продукти', 'path' => '/catalog']];
            $trailCategory = $category ?? (is_array($product['categories'][0] ?? null) ? $product['categories'][0] : null);
            if ($trailCategory !== null && ($trailCategory['slug'] ?? '') !== '') {
                $trail[] = ['title' => (string) $trailCategory['name'], 'path' => '/catalog/category/' . rawurlencode((string) $trailCategory['slug'])];
            }
            if ($product !== null) {
                $trail[] = ['title' => (string) $product['name'], 'path' => $path];
            }
            if (count($trail) > 2 || $type === 'catalog') {
                $jsonLd[] = $this->breadcrumbs($base, $trail);
            }
        }

        return [
            'type' => $product !== null ? 'product' : $type,
            'id' => (int) ($product['id'] ?? $category['id'] ?? 0),
            'name' => (string) $name,
            'path' => $path,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $robots,
            'og_type' => $product !== null ? 'product' : 'website',
            'og_title' => $meta['og_title'] !== '' ? $meta['og_title'] : $title,
            'og_description' => $meta['og_description'] !== '' ? $meta['og_description'] : $description,
            'image' => $image === null ? null : ['url' => $this->site->absolute($base, $image['url'])] + $image,
            'json_ld' => $jsonLd,
            'meta' => $meta,
            'settings' => $settings,
        ];
    }

    /** @param array<string, mixed> $resolved */
    public function head(array $resolved): string
    {
        return (new HeadRenderer($this->signature))->render([
            'description' => $resolved['description'],
            'canonical' => $resolved['canonical'],
            'robots' => $resolved['robots'],
            'og' => [
                'type' => $resolved['og_type'],
                'title' => $resolved['og_title'],
                'description' => $resolved['og_description'],
                'url' => $resolved['canonical'],
                'site_name' => $this->site->name(),
                'locale' => $this->site->ogLocale(),
            ],
            'image' => $resolved['image'],
            'twitter_site' => (string) $resolved['settings']['twitter_site'],
            'json_ld' => $resolved['json_ld'],
        ]);
    }

    public function template(string $template, string $title): string
    {
        $site = $this->site->name();
        if ($title === '') {
            return $site;
        }
        $result = trim(str_replace(['{title}', '{site}'], [$title, $site], $template));
        // A template such as "{title} | {site}" must not leave a dangling separator when the site name is empty.
        $result = trim(preg_replace('/^[\s|–—:·-]+|[\s|–—:·-]+$/u', '', $result) ?? $result);

        return $result === '' ? $title : $result;
    }

    public static function text(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<\/?(?:p|div|br|li|h[1-6]|section|article|td|th)\b[^>]*>/i', ' ', $html) ?? $html;
        // Shortcodes such as [gallery id="1"] are not visible text.
        $html = preg_replace('/\[[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', ' ', $html) ?? $html;

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    public static function excerpt(string $text, int $length = 160): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,.;:-–") . '…';
    }

    /** @param list<array{title: string, path: string}> $trail @return array<string, mixed> */
    private function breadcrumbs(string $base, array $trail): array
    {
        $items = [];
        foreach (array_values($trail) as $index => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $crumb['title'], 'item' => $this->site->absolute($base, $crumb['path'] === '' ? '/' : $crumb['path'])];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function productSchema(array $product, string $base, string $url, string $description): array
    {
        $images = [];
        foreach (is_array($product['images'] ?? null) ? $product['images'] : [] as $image) {
            if (is_array($image) && ($image['url'] ?? '') !== '') {
                $images[] = $this->site->absolute($base, (string) $image['url']);
            }
        }
        $schema = ['@type' => 'Product', 'name' => (string) $product['name'], 'url' => $url];
        if ($description !== '') {
            $schema['description'] = $description;
        }
        if (($product['sku'] ?? '') !== '' && $product['sku'] !== null) {
            $schema['sku'] = (string) $product['sku'];
        }
        if ($images !== []) {
            $schema['image'] = $images;
        }
        if (isset($product['price_minor']) && is_numeric($product['price_minor']) && ($product['currency'] ?? '') !== '') {
            $schema['offers'] = [
                '@type' => 'Offer',
                'url' => $url,
                'price' => number_format(((int) $product['price_minor']) / 100, 2, '.', ''),
                'priceCurrency' => (string) $product['currency'],
                'availability' => ($product['available'] ?? false) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ];
        }

        return $schema;
    }

    /** @param array<string, mixed> $category */
    private function categoryDescription(array $category): string
    {
        if (is_string($category['description'] ?? null)) {
            return $category['description'];
        }
        $id = (int) ($category['id'] ?? 0);
        $db = $this->site->connection();
        if ($id < 1 || !$db->getSchemaBuilder()->hasTable('flex_commerce_categories')) {
            return '';
        }

        return (string) ($db->table('flex_commerce_categories')->where('id', $id)->value('description') ?? '');
    }
}
