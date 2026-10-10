<?php

declare(strict_types=1);

namespace Flex\Seo;

use Flex\Extension\V1\BootablePluginInterface;
use Flex\Extension\V1\EventNames;
use Flex\Extension\V1\ExtensionEventInterface;
use Flex\Extension\V1\PageEvent;
use Flex\Extension\V1\PluginContext;
use Flex\Extension\V1\PluginInterface;

final class SeoPlugin implements PluginInterface, BootablePluginInterface
{
    public function install(PluginContext $context): void {}

    public function activate(PluginContext $context): void {}

    public function deactivate(PluginContext $context): void {}

    public function boot(PluginContext $context): void
    {
        $context->pageFields?->text('seo_title', 'SEO заглавие', '', 'Заглавието, което ще се използва в търсачките.', 190);
        $context->pageFields?->textarea('meta_description', 'Meta описание', '', 'Кратко описание на страницата за търсачките.', 320);
        $context->pageFields?->text('focus_keyword', 'Основна ключова дума', '', 'Основната ключова дума или фраза за оптимизация на страницата.', 190);
        $context->pageFields?->text('og_title', 'Open Graph заглавие', '', 'Заглавие за споделяне на страницата в социални мрежи.', 190);
        $context->pageFields?->text('canonical_url', 'Canonical URL', '', 'Каноничният адрес на страницата.', 500);
        $context->pageSettings?->checkbox('no_index', 'Не индексирай страницата', false, 'Забранява показването на страницата в резултатите от търсачките.');

        $context->pageFields?->values(fn (int $pageId): array => $this->read($context, $pageId));
        $context->pageSettings?->values(fn (int $pageId): array => $this->read($context, $pageId));
        $context->api->listen(EventNames::PAGE_CREATED, function (ExtensionEventInterface $event) use ($context): void {
            $this->saveEvent($context, $event);
        });
        $context->api->listen(EventNames::PAGE_UPDATED, function (ExtensionEventInterface $event) use ($context): void {
            $this->saveEvent($context, $event);
        });
        $context->api->listen(EventNames::PAGE_DELETED, function (ExtensionEventInterface $event) use ($context): void {
            $this->deleteEvent($context, $event);
        });
    }

    private function saveEvent(PluginContext $context, ExtensionEventInterface $event): void
    {
        if (!$event instanceof PageEvent || !isset($event->page['id']) || !is_int($event->page['id'])) return;
        $all = $this->all($context);
        $key = (string) $event->page['id'];
        $all[$key] = [
            ...($all[$key] ?? []),
            ...($event->fields[$context->id] ?? []),
        ];
        $this->write($context, $all);
    }

    private function deleteEvent(PluginContext $context, ExtensionEventInterface $event): void
    {
        if (!$event instanceof PageEvent || !isset($event->page['id'])) return;
        $all = $this->all($context);
        unset($all[(string) $event->page['id']]);
        $this->write($context, $all);
    }

    /** @return array<string, array<string, string|bool>> */
    private function all(PluginContext $context): array
    {
        $path = $this->dataPath($context);
        if (!is_file($path)) return [];
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string|bool> */
    private function read(PluginContext $context, int $pageId): array
    {
        $all = $this->all($context);
        $values = $all[(string) $pageId] ?? [];
        return is_array($values) ? $values : [];
    }

    /** @param array<string, array<string, string|bool>> $values */
    private function write(PluginContext $context, array $values): void
    {
        $path = $this->dataPath($context);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
    }

    private function dataPath(PluginContext $context): string
    {
        return rtrim($context->path, '/') . '/data/seo.json';
    }
}
