<?php

declare(strict_types=1);

namespace Flex\Seo\Tests;

use Flex\Configuration\ConfigurationRepository;
use Flex\Database\DatabaseManager;
use Flex\Seo\{AdminController, HeadRenderer, RobotsController, SeoAnalyzer, SeoItems, SeoResolver, SeoSite, SeoStore, SitemapController};
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

foreach (['SeoStore', 'SeoSite', 'HeadRenderer', 'SeoResolver', 'SeoAnalyzer', 'SeoItems', 'SitemapController', 'RobotsController', 'AdminController'] as $class) {
    require_once __DIR__ . '/../src/' . $class . '.php';
}

final class SeoTest extends TestCase
{
    private DatabaseManager $database;
    private Connection $db;
    private SeoStore $store;
    private string $base = 'https://shop.example';

    protected function setUp(): void
    {
        $_ENV['APP_URL'] = $this->base;
        $_ENV['APP_NAME'] = 'Flex';
        $this->database = new DatabaseManager(new ConfigurationRepository(['database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]]]));
        $schema = $this->database->schema();
        $schema->create('pages', static function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedBigInteger('author_id')->nullable();
            $t->unsignedInteger('parent_id')->nullable();
            $t->unsignedInteger('featured_media_id')->nullable();
            $t->string('title', 190);
            $t->string('slug', 190)->unique();
            $t->text('content')->nullable();
            $t->string('status', 20)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->json('settings')->nullable();
            $t->text('blocks')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('settings', static function (Blueprint $t): void {
            $t->string('group');
            $t->string('key');
            $t->text('value');
        });
        $schema->create('media', static function (Blueprint $t): void {
            $t->increments('id');
            foreach (['title', 'alt', 'mime'] as $name) {
                $t->text($name);
            }
            $t->integer('width');
            $t->integer('height');
            $t->softDeletes();
        });
        foreach (['products', 'categories'] as $kind) {
            $schema->create('flex_commerce_' . $kind, static function (Blueprint $t) use ($kind): void {
                $t->increments('id');
                $t->string('name');
                $t->string('slug')->unique();
                $t->text('description');
                $t->string('status', 16);
                if ($kind === 'products') {
                    $t->string('sku')->nullable();
                    $t->unsignedBigInteger('price_minor');
                    $t->unsignedBigInteger('sale_price_minor')->nullable();
                    $t->string('currency', 3);
                    $t->unsignedInteger('stock');
                    $t->text('images');
                    $t->text('category_ids');
                }
                $t->timestamps();
                $t->softDeletes();
            });
        }
        $this->db = $this->database->connection();
        $this->db->table('settings')->insert([
            ['group' => 'general', 'key' => 'site.name', 'value' => 'Магазин Флекс'],
            ['group' => 'general', 'key' => 'site.description', 'value' => 'Ръчно изработени продукти от България.'],
            ['group' => 'settings.public', 'key' => 'settings.public.home_page_id', 'value' => '1'],
        ]);
        $this->db->table('media')->insert([
            ['id' => 1, 'title' => 'Корица', 'alt' => 'Корица <alt>', 'mime' => 'image/jpeg', 'width' => 1200, 'height' => 630],
            ['id' => 2, 'title' => 'PDF', 'alt' => '', 'mime' => 'application/pdf', 'width' => 0, 'height' => 0],
        ]);
        $now = '2026-10-01 10:00:00';
        $this->db->table('pages')->insert([
            ['id' => 1, 'title' => 'Начало', 'slug' => 'home', 'content' => '<p>Добре дошли.</p>', 'status' => 'published', 'settings' => '{}', 'parent_id' => null, 'featured_media_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'title' => 'За нас', 'slug' => 'about-us', 'content' => '<h2>История</h2><p>Ние правим керамика от 1998 година в малка работилница край Троян.</p>[gallery id="1"]', 'status' => 'published', 'settings' => '{"show_in_navigation":true}', 'parent_id' => null, 'featured_media_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'title' => 'Екип', 'slug' => 'team', 'content' => '', 'status' => 'published', 'settings' => '{"use_parent_slugs":true}', 'parent_id' => 2, 'featured_media_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'title' => 'Скрита', 'slug' => 'hidden', 'content' => '', 'status' => 'published', 'settings' => '{"no_index":true}', 'parent_id' => null, 'featured_media_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'title' => 'Чернова', 'slug' => 'draft', 'content' => '', 'status' => 'draft', 'settings' => '{}', 'parent_id' => null, 'featured_media_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'title' => 'Без карта', 'slug' => 'no-sitemap', 'content' => '', 'status' => 'published', 'settings' => '{"show_in_sitemap":false}', 'parent_id' => null, 'featured_media_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->db->table('flex_commerce_categories')->insert(['id' => 1, 'name' => 'Чаши', 'slug' => 'cups', 'description' => '<p>Чаши за кафе и чай.</p>', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->table('flex_commerce_products')->insert([
            ['id' => 1, 'name' => 'Чаша „Троян“', 'slug' => 'troyan-cup', 'description' => '<p>Керамична чаша с <strong>традиционна</strong> шарка.</p>', 'status' => 'active', 'sku' => 'CUP-1', 'price_minor' => 2500, 'sale_price_minor' => 1999, 'currency' => 'EUR', 'stock' => 3, 'images' => '[1]', 'category_ids' => '[1]', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Чернова чаша', 'slug' => 'draft-cup', 'description' => '', 'status' => 'draft', 'sku' => null, 'price_minor' => 1000, 'sale_price_minor' => null, 'currency' => 'EUR', 'stock' => 0, 'images' => '[]', 'category_ids' => '[]', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->store = new SeoStore($this->db);
        $this->store->install();
        $this->store->install();
    }

    protected function tearDown(): void
    {
        $this->database->disconnect();
        unset($_ENV['APP_URL'], $_ENV['APP_NAME']);
    }

    private function site(bool $commerce = true): SeoSite
    {
        return new SeoSite($this->db, $commerce);
    }

    private function resolver(?SeoSite $site = null): SeoResolver
    {
        return new SeoResolver($this->store, $site ?? $this->site());
    }

    private function items(?SeoSite $site = null): SeoItems
    {
        $site ??= $this->site();

        return new SeoItems($this->store, $site, new SeoResolver($this->store, $site), new SeoAnalyzer());
    }

    /** @return array<string, mixed> */
    private function page(int $id): array
    {
        return \Flex\Pages\Page::query()->findOrFail($id)->toPublicArray();
    }

    public function testStoreValidatesSavesPartiallyAndDeletes(): void
    {
        $saved = $this->store->save('page', 2, ['seo_title' => '  Керамика   от Троян ', 'og_image_media_id' => 1]);
        self::assertSame('Керамика от Троян', $saved['seo_title']);
        self::assertSame(1, $saved['og_image_media_id']);
        $saved = $this->store->save('page', 2, ['meta_description' => 'Описание']);
        self::assertSame('Керамика от Троян', $saved['seo_title'], 'Missing keys keep stored values.');
        foreach ([
            ['canonical_url' => '//evil.example'],
            ['canonical_url' => 'javascript:alert(1)'],
            ['seo_title' => str_repeat('а', 191)],
            ['og_image_media_id' => 2],
            ['seo_title' => "bad\x01"],
        ] as $input) {
            try {
                $this->store->save('page', 2, $input);
                self::fail('Expected validation error for ' . json_encode($input));
            } catch (\InvalidArgumentException $error) {
                self::assertSame(422, $error->getCode());
            }
        }
        $lenient = $this->store->save('page', 2, ['seo_title' => str_repeat('б', 200), 'canonical_url' => '//evil.example'], true);
        self::assertSame(190, mb_strlen($lenient['seo_title']));
        self::assertSame('', $lenient['canonical_url']);
        $this->store->delete('page', 2);
        self::assertFalse($this->store->hasValues($this->store->get('page', 2)));
    }

    public function testSettingsValidation(): void
    {
        $settings = $this->store->saveSettings(['title_template' => '{title} — {site}', 'twitter_site' => '@flex_shop', 'site_url' => 'https://flex.example/', 'robots_extra' => "Disallow: /private\n# note"]);
        self::assertSame('flex_shop', $settings['twitter_site']);
        self::assertSame('https://flex.example', $settings['site_url']);
        foreach ([
            ['title_template' => 'Без заглавие'],
            ['twitter_site' => 'има интервал'],
            ['site_url' => 'ftp://flex.example'],
            ['robots_extra' => "Disallow: /a\nне е правило"],
            ['sitemap_pages' => 'yes'],
            ['default_image_media_id' => 2],
        ] as $input) {
            try {
                $this->store->saveSettings($input);
                self::fail('Expected validation error for ' . json_encode($input, JSON_UNESCAPED_UNICODE));
            } catch (\InvalidArgumentException $error) {
                self::assertSame(422, $error->getCode());
            }
        }
    }

    public function testMigratesLegacyJsonOnceAndMovesNoIndexToThePage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seo');
        file_put_contents($path, json_encode(['2' => ['seo_title' => 'Стар', 'focus_keyword' => 'керамика'], '3' => ['no_index' => true], 'x' => ['seo_title' => 'bad'], '6' => []]));
        self::assertSame(1, $this->store->migrateJson($path));
        self::assertFileDoesNotExist($path);
        self::assertFileExists($path . '.migrated');
        self::assertSame('Стар', $this->store->get('page', 2)['seo_title']);
        self::assertFalse($this->store->hasValues($this->store->get('page', 3)));
        self::assertTrue(json_decode((string) $this->db->table('pages')->where('id', 3)->value('settings'), true)['no_index']);
        self::assertTrue(json_decode((string) $this->db->table('pages')->where('id', 3)->value('settings'), true)['use_parent_slugs']);
        file_put_contents($path, json_encode(['4' => ['seo_title' => 'Повторно']]));
        self::assertSame(0, $this->store->migrateJson($path), 'Existing rows prevent a second import.');
        @unlink($path);
        @unlink($path . '.migrated');
    }

    public function testPageHeadUsesFallbacksCanonicalBreadcrumbsAndEscaping(): void
    {
        $resolver = $this->resolver();
        $about = $resolver->resolve(['page' => $this->page(2)]);
        self::assertSame('За нас | Магазин Флекс', $about['title']);
        self::assertSame('История Ние правим керамика от 1998 година в малка работилница край Троян.', $about['description']);
        self::assertSame($this->base . '/about-us', $about['canonical']);
        self::assertNull($about['robots']);
        self::assertSame($this->base . '/media-files/1/original', $about['image']['url']);
        self::assertSame('BreadcrumbList', $about['json_ld'][0]['@type']);

        $team = $resolver->page($this->page(3));
        self::assertSame($this->base . '/about-us/team', $team['canonical']);
        self::assertSame(['Магазин Флекс', 'За нас', 'Екип'], array_column($team['json_ld'][0]['itemListElement'], 'name'));

        $home = $resolver->page($this->page(1));
        self::assertSame($this->base . '/', $home['canonical']);
        self::assertSame('WebSite', $home['json_ld'][0]['@type']);
        self::assertSame('noindex, follow', $resolver->page($this->page(4))['robots']);

        $this->store->save('page', 2, ['seo_title' => 'Керамика "Троян" <b>', 'canonical_url' => '/original', 'og_title' => 'Споделяне']);
        $html = $this->resolver()->head($this->resolver()->page($this->page(2)));
        self::assertStringContainsString('<link rel="canonical" href="' . $this->base . '/original" data-flex-seo>', $html);
        self::assertStringContainsString('<meta property="og:title" content="Споделяне" data-flex-seo>', $html);
        self::assertStringContainsString('<meta property="og:image:alt" content="Корица &lt;alt&gt;" data-flex-seo>', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image" data-flex-seo>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertSame('Керамика "Троян" <b>', $this->resolver()->page($this->page(2))['title'], 'The theme escapes the document title.');
    }

    public function testHeadRendererNeverBreaksOutOfJsonLd(): void
    {
        $html = (new HeadRenderer())->render(['json_ld' => [['@type' => 'Thing', 'name' => '</script><script>alert(1)</script>']]]);
        self::assertStringNotContainsString('</script><script>', $html);
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringContainsString('"@context":"https://schema.org"', $html);
    }

    public function testHeadOutputIsWrappedInSignatureComments(): void
    {
        $html = (new HeadRenderer('Flex SEO 0.2.0'))->render(['description' => 'Описание']);
        self::assertStringStartsWith("<!-- This site is optimized with Flex SEO 0.2.0 -->\n<meta name=\"description\"", $html);
        self::assertStringEndsWith("<!-- / Flex SEO 0.2.0 -->\n", $html);
        $unsafe = (new HeadRenderer('Bad --> <script>'))->render(['description' => 'x']);
        self::assertSame(2, substr_count($unsafe, '-->'), 'The signature cannot close the comment early.');
        $resolver = new SeoResolver($this->store, $this->site(), 'Flex SEO 9.9.9');
        self::assertStringContainsString('<!-- / Flex SEO 9.9.9 -->', $resolver->head($resolver->page($this->page(2))));
    }

    public function testCommerceProductCategoryAndPrivatePages(): void
    {
        $resolver = $this->resolver();
        $product = $this->items()->products(true)[0];
        $resolved = $resolver->resolve(['commerce' => ['type' => 'product', 'path' => '/product/troyan-cup', 'product' => $product, 'title' => 'Чаша „Троян“']]);
        self::assertSame('Чаша „Троян“ | Магазин Флекс', $resolved['title']);
        self::assertSame('Керамична чаша с традиционна шарка.', $resolved['description']);
        self::assertSame($this->base . '/product/troyan-cup', $resolved['canonical']);
        self::assertSame('product', $resolved['og_type']);
        $schema = $resolved['json_ld'][0];
        self::assertSame('Product', $schema['@type']);
        self::assertSame('19.99', $schema['offers']['price']);
        self::assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        self::assertSame('CUP-1', $schema['sku']);
        self::assertSame(['Магазин Флекс', 'Продукти', 'Чаши', 'Чаша „Троян“'], array_column($resolved['json_ld'][1]['itemListElement'], 'name'));

        $category = $resolver->commerce(['type' => 'category', 'path' => '/catalog/category/cups', 'category' => ['id' => 1, 'name' => 'Чаши', 'slug' => 'cups'], 'page' => 1, 'filtered' => false]);
        self::assertSame('Чаши за кафе и чай.', $category['description']);
        self::assertNull($category['robots']);
        $filtered = $resolver->commerce(['type' => 'catalog', 'path' => '/catalog', 'filtered' => true, 'title' => 'Продукти']);
        self::assertSame('noindex, follow', $filtered['robots']);
        self::assertSame($this->base . '/catalog', $filtered['canonical']);
        $checkout = $resolver->commerce(['type' => 'checkout', 'path' => '/checkout', 'title' => 'Поръчка']);
        self::assertSame('noindex, nofollow', $checkout['robots']);
        self::assertSame('', $checkout['canonical']);
        self::assertSame([], $checkout['json_ld']);
        self::assertSame('noindex, follow', $resolver->commerce(['type' => 'error', 'status' => 404])['robots']);
    }

    public function testAnalyzerReportsLengthsKeywordDuplicatesAndCanonical(): void
    {
        $analyzer = new SeoAnalyzer();
        $meta = ['seo_title' => 'Ръчно изработена керамика от Троян за дома', 'meta_description' => '', 'focus_keyword' => 'керамика троян'];
        $result = $analyzer->analyze([
            'title' => $meta['seo_title'], 'description' => '', 'meta' => $meta, 'slug' => 'keramika-troyan',
            'heading' => 'Керамика', 'text' => '', 'has_image' => false, 'robots' => 'noindex, follow', 'in_navigation' => true,
            'canonical' => 'https://other.example/a', 'base_url' => $this->base,
        ], ['titles' => [SeoAnalyzer::key($meta['seo_title']) => 2]]);
        $codes = array_column($result['checks'], 'code');
        self::assertSame('error', $result['status']);
        foreach (['description_missing', 'title_duplicate', 'keyword_title', 'keyword_heading', 'image_missing', 'noindex', 'canonical_external'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertNotContains('keyword_slug', $codes, 'Cyrillic keywords match transliterated slugs.');
        $good = $analyzer->analyze([
            'title' => 'Керамика от Троян – ръчно изработени чаши и купи',
            'description' => 'Керамика от Троян: ръчно изработени чаши, купи и подаръци с традиционна шарка и доставка в цялата страна.',
            'meta' => ['seo_title' => 'x', 'meta_description' => 'x', 'focus_keyword' => 'керамика'], 'slug' => 'keramika',
            'heading' => 'Керамика', 'text' => 'Керамика от нашата работилница.', 'has_image' => true, 'robots' => null,
            'canonical' => $this->base . '/keramika', 'base_url' => $this->base,
        ]);
        self::assertSame('good', $good['status']);
    }

    public function testSitemapListsOnlyIndexablePublicUrls(): void
    {
        $site = $this->site();
        $entries = SitemapController::entries($this->items($site), $site, new SeoResolver($this->store, $site), $this->store->settings());
        $locs = array_column($entries, 'loc');
        self::assertSame([
            $this->base . '/',
            $this->base . '/about-us',
            $this->base . '/about-us/team',
            $this->base . '/catalog',
            $this->base . '/catalog/category/cups',
            $this->base . '/product/troyan-cup',
        ], $locs);
        // All stored dates are UTC.
        self::assertSame('2026-10-01T10:00:00Z', $entries[0]['lastmod']);
        self::assertSame('2026-10-01T10:00:00Z', $entries[5]['lastmod']);
        $this->store->save('page', 2, ['canonical_url' => '/']);
        $this->store->saveSettings(['sitemap_products' => false]);
        $without = array_column(SitemapController::entries($this->items($this->site(false)), $this->site(false), $this->resolver($this->site(false)), $this->store->settings()), 'loc');
        self::assertNotContains($this->base . '/about-us', $without, 'Pages with a different canonical URL are not listed.');
        self::assertNotContains($this->base . '/catalog', $without, 'Commerce URLs need an active Commerce plugin.');
        $xml = SitemapController::xml([['loc' => 'https://a.example/?a=1&b=2', 'lastmod' => null]]);
        self::assertNotFalse(simplexml_load_string($xml));
        self::assertStringContainsString('<loc>https://a.example/?a=1&amp;b=2</loc>', $xml);
    }

    public function testRobotsText(): void
    {
        $settings = $this->store->saveSettings(['robots_extra' => 'Disallow: /private']);
        $text = RobotsController::content($settings, $this->base, false);
        self::assertStringStartsWith("User-agent: *\nDisallow: /admin/\n", $text);
        self::assertStringContainsString("Disallow: /private\n", $text);
        self::assertStringEndsWith("Sitemap: {$this->base}/sitemap.xml\n", $text);
        self::assertSame("User-agent: *\nDisallow: /\n", RobotsController::content($settings, $this->base, true));
    }

    public function testAdminOverviewDetailPreviewAndFilters(): void
    {
        $items = $this->items();
        $overview = $items->overview();
        self::assertCount(9, $overview, 'Six pages, two products and one category, including drafts.');
        $detail = $items->detail('product', 1);
        self::assertSame('Чаша „Троян“ | Магазин Флекс', $detail['preview']['title']);
        $preview = $items->detail('page', 2, ['seo_title' => 'Ново заглавие', 'canonical_url' => '//bad']);
        self::assertSame('Ново заглавие', $preview['preview']['title']);
        self::assertSame($this->base . '/about-us', $preview['preview']['url'], 'Invalid draft canonical URLs are ignored.');
        self::assertSame('', $this->store->get('page', 2)['seo_title'], 'Previews are not saved.');
        self::assertNull($items->detail('page', 99));

        $controller = new \ReflectionClass(AdminController::class);
        $listing = $controller->newInstanceWithoutConstructor()->listing($overview, ['type' => 'product', 'q' => 'троян']);
        self::assertSame(1, $listing['count']);
        self::assertSame(9, array_sum($listing['totals']));
        $this->expectException(\InvalidArgumentException::class);
        $controller->newInstanceWithoutConstructor()->listing($overview, ['status' => 'bad']);
    }

    public function testEditorPreviewUsesUnsavedValuesForExistingAndNewRecords(): void
    {
        $items = $this->items();
        $page = $items->detail('page', 2, [], ['title' => 'Керамика от Троян', 'slug' => 'keramika', 'content' => '<p>Ръчно изработена керамика за дома и подаръци.</p>']);
        self::assertSame('Керамика от Троян | Магазин Флекс', $page['preview']['title']);
        self::assertSame($this->base . '/keramika', $page['preview']['url'], 'An unsaved slug is previewed.');
        self::assertSame('Ръчно изработена керамика за дома и подаръци.', $page['preview']['description']);

        $new = $items->detail('page', null, ['seo_title' => 'Нова страница за SEO'], ['title' => 'Нова', 'slug' => 'nova']);
        self::assertSame('Нова страница за SEO', $new['preview']['title']);
        self::assertSame($this->base . '/nova', $new['preview']['url']);

        $product = $items->detail('product', null, [], ['name' => 'Нова чаша', 'slug' => 'nova-chasha', 'description' => '<p>Чаша.</p>', 'price' => '12.50', 'sale_price' => '', 'currency' => 'EUR', 'stock' => '0', 'images' => [1]]);
        self::assertSame('Нова чаша | Магазин Флекс', $product['preview']['title']);
        self::assertSame($this->base . '/media-files/1/original', $product['preview']['image']);
        self::assertSame($this->base . '/product/nova-chasha', $product['preview']['url']);
        $resolved = $this->items()->resolveProduct(['id' => 0, 'name' => 'Нова чаша', 'slug' => 'x', 'price_minor' => 1250, 'currency' => 'EUR', 'available' => false, 'images' => []]);
        self::assertSame('12.50', $resolved['json_ld'][0]['offers']['price']);
    }

    public function testCategoriesHaveTheirOwnSeoValues(): void
    {
        $this->store->save('category', 1, ['seo_title' => 'Чаши за кафе и чай', 'meta_description' => 'Ръчно изработени чаши.']);
        $category = $this->items()->detail('category', 1);
        self::assertSame('category', $category['type']);
        self::assertSame('Чаши за кафе и чай', $category['preview']['title']);
        $public = $this->resolver()->commerce(['type' => 'category', 'path' => '/catalog/category/cups', 'category' => ['id' => 1, 'name' => 'Чаши', 'slug' => 'cups']]);
        self::assertSame('Чаши за кафе и чай', $public['title']);
        self::assertSame('Ръчно изработени чаши.', $public['description']);
        self::assertNull($this->items()->detail('category', 99));
    }

    public function testListingCanReturnEveryRowForTheColumn(): void
    {
        $rows = array_fill(0, 30, ['type' => 'page', 'name' => 'x', 'path' => '/x', 'preview' => ['title' => 'x'], 'analysis' => ['status' => 'good']]);
        $controller = (new \ReflectionClass(AdminController::class))->newInstanceWithoutConstructor();
        self::assertCount(25, $controller->listing($rows, [])['records']);
        self::assertCount(30, $controller->listing($rows, ['per_page' => '1000'])['records']);
        self::assertCount(30, $controller->listing($rows, ['per_page' => '99999'])['records']);
    }

    public function testUninstallKeepsDataUnlessRequested(): void
    {
        $this->store->save('page', 2, ['seo_title' => 'Запазено']);
        $this->store->uninstall();
        self::assertSame('Запазено', $this->store->get('page', 2)['seo_title']);
        $this->store->saveSettings(['delete_on_uninstall' => true]);
        $this->store->uninstall();
        self::assertFalse($this->db->getSchemaBuilder()->hasTable('flex_seo_meta'));
    }
}
