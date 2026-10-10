<?php

declare(strict_types=1);

namespace Flex\Seo;

use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Http\RequestInput;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final readonly class AdminController
{
    private const PER_PAGE = 25;

    public function __construct(private ResponseFactoryInterface $responses, private RequestInput $input) {}

    public function __invoke(ServerRequestInterface $request, array $arguments = []): ResponseInterface
    {
        $store = new SeoStore();
        $site = new SeoSite();
        $items = new SeoItems($store, $site, new SeoResolver($store, $site), new SeoAnalyzer());
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        try {
            if (str_ends_with($path, '/settings')) {
                $settings = $method === 'PUT' ? $store->saveSettings($this->body($request)) : $store->settings();

                return $this->json(['settings' => $settings, 'default_image' => $site->image((int) $settings['default_image_media_id']), 'site_name' => $site->name()]);
            }
            if (preg_match('~/items/(page|product|category)/(\d+|new)(/preview)?$~', $path, $match) === 1) {
                $type = $match[1];
                $id = $match[2] === 'new' ? null : (int) $match[2];
                if (($match[3] ?? '') !== '') {
                    // The editor sends its SEO draft plus the unsaved values of the record (source).
                    $body = $this->body($request);
                    $source = is_array($body['source'] ?? null) ? $body['source'] : [];
                    unset($body['source']);

                    return $this->json(['item' => $this->detail($items, $type, $id, $body, $source)]);
                }
                if ($id === null) {
                    throw new \InvalidArgumentException('Записът още не е създаден.', 422);
                }
                if ($method === 'PUT') {
                    $this->detail($items, $type, $id);
                    $store->save($type, $id, $this->body($request));
                }

                return $this->json(['item' => $this->detail($items, $type, $id)]);
            }

            return $this->json($this->listing($items->overview(), $request->getQueryParams()));
        } catch (\InvalidArgumentException $error) {
            $status = $error->getCode() >= 400 && $error->getCode() < 500 ? $error->getCode() : 422;

            return $this->json(['error' => ['message' => $error->getMessage()]], $status);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listing(array $rows, array $query): array
    {
        $type = is_string($query['type'] ?? null) ? $query['type'] : 'all';
        $status = is_string($query['status'] ?? null) ? $query['status'] : 'all';
        $search = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        if (!in_array($type, ['all', 'page', 'product', 'category'], true) || !in_array($status, ['all', 'good', 'warning', 'error'], true) || mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Невалиден филтър.', 422);
        }
        $totals = ['good' => 0, 'warning' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $totals[$row['analysis']['status']]++;
        }
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => ($type === 'all' || $row['type'] === $type)
            && ($status === 'all' || $row['analysis']['status'] === $status)
            && ($needle === '' || str_contains(mb_strtolower($row['name'] . ' ' . $row['preview']['title'] . ' ' . $row['path']), $needle))));
        // The pages list column asks for every page at once (per_page up to 1000).
        $perPage = max(1, min(1000, (int) ($query['per_page'] ?? self::PER_PAGE)));
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $page = max(1, min($pages, (int) ($query['page'] ?? 1)));

        return [
            'records' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'count' => count($rows),
            'page' => $page,
            'pages' => $pages,
            'totals' => $totals,
        ];
    }

    /** @param array<string, mixed>|null $draft @param array<string, mixed> $source @return array<string, mixed> */
    private function detail(SeoItems $items, string $type, ?int $id, ?array $draft = null, array $source = []): array
    {
        $detail = $items->detail($type, $id, $draft, $source);
        if ($detail === null) {
            throw new \InvalidArgumentException(match ($type) {
                'page' => 'Страницата не е намерена.',
                'product' => 'Продуктът не е намерен.',
                default => 'Категорията не е намерена.',
            }, 404);
        }

        return $detail;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $this->input->all($request);

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->responses->json($payload, $status, ['Cache-Control' => 'no-store']);
    }
}
