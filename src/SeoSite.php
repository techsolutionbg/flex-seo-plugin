<?php

declare(strict_types=1);

namespace Flex\Seo;

use Flex\Extensions\{Plugin, PluginManager};
use Flex\Pages\{Page, PageRepository, PublicPageSet};
use Illuminate\Database\{Connection, Eloquent\Model};
use Psr\Http\Message\ServerRequestInterface;

/** Read-only site data shared by head output, the sitemap, robots.txt and the admin analysis. */
final class SeoSite
{
    private Connection $db;
    /** @var array<string, string>|null */
    private ?array $general = null;
    /** @var array<string, string>|null */
    private ?array $public = null;
    private ?PublicPageSet $pageSet = null;
    private ?int $homePageId = null;
    /** @var array<int, array<string, mixed>|null> */
    private array $media = [];

    public function __construct(?Connection $db = null, private ?bool $commerceActive = null)
    {
        $this->db = $db ?? Model::resolveConnection();
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    public function name(): string
    {
        return $this->general()['name'];
    }

    public function description(): string
    {
        return $this->general()['description'];
    }

    public function locale(): string
    {
        return $this->general()['locale'] === 'en' ? 'en' : 'bg';
    }

    public function ogLocale(): string
    {
        return $this->locale() === 'en' ? 'en_US' : 'bg_BG';
    }

    public function closed(): bool
    {
        return $this->publicSettings()['closed'] === '1';
    }

    /** The configured site URL, then APP_URL, then the request origin. */
    public function baseUrl(string $configured = '', ?ServerRequestInterface $request = null): string
    {
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $env = trim((string) ($_ENV['APP_URL'] ?? ''));
        if ($env !== '' && preg_match('~^https?://~i', $env) === 1) {
            return rtrim($env, '/');
        }
        if ($request !== null) {
            $uri = $request->getUri();
            $port = $uri->getPort();

            return $uri->getScheme() . '://' . $uri->getHost() . ($port === null ? '' : ':' . $port);
        }

        return 'http://localhost';
    }

    public function absolute(string $base, string $path): string
    {
        if (preg_match('~^https?://~i', $path) === 1) {
            return $path;
        }

        return $base . '/' . ltrim($path, '/');
    }

    public function homePageId(): int
    {
        return $this->homePageId ??= $this->resolveHomePageId();
    }

    private function resolveHomePageId(): int
    {
        $id = (int) $this->publicSettings()['home_page_id'];
        if ($id > 0) {
            return $id;
        }
        // Mirrors the core fallback to app.public_home_slug.
        $slug = trim((string) ($_ENV['PUBLIC_HOME_SLUG'] ?? 'home'), '/');
        $page = $slug === '' ? null : $this->pageSet()?->findPublishedBySlug($slug);

        return $page instanceof Page ? (int) $page->getKey() : 0;
    }

    /** @param array<string, mixed> $page */
    public function pagePath(array $page): string
    {
        $id = (int) ($page['id'] ?? 0);
        if ($id > 0 && $id === $this->homePageId()) {
            return '/';
        }
        $model = $id > 0 ? $this->pageSet()?->findPublishedById($id) : null;

        $slug = trim((string) ($page['slug'] ?? ''), '/');
        // An unsaved slug from the editor wins over the published path.
        if ($model instanceof Page && $model->getAttribute('slug') === $slug) {
            return '/' . $this->pageSet()->publicPath($model);
        }

        return '/' . $slug;
    }

    /** @return list<array{title: string, path: string}> Published ancestors, nearest last. */
    public function ancestors(array $page): array
    {
        $result = [];
        $seen = [(int) ($page['id'] ?? 0) => true];
        $parentId = (int) ($page['parent_id'] ?? 0);
        while ($parentId > 0 && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = $this->pageSet()?->findPublishedById($parentId);
            if (!$parent instanceof Page) {
                break;
            }
            array_unshift($result, ['title' => (string) $parent->getAttribute('title'), 'path' => $this->pagePath($parent->toPublicArray())]);
            $parentId = (int) $parent->getAttribute('parent_id');
        }

        return $result;
    }

    /** @return list<Page> */
    public function publishedPages(): array
    {
        return array_values(array_filter(
            (new PageRepository())->all()->all(),
            static fn(Page $page): bool => $page->getAttribute('status') === 'published',
        ));
    }

    /** @return array{id: int, url: string, width: int, height: int, alt: string}|null */
    public function image(?int $id): ?array
    {
        if ($id === null || $id < 1) {
            return null;
        }
        if (!array_key_exists($id, $this->media)) {
            $row = $this->db->getSchemaBuilder()->hasTable('media')
                ? $this->db->table('media')->where('id', $id)->whereNull('deleted_at')->where('mime', 'like', 'image/%')->first()
                : null;
            $this->media[$id] = $row === null ? null : [
                'id' => $id,
                'url' => '/media-files/' . $id . '/original',
                'width' => (int) ($row->width ?? 0),
                'height' => (int) ($row->height ?? 0),
                'alt' => (string) ($row->alt ?? ''),
            ];
        }

        return $this->media[$id];
    }

    public function commerceActive(): bool
    {
        if ($this->commerceActive !== null) {
            return $this->commerceActive;
        }
        try {
            $plugin = Plugin::query()->find('flex/commerce');
            $this->commerceActive = $plugin instanceof Plugin
                && $plugin->getAttribute('status') === PluginManager::STATUS_ACTIVE
                && $this->db->getSchemaBuilder()->hasTable('flex_commerce_products');
        } catch (\Throwable) {
            $this->commerceActive = false;
        }

        return $this->commerceActive;
    }

    /** @return array<string, string> */
    private function general(): array
    {
        if ($this->general === null) {
            $this->general = ['name' => (string) ($_ENV['APP_NAME'] ?? 'Flex CMS'), 'description' => '', 'locale' => 'bg'];
            foreach ($this->settingsRows('general') as $key => $value) {
                $key = substr($key, strlen('site.'));
                if (array_key_exists($key, $this->general)) {
                    $this->general[$key] = $value;
                }
            }
        }

        return $this->general;
    }

    /** @return array<string, string> */
    private function publicSettings(): array
    {
        if ($this->public === null) {
            $this->public = ['home_page_id' => '0', 'closed' => '0'];
            foreach ($this->settingsRows('settings.public') as $key => $value) {
                $key = substr($key, strlen('settings.public.'));
                if (array_key_exists($key, $this->public)) {
                    $this->public[$key] = $value;
                }
            }
        }

        return $this->public;
    }

    /** @return array<string, string> */
    private function settingsRows(string $group): array
    {
        if (!$this->db->getSchemaBuilder()->hasTable('settings')) {
            return [];
        }
        $rows = [];
        foreach ($this->db->table('settings')->where('group', $group)->get(['key', 'value']) as $row) {
            $rows[(string) $row->key] = (string) $row->value;
        }

        return $rows;
    }

    private function pageSet(): ?PublicPageSet
    {
        try {
            return $this->pageSet ??= (new PageRepository())->publicPageSet();
        } catch (\Throwable) {
            return null;
        }
    }
}
