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
        foreach (SeoStore::TYPES as $type) {
            $context->routes?->admin(['GET', 'PUT'], '/items/' . $type . '/{id:number}', AdminController::class, 'item-' . $type);
            $context->routes?->admin('POST', '/items/' . $type . '/{id:number}/preview', AdminController::class, 'preview-' . $type);
            $context->routes?->admin('POST', '/items/' . $type . '/new/preview', AdminController::class, 'preview-new-' . $type);
        }
        $context->routes?->admin(['GET', 'PUT'], '/settings', AdminController::class, 'settings');

        // SEO lives inside the platform screens: editors, the pages list and Settings → SEO.
        $context->admin?->panel('page.editor', 'meta', 'SEO', 'assets/panels.js', ['icon' => 'file-text', 'order' => 10]);
        $context->admin?->panel('pages.list.column', 'score', 'SEO', 'assets/panels.js');
        $context->admin?->panel('settings.tab', 'settings', 'SEO', 'assets/panels.js', ['icon' => 'file-text']);
        $context->admin?->panel('commerce.product.editor', 'product', 'SEO', 'assets/panels.js', ['icon' => 'file-text']);
        $context->admin?->panel('commerce.category.editor', 'category', 'SEO', 'assets/panels.js', ['icon' => 'file-text']);
        // Flex Commerce removes products and categories permanently from the trash.
        $context->api->addAction('commerce.catalog.deleted', static function (array $deleted): void {
            if (in_array($deleted['kind'] ?? null, ['product', 'category'], true) && is_int($deleted['id'] ?? null)) {
                (new SeoStore())->delete($deleted['kind'], $deleted['id']);
            }
        });
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
