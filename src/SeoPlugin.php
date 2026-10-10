<?php

declare(strict_types=1);

namespace Flex\Seo;

use Flex\Extension\V1\{BootablePluginInterface, EventNames, ExtensionEventInterface, PageEvent, PluginContext, PluginInterface, UninstallablePluginInterface, UpdatablePluginInterface};

final class SeoPlugin implements PluginInterface, BootablePluginInterface, UpdatablePluginInterface, UninstallablePluginInterface
{
    private ?SeoResolver $resolver = null;
    private string $signature = 'Flex SEO';

    public function install(PluginContext $context): void
    {
        $store = new SeoStore();
        $store->install();
        $store->migrateJson(rtrim($context->path, '/\\') . '/data/seo.json');
    }

    public function activate(PluginContext $context): void
    {
        $this->install($context);
    }

    public function update(PluginContext $context, string $fromVersion): void
    {
        $this->install($context);
    }

    public function deactivate(PluginContext $context): void {}

    public function uninstall(PluginContext $context): void
    {
        (new SeoStore())->uninstall();
    }

    public function boot(PluginContext $context): void
    {
        $name = is_string($context->manifest['name'] ?? null) ? $context->manifest['name'] : 'Flex SEO';
        $this->signature = trim($name . ' ' . $context->version);
        $context->pageFields?->text('seo_title', 'SEO заглавие', '', 'Заглавие в резултатите от търсене. Празно поле използва шаблона от настройките на SEO.', 190);
        $context->pageFields?->textarea('meta_description', 'Meta описание', '', 'Описание в резултатите от търсене, препоръчително 70–160 символа. Празно поле използва началото на съдържанието.', 320);
        $context->pageFields?->text('focus_keyword', 'Основна ключова дума', '', 'Използва се само за проверките в раздел SEO.', 190);
        $context->pageFields?->text('og_title', 'Заглавие за социални мрежи', '', 'Open Graph заглавие при споделяне. Празно поле използва SEO заглавието.', 190);
        $context->pageFields?->textarea('og_description', 'Описание за социални мрежи', '', 'Open Graph описание при споделяне. Празно поле използва meta описанието.', 320);
        $context->pageFields?->text('canonical_url', 'Canonical URL', '', 'Само ако съдържанието е копие на друга страница. Път като /about или пълен адрес.', 500);
        $context->pageFields?->values(static function (int $pageId): array {
            try {
                return array_intersect_key((new SeoStore())->get('page', $pageId), SeoStore::FIELDS);
            } catch (\Throwable) {
                return [];
            }
        });
        $save = static function (ExtensionEventInterface $event) use ($context): void {
            if (!$event instanceof PageEvent || !is_int($event->page['id'] ?? null)) {
                return;
            }
            $values = array_intersect_key($event->fields[$context->id] ?? [], SeoStore::FIELDS);
            if ($values !== []) {
                (new SeoStore())->save('page', $event->page['id'], $values, true);
            }
        };
        $context->api->listen(EventNames::PAGE_CREATED, $save);
        $context->api->listen(EventNames::PAGE_UPDATED, $save);
        $context->api->listen(EventNames::PAGE_DELETED, static function (ExtensionEventInterface $event): void {
            if ($event instanceof PageEvent && is_int($event->page['id'] ?? null)) {
                (new SeoStore())->delete('page', $event->page['id']);
            }
        });

        $context->api->addFilter('public.head', function (mixed $html, array $filterContext = []): mixed {
            $resolved = $this->resolve($filterContext);

            return $resolved === null || !is_string($html) ? $html : $html . $this->resolver()->head($resolved);
        }, 20);
        $context->api->addFilter('public.title', function (mixed $title, array $filterContext = []): mixed {
            $resolved = $this->resolve($filterContext);

            return $resolved === null ? $title : $resolved['title'];
        }, 20);

        $context->routes?->publicRoute('GET', '/sitemap.xml', SitemapController::class, 'sitemap');
        $context->routes?->publicRoute('GET', '/robots.txt', RobotsController::class, 'robots');
        $context->routes?->admin('GET', '/items', AdminController::class, 'items');
        foreach (['page', 'product'] as $type) {
            $context->routes?->admin(['GET', 'PUT'], '/items/' . $type . '/{id:number}', AdminController::class, 'item-' . $type);
            $context->routes?->admin('POST', '/items/' . $type . '/{id:number}/preview', AdminController::class, 'preview-' . $type);
        }
        $context->routes?->admin(['GET', 'PUT'], '/settings', AdminController::class, 'settings');
        $context->admin?->page('seo', 'SEO', 'assets/admin.js', false, ['group' => 'content', 'icon' => 'file-text', 'order' => 40]);
    }

    /** @param array<string, mixed> $context @return array<string, mixed>|null */
    private function resolve(array $context): ?array
    {
        try {
            return $this->resolver()->resolve($context);
        } catch (\Throwable $error) {
            // SEO output must never break a public page.
            error_log('Flex SEO: ' . $error->getMessage());

            return null;
        }
    }

    private function resolver(): SeoResolver
    {
        return $this->resolver ??= new SeoResolver(new SeoStore(), new SeoSite(), $this->signature);
    }
}
