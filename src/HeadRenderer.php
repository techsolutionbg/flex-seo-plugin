<?php

declare(strict_types=1);

namespace Flex\Seo;

/** Turns resolved SEO data into escaped <head> markup. Every tag is marked with data-flex-seo. */
final class HeadRenderer
{
    /** @param string $signature Plugin name and version shown in the HTML comments around the tags. */
    public function __construct(private string $signature = 'Flex SEO') {}

    /**
     * @param array{
     *     description?: string, canonical?: string, robots?: string|null,
     *     og?: array<string, string>, image?: array{url: string, width?: int, height?: int, alt?: string}|null,
     *     twitter_site?: string, json_ld?: list<array<string, mixed>>
     * } $data
     */
    public function render(array $data): string
    {
        $tags = [];
        if (($data['description'] ?? '') !== '') {
            $tags[] = self::meta('name', 'description', $data['description']);
        }
        if (($data['robots'] ?? null) !== null) {
            $tags[] = self::meta('name', 'robots', (string) $data['robots']);
        }
        if (($data['canonical'] ?? '') !== '') {
            $tags[] = '<link rel="canonical" href="' . self::escape($data['canonical']) . '" data-flex-seo>';
        }
        foreach ($data['og'] ?? [] as $property => $value) {
            if ($value !== '') {
                $tags[] = self::meta('property', 'og:' . $property, $value);
            }
        }
        $image = $data['image'] ?? null;
        if (is_array($image) && ($image['url'] ?? '') !== '') {
            $tags[] = self::meta('property', 'og:image', $image['url']);
            if (($image['width'] ?? 0) > 0 && ($image['height'] ?? 0) > 0) {
                $tags[] = self::meta('property', 'og:image:width', (string) $image['width']);
                $tags[] = self::meta('property', 'og:image:height', (string) $image['height']);
            }
            if (($image['alt'] ?? '') !== '') {
                $tags[] = self::meta('property', 'og:image:alt', $image['alt']);
            }
        }
        $tags[] = self::meta('name', 'twitter:card', is_array($image) ? 'summary_large_image' : 'summary');
        if (($data['twitter_site'] ?? '') !== '') {
            $tags[] = self::meta('name', 'twitter:site', '@' . $data['twitter_site']);
        }
        foreach ($data['json_ld'] ?? [] as $graph) {
            $json = json_encode(['@context' => 'https://schema.org'] + $graph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $tags[] = '<script type="application/ld+json" data-flex-seo>' . $json . '</script>';
            }
        }

        if ($tags === []) {
            return '';
        }
        // "--" and ">" cannot end the comment early; other characters are safe inside a comment.
        $signature = trim(str_replace(['--', '>'], ['-', ''], $this->signature));

        return '<!-- This site is optimized with ' . $signature . " -->\n" . implode("\n", $tags) . "\n<!-- / " . $signature . " -->\n";
    }

    private static function meta(string $attribute, string $name, string $content): string
    {
        return '<meta ' . $attribute . '="' . self::escape($name) . '" content="' . self::escape($content) . '" data-flex-seo>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
