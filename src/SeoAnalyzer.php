<?php

declare(strict_types=1);

namespace Flex\Seo;

/** Content checks shown in the SEO administration. Messages are shown to editors as-is. */
final class SeoAnalyzer
{
    public const TITLE_MIN = 30;
    public const TITLE_MAX = 60;
    public const DESCRIPTION_MIN = 70;
    public const DESCRIPTION_MAX = 160;

    private const TRANSLITERATION = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sht',
        'ъ' => 'a', 'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * @param array{
     *     title: string, description: string, meta: array<string, mixed>, slug: string, heading: string,
     *     text: string, has_image: bool, robots: string|null, in_navigation?: bool, canonical: string, base_url: string
     * } $item
     * @param array{titles?: array<string, int>, descriptions?: array<string, int>} $counts Occurrences across all items.
     * @return array{status: string, checks: list<array{level: string, code: string, message: string}>}
     */
    public function analyze(array $item, array $counts = []): array
    {
        $checks = [];
        $add = static function (string $level, string $code, string $message) use (&$checks): void {
            $checks[] = ['level' => $level, 'code' => $code, 'message' => $message];
        };
        $title = $item['title'];
        $titleLength = mb_strlen($title);
        if ($item['meta']['seo_title'] === '') {
            $add('info', 'title_auto', 'Заглавието се генерира автоматично от шаблона.');
        }
        if ($titleLength < self::TITLE_MIN) {
            $add('warning', 'title_short', sprintf('Заглавието е %d символа. Препоръчително е между %d и %d.', $titleLength, self::TITLE_MIN, self::TITLE_MAX));
        } elseif ($titleLength > self::TITLE_MAX) {
            $add('warning', 'title_long', sprintf('Заглавието е %d символа и може да бъде съкратено в резултатите. Препоръчително е до %d.', $titleLength, self::TITLE_MAX));
        }
        $description = $item['description'];
        $descriptionLength = mb_strlen($description);
        if ($description === '') {
            $add('error', 'description_missing', 'Липсва описание. Търсачките ще изберат текст сами.');
        } else {
            if ($item['meta']['meta_description'] === '') {
                $add('info', 'description_auto', 'Описанието се взима автоматично от съдържанието.');
            }
            if ($descriptionLength < self::DESCRIPTION_MIN) {
                $add('warning', 'description_short', sprintf('Описанието е %d символа. Препоръчително е между %d и %d.', $descriptionLength, self::DESCRIPTION_MIN, self::DESCRIPTION_MAX));
            } elseif ($descriptionLength > self::DESCRIPTION_MAX) {
                $add('warning', 'description_long', sprintf('Описанието е %d символа и може да бъде съкратено. Препоръчително е до %d.', $descriptionLength, self::DESCRIPTION_MAX));
            }
        }
        if (($counts['titles'][self::key($title)] ?? 0) > 1) {
            $add('warning', 'title_duplicate', 'Друга страница или продукт има същото заглавие.');
        }
        if ($description !== '' && ($counts['descriptions'][self::key($description)] ?? 0) > 1) {
            $add('warning', 'description_duplicate', 'Друга страница или продукт има същото описание.');
        }
        $keyword = trim((string) $item['meta']['focus_keyword']);
        if ($keyword === '') {
            $add('info', 'keyword_missing', 'Не е зададена ключова дума за проверка.');
        } else {
            $places = [
                'keyword_title' => [$title, 'заглавието'],
                'keyword_description' => [$description, 'описанието'],
                'keyword_heading' => [$item['heading'], 'основния заглавен текст (H1)'],
                'keyword_intro' => [mb_substr($item['text'], 0, 400), 'началото на съдържанието'],
            ];
            foreach ($places as $code => [$text, $label]) {
                if (!self::contains($text, $keyword)) {
                    $add('warning', $code, 'Ключовата дума липсва в ' . $label . '.');
                }
            }
            if (!str_contains(self::slug($item['slug']), self::slug($keyword))) {
                $add('warning', 'keyword_slug', 'Ключовата дума липсва в адреса (slug).');
            }
        }
        if (!$item['has_image']) {
            $add('warning', 'image_missing', 'Няма изображение за споделяне в социалните мрежи.');
        }
        if ($item['robots'] !== null && str_contains($item['robots'], 'noindex')) {
            $add(($item['in_navigation'] ?? false) ? 'warning' : 'info', 'noindex', ($item['in_navigation'] ?? false)
                ? 'Страницата е скрита от търсачките, но е в навигацията.'
                : 'Страницата е скрита от търсачките.');
        }
        $canonicalHost = parse_url($item['canonical'], PHP_URL_HOST);
        $baseHost = parse_url($item['base_url'], PHP_URL_HOST);
        if (is_string($canonicalHost) && is_string($baseHost) && strcasecmp($canonicalHost, $baseHost) !== 0) {
            $add('warning', 'canonical_external', 'Каноничният адрес сочи към друг домейн. Търсачките ще индексират него.');
        }
        $levels = array_column($checks, 'level');

        return [
            'status' => in_array('error', $levels, true) ? 'error' : (in_array('warning', $levels, true) ? 'warning' : 'good'),
            'checks' => $checks,
        ];
    }

    public static function key(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private static function contains(string $text, string $keyword): bool
    {
        return $keyword !== '' && str_contains(self::key($text), self::key($keyword));
    }

    private static function slug(string $value): string
    {
        $value = strtr(mb_strtolower(rawurldecode($value)), self::TRANSLITERATION);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?? '', '-');
    }
}
