<?php

declare(strict_types=1);

namespace Flex\Seo;

use Flex\Contracts\Http\ResponseFactoryInterface;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final readonly class RobotsController
{
    /** Paths that never contain indexable content. */
    public const PRIVATE_PATHS = ['/admin/', '/cart', '/checkout', '/order-received', '/favourites', '/account'];

    public function __construct(private ResponseFactoryInterface $responses) {}

    public function __invoke(ServerRequestInterface $request, array $arguments = []): ResponseInterface
    {
        $store = new SeoStore();
        $site = new SeoSite();
        $settings = $store->settings();

        return $this->responses->text(self::content($settings, $site->baseUrl((string) $settings['site_url'], $request), $site->closed()))
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** @param array<string, mixed> $settings */
    public static function content(array $settings, string $base, bool $closed): string
    {
        if ($closed) {
            return "User-agent: *\nDisallow: /\n";
        }
        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $extra = trim((string) $settings['robots_extra']);
        if ($extra !== '') {
            $lines[] = '';
            $lines[] = $extra;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';

        return implode("\n", $lines) . "\n";
    }
}
