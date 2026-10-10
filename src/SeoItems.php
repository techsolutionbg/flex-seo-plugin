<?php

declare(strict_types=1);

namespace Flex\Seo;

use Flex\Pages\{Page, PageRepository};
use Psr\Http\Message\ServerRequestInterface;

/** Pages and products with their resolved SEO values, for the administration and the sitemap. */
final class SeoItems
{
    public function __construct(private SeoStore $store, private SeoSite $site, private SeoResolver $resolver, private SeoAnalyzer $analyzer) {}

    /** @return list<array<string, mixed>> Public page arrays of pages that are not in the trash. */
    public function pages(): array
    {
        return array_map(static fn(Page $page): array => $page->toPublicArray(), (new PageRepository())->all()->all());
    }

    /** @return list<array<string, mixed>> Products shaped like Flex Commerce public product records. */
    public function products(bool $activeOnly = false): array
    {
        $db = $this->site->connection();
        if (!$db->getSchemaBuilder()->hasTable('flex_commerce_products')) {
            return [];
        }
        $query = $db->table('flex_commerce_products')->whereNull('deleted_at')->orderBy('name');
        if ($activeOnly) {
            $query->where('status', 'active');
        }
        $categories = [];
        foreach ($this->categories() as $category) {
            $categories[(int) $category['id']] = $category;
        }
        $products = [];
        foreach ($query->get() as $row) {
            $images = [];
            foreach (json_decode((string) $row->images, true) ?? [] as $id) {
                $image = $this->site->image((int) $id);
                if ($image !== null) {
                    $images[] = $image;
                }
            }
            $regular = (int) $row->price_minor;
            $sale = isset($row->sale_price_minor) ? (int) $row->sale_price_minor : null;
            $products[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'sku' => $row->sku,
                'status' => (string) $row->status,
                'description' => (string) $row->description,
                'excerpt' => SeoResolver::text((string) $row->description),
                'price_minor' => $sale !== null && $sale < $regular ? $sale : $regular,
                'currency' => (string) $row->currency,
                'available' => (int) $row->stock > 0,
                'images' => $images,
                'categories' => array_values(array_filter(array_map(
                    static fn(mixed $id): ?array => $categories[(int) $id] ?? null,
                    json_decode((string) $row->category_ids, true) ?? [],
                ))),
                'updated_at' => $row->updated_at,
            ];
        }

        return $products;
    }

    /** @return list<array{id: int, name: string, slug: string, updated_at: mixed}> Active categories. */
    public function categories(): array
    {
        $db = $this->site->connection();
        if (!$db->getSchemaBuilder()->hasTable('flex_commerce_categories')) {
            return [];
        }

        return $db->table('flex_commerce_categories')->where('status', 'active')->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'slug', 'updated_at'])
            ->map(static fn(object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'slug' => (string) $row->slug, 'updated_at' => $row->updated_at])
            ->all();
    }

    /** @param array<string, mixed> $product @param array<string, mixed>|null $meta @return array<string, mixed> */
    public function resolveProduct(array $product, ?array $meta = null, ?ServerRequestInterface $request = null): array
    {
        return $this->resolver->commerce([
            'type' => 'product',
            'path' => '/product/' . rawurlencode((string) $product['slug']),
            'product' => $product,
        ], $request, $meta);
    }

    /**
     * Every page and product with its resolved values and analysis.
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $resolved = [];
        foreach ($this->pages() as $page) {
            $resolved[] = ['source' => $page, 'resolved' => $this->resolver->page($page)];
        }
        foreach ($this->products() as $product) {
            $resolved[] = ['source' => $product, 'resolved' => $this->resolveProduct($product)];
        }
        $counts = $this->counts(array_column($resolved, 'resolved'));
        $rows = [];
        foreach ($resolved as $entry) {
            $rows[] = $this->row($entry['source'], $entry['resolved'], $counts);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $draft Unsaved values for a live preview.
     * @return array<string, mixed>|null
     */
    public function detail(string $type, int $id, ?array $draft = null): ?array
    {
        $source = null;
        if ($type === 'page') {
            $page = (new PageRepository())->find($id);
            $source = $page?->toPublicArray();
        } elseif ($type === 'product') {
            foreach ($this->products() as $product) {
                if ($product['id'] === $id) {
                    $source = $product;
                    break;
                }
            }
        }
        if ($source === null) {
            return null;
        }
        $meta = $draft === null ? null : $this->draftMeta($type, $id, $draft);
        $resolved = $type === 'page' ? $this->resolver->page($source, null, $meta) : $this->resolveProduct($source, $meta);
        $others = [];
        foreach ($this->overview() as $row) {
            if ($row['type'] !== $type || $row['id'] !== $id) {
                $others[] = ['title' => $row['preview']['title'], 'description' => $row['preview']['description']];
            }
        }
        $counts = $this->counts([...$others, $resolved]);

        return $this->row($source, $resolved, $counts) + ['meta' => $resolved['meta']];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed> Stored values merged with valid draft values, without saving.
     */
    private function draftMeta(string $type, int $id, array $draft): array
    {
        $meta = $this->store->get($type, $id);
        foreach (SeoStore::FIELDS as $field => $length) {
            if (is_string($draft[$field] ?? null)) {
                $meta[$field] = mb_substr(trim(preg_replace('/\s+/u', ' ', $draft[$field]) ?? ''), 0, $length);
            }
        }
        if ($meta['canonical_url'] !== '' && !SeoStore::canonicalAllowed($meta['canonical_url'])) {
            $meta['canonical_url'] = '';
        }
        if (array_key_exists('og_image_media_id', $draft)) {
            $value = filter_var($draft['og_image_media_id'], FILTER_VALIDATE_INT);
            $meta['og_image_media_id'] = is_int($value) && $value > 0 ? $value : null;
        }

        return $meta;
    }

    /** @param list<array<string, mixed>> $resolved @return array{titles: array<string, int>, descriptions: array<string, int>} */
    private function counts(array $resolved): array
    {
        $counts = ['titles' => [], 'descriptions' => []];
        foreach ($resolved as $item) {
            $title = SeoAnalyzer::key((string) $item['title']);
            $counts['titles'][$title] = ($counts['titles'][$title] ?? 0) + 1;
            if (($item['description'] ?? '') !== '') {
                $description = SeoAnalyzer::key((string) $item['description']);
                $counts['descriptions'][$description] = ($counts['descriptions'][$description] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $resolved @param array<string, mixed> $counts @return array<string, mixed> */
    private function row(array $source, array $resolved, array $counts): array
    {
        $isPage = $resolved['type'] === 'page';
        $settings = is_array($source['settings'] ?? null) ? $source['settings'] : [];
        $base = $this->site->baseUrl((string) $resolved['settings']['site_url']);
        $analysis = $this->analyzer->analyze([
            'title' => $resolved['title'],
            'description' => $resolved['description'],
            'meta' => $resolved['meta'],
            'slug' => (string) ($source['slug'] ?? ''),
            'heading' => $isPage ? (string) $source['title'] : (string) $source['name'],
            'text' => SeoResolver::text((string) ($isPage ? ($source['content'] ?? '') : ($source['description'] ?? ''))),
            'has_image' => $resolved['image'] !== null,
            'robots' => $resolved['robots'],
            'in_navigation' => $isPage && ($settings['show_in_navigation'] ?? true) !== false && $source['status'] === 'published',
            'canonical' => $resolved['canonical'],
            'base_url' => $base,
        ], $counts);

        return [
            'type' => $resolved['type'],
            'id' => (int) $source['id'],
            'name' => $isPage ? (string) $source['title'] : (string) $source['name'],
            'status' => (string) ($source['status'] ?? ''),
            'path' => $resolved['path'],
            'preview' => [
                'title' => $resolved['title'],
                'description' => $resolved['description'],
                'url' => $resolved['canonical'] !== '' ? $resolved['canonical'] : $base . $resolved['path'],
                'og_title' => $resolved['og_title'],
                'og_description' => $resolved['og_description'],
                'image' => $resolved['image']['url'] ?? null,
                'robots' => $resolved['robots'],
                'site_name' => $this->site->name(),
            ],
            'analysis' => $analysis,
        ];
    }
}
