<?php

declare(strict_types=1);

namespace Flex\Seo;

use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Extension\V1\Time;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final readonly class SitemapController
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function __invoke(ServerRequestInterface $request, array $arguments = []): ResponseInterface
    {
        $store = new SeoStore();
        $site = new SeoSite();
        if ($site->closed()) {
            return $this->responses->text('', 404, ['Cache-Control' => 'no-store']);
        }
        $settings = $store->settings();
        $resolver = new SeoResolver($store, $site);
        $items = new SeoItems($store, $site, $resolver, new SeoAnalyzer());
        $xml = self::xml(self::entries($items, $site, $resolver, $settings, $request));
        $etag = '"' . substr(hash('sha256', $xml), 0, 32) . '"';
        if (trim($request->getHeaderLine('If-None-Match')) === $etag) {
            return $this->responses->text('', 304, ['ETag' => $etag]);
        }

        return $this->responses->text($xml, 200, ['ETag' => $etag])
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Indexable published pages, active products and active categories.
     * @param array<string, mixed> $settings
     * @return list<array{loc: string, lastmod: string|null}>
     */
    public static function entries(SeoItems $items, SeoSite $site, SeoResolver $resolver, array $settings, ?ServerRequestInterface $request = null): array
    {
        $base = $site->baseUrl((string) $settings['site_url'], $request);
        $entries = [];
        $add = static function (string $loc, mixed $updated) use (&$entries): void {
            if ($loc === '' || isset($entries[$loc])) {
                return;
            }
            $entries[$loc] = ['loc' => $loc, 'lastmod' => Time::iso($updated)];
        };
        if ($settings['sitemap_pages']) {
            foreach ($items->pages() as $page) {
                $pageSettings = is_array($page['settings'] ?? null) ? $page['settings'] : [];
                if ($page['status'] !== 'published' || ($pageSettings['show_in_sitemap'] ?? true) === false) {
                    continue;
                }
                $resolved = $resolver->page($page, $request);
                // Pages whose canonical URL points elsewhere are represented by that URL, not listed twice.
                if ($resolved['robots'] === null && $resolved['canonical'] === $base . $resolved['path']) {
                    $add($resolved['canonical'], $page['updated_at'] ?? null);
                }
            }
        }
        if ($site->commerceActive()) {
            if ($settings['sitemap_products'] || $settings['sitemap_categories']) {
                $add($base . '/catalog', null);
            }
            if ($settings['sitemap_categories']) {
                foreach ($items->categories() as $category) {
                    $add($base . '/catalog/category/' . rawurlencode($category['slug']), $category['updated_at']);
                }
            }
            if ($settings['sitemap_products']) {
                foreach ($items->products(true) as $product) {
                    $resolved = $items->resolveProduct($product, null, $request);
                    $path = $base . '/product/' . rawurlencode($product['slug']);
                    if ($resolved['canonical'] === $path) {
                        $add($path, $product['updated_at']);
                    }
                }
            }
        }

        return array_values($entries);
    }

    /** @param list<array{loc: string, lastmod: string|null}> $entries */
    public static function xml(array $entries): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($entries as $entry) {
            $lines[] = '  <url><loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($entry['lastmod'] === null ? '' : '<lastmod>' . $entry['lastmod'] . '</lastmod>') . '</url>';
        }
        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }
}
