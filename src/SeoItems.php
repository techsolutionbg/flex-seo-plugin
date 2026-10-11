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

    /** @return list<array<string, mixed>> Categories that are not in the trash, including inactive ones. */
    public function categoryRecords(): array
    {
        $db = $this->site->connection();
        if (!$db->getSchemaBuilder()->hasTable('flex_commerce_categories')) {
            return [];
        }

        return $db->table('flex_commerce_categories')->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'status', 'updated_at'])
            ->map(static fn(object $row): array => [
                'id' => (int) $row->id, 'name' => (string) $row->name, 'slug' => (string) $row->slug,
                'description' => (string) $row->description, 'status' => (string) $row->status, 'updated_at' => $row->updated_at,
            ])->all();
    }

    /** @param array<string, mixed> $category @param array<string, mixed>|null $meta @return array<string, mixed> */
    public function resolveCategory(array $category, ?array $meta = null): array
    {
        return $this->resolver->commerce([
            'type' => 'category',
            'path' => '/catalog/category/' . rawurlencode((string) $category['slug']),
            'category' => $category,
        ], null, $meta);
    }

    /**
     * Every page, product and category with its resolved values and analysis.
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
        foreach ($this->categoryRecords() as $category) {
            $resolved[] = ['source' => $category, 'resolved' => $this->resolveCategory($category)];
        }
        $counts = $this->counts(array_column($resolved, 'resolved'));
        $rows = [];
        foreach ($resolved as $entry) {
            $rows[] = $this->row($entry['source'], $entry['resolved'], $counts);
        }

        return $rows;
    }

    /**
     * @param int|null $id Null for a record that is not saved yet; it is previewed from $source only.
     * @param array<string, mixed>|null $draft Unsaved SEO values for a live preview.
     * @param array<string, mixed> $source Unsaved values of the page, product or category being edited.
     * @return array<string, mixed>|null
     */
    public function detail(string $type, ?int $id, ?array $draft = null, array $source = []): ?array
    {
        $record = $id === null ? $this->blank($type) : $this->find($type, $id);
        if ($record === null) {
            return null;
        }
        $record = $this->withSource($type, $record, $source);
        $meta = $draft === null ? null : $this->draftMeta($type, $id ?? 0, $draft);
        $resolved = match ($type) {
            'page' => $this->resolver->page($record, null, $meta),
            'product' => $this->resolveProduct($record, $meta),
            default => $this->resolveCategory($record, $meta),
        };
        $others = [];
        foreach ($this->overview() as $row) {
            if ($row['type'] !== $type || $row['id'] !== $id) {
                $others[] = ['title' => $row['preview']['title'], 'description' => $row['preview']['description']];
            }
        }
        $counts = $this->counts([...$others, $resolved]);

        return $this->row($record, $resolved, $counts) + ['meta' => $resolved['meta']];
    }

    /** @return array<string, mixed>|null */
    private function find(string $type, int $id): ?array
    {
        if ($type === 'page') {
            return (new PageRepository())->find($id)?->toPublicArray();
        }
        foreach ($type === 'product' ? $this->products() : $this->categoryRecords() as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function blank(string $type): array
    {
        return match ($type) {
            'page' => ['id' => 0, 'parent_id' => null, 'featured_media_id' => null, 'title' => '', 'slug' => '', 'content' => '', 'status' => 'draft', 'settings' => []],
            'product' => ['id' => 0, 'name' => '', 'slug' => '', 'sku' => null, 'status' => 'draft', 'description' => '', 'excerpt' => '', 'price_minor' => 0, 'currency' => 'EUR', 'available' => false, 'images' => [], 'categories' => [], 'updated_at' => null],
            default => ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'status' => 'draft', 'updated_at' => null],
        };
    }

    /**
     * Applies the editor's unsaved values, so previews and checks follow what the user is typing.
     * @param array<string, mixed> $record @param array<string, mixed> $source @return array<string, mixed>
     */
    private function withSource(string $type, array $record, array $source): array
    {
        $text = static fn(mixed $value, int $length): ?string => is_string($value) ? mb_substr($value, 0, $length) : null;
        if ($type === 'page') {
            foreach (['title' => 190, 'slug' => 190, 'content' => 200000, 'status' => 20] as $key => $length) {
                $value = $text($source[$key] ?? null, $length);
                if ($value !== null) {
                    $record[$key] = $value;
                }
            }
            if (array_key_exists('featured_media_id', $source)) {
                $media = filter_var($source['featured_media_id'], FILTER_VALIDATE_INT);
                $record['featured_media_id'] = is_int($media) && $media > 0 ? $media : null;
            }

            return $record;
        }
        foreach (['name' => 190, 'slug' => 190, 'description' => 200000, 'sku' => 100, 'status' => 16] as $key => $length) {
            $value = $text($source[$key] ?? null, $length);
            if ($value !== null) {
                $record[$key] = $value;
            }
        }
        if ($type === 'product') {
            $record['excerpt'] = SeoResolver::text((string) $record['description']);
            $price = static fn(mixed $value): ?int => is_numeric($value) ? (int) round((float) $value * 100) : null;
            $regular = $price($source['price'] ?? null);
            $sale = $price($source['sale_price'] ?? null);
            if ($regular !== null) {
                $record['price_minor'] = $sale !== null && $sale > 0 && $sale < $regular ? $sale : $regular;
            }
            if (is_string($source['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', $source['currency']) === 1) {
                $record['currency'] = $source['currency'];
            }
            if (is_numeric($source['stock'] ?? null)) {
                $record['available'] = (int) $source['stock'] > 0;
            }
            if (is_array($source['images'] ?? null)) {
                $record['images'] = array_values(array_filter(array_map(
                    fn(mixed $media): ?array => $this->site->image(is_numeric($media) ? (int) $media : null),
                    $source['images'],
                )));
            }
        }

        return $record;
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
                // The admin preview loads the image from the current site; og:image itself stays absolute (image_url).
                'image' => $resolved['image']['path'] ?? null,
                'image_url' => $resolved['image']['url'] ?? null,
                'robots' => $resolved['robots'],
                'site_name' => $this->site->name(),
            ],
            'analysis' => $analysis,
        ];
    }
}
