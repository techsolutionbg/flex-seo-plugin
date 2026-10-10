<?php

declare(strict_types=1);

namespace Flex\Seo;

use Illuminate\Database\{Connection, Eloquent\Model, Schema\Blueprint};

final class SeoStore
{
    public const TYPES = ['page', 'product', 'category'];
    /** Text fields and their maximum length in characters. */
    public const FIELDS = [
        'seo_title' => 190,
        'meta_description' => 320,
        'focus_keyword' => 190,
        'og_title' => 190,
        'og_description' => 320,
        'canonical_url' => 500,
    ];
    public const DEFAULTS = [
        'title_template' => '{title} | {site}',
        'home_title' => '',
        'default_image_media_id' => 0,
        'twitter_site' => '',
        'site_url' => '',
        'sitemap_pages' => true,
        'sitemap_products' => true,
        'sitemap_categories' => true,
        'robots_extra' => '',
        'delete_on_uninstall' => false,
    ];

    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Model::resolveConnection();
    }

    public function install(): void
    {
        $schema = $this->db->getSchemaBuilder();
        if (!$schema->hasTable('flex_seo_meta')) {
            $schema->create('flex_seo_meta', static function (Blueprint $t): void {
                $t->increments('id');
                $t->string('object_type', 16);
                $t->unsignedInteger('object_id');
                foreach (self::FIELDS as $field => $length) {
                    $t->string($field, $length)->default('');
                }
                $t->unsignedInteger('og_image_media_id')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['object_type', 'object_id']);
            });
        }
        if (!$schema->hasTable('flex_seo_settings')) {
            $schema->create('flex_seo_settings', static function (Blueprint $t): void {
                $t->integer('id')->primary();
                $t->text('values');
            });
            $this->db->table('flex_seo_settings')->insert(['id' => 1, 'values' => '{}']);
        }
    }

    public function uninstall(): void
    {
        if (!$this->db->getSchemaBuilder()->hasTable('flex_seo_settings') || !$this->settings()['delete_on_uninstall']) {
            return;
        }
        $this->db->getSchemaBuilder()->dropIfExists('flex_seo_meta');
        $this->db->getSchemaBuilder()->dropIfExists('flex_seo_settings');
    }

    /**
     * Moves values from the 0.1.x plugin-local JSON file once; the file is renamed so it is never imported twice.
     */
    public function migrateJson(string $path): int
    {
        if (!is_file($path) || $this->db->table('flex_seo_meta')->exists()) {
            return 0;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $count = 0;
        $this->db->transaction(function () use ($decoded, &$count): void {
            foreach (is_array($decoded) ? $decoded : [] as $pageId => $values) {
                if (!is_array($values) || filter_var($pageId, FILTER_VALIDATE_INT) === false || (int) $pageId < 1) {
                    continue;
                }
                if (($values['no_index'] ?? false) === true) {
                    $this->moveNoIndexToPage((int) $pageId);
                }
                $saved = $this->save('page', (int) $pageId, $values, true);
                if ($this->hasValues($saved)) {
                    $count++;
                } else {
                    $this->delete('page', (int) $pageId);
                }
            }
        });
        @rename($path, $path . '.migrated');

        return $count;
    }

    /** 0.1.x kept its own "no index" option; the page setting in the core is now the only one. */
    private function moveNoIndexToPage(int $pageId): void
    {
        if (!$this->db->getSchemaBuilder()->hasTable('pages')) {
            return;
        }
        $row = $this->db->table('pages')->where('id', $pageId)->first(['settings']);
        if ($row === null) {
            return;
        }
        $settings = json_decode((string) $row->settings, true);
        $settings = is_array($settings) ? $settings : [];
        $settings['no_index'] = true;
        $this->db->table('pages')->where('id', $pageId)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $raw = $this->db->table('flex_seo_settings')->where('id', 1)->value('values');
        $values = json_decode(is_string($raw) ? $raw : '{}', true);

        return array_replace(self::DEFAULTS, array_intersect_key(is_array($values) ? $values : [], self::DEFAULTS));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveSettings(array $input): array
    {
        $values = $this->settings();
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_bool($default)) {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('Невалидна настройка: ' . $key, 422);
                }
            } elseif (is_int($default)) {
                $value = filter_var($value, FILTER_VALIDATE_INT);
                if (!is_int($value) || $value < 0) {
                    throw new \InvalidArgumentException('Невалидна настройка: ' . $key, 422);
                }
            } elseif (!is_string($value)) {
                throw new \InvalidArgumentException('Невалидна настройка: ' . $key, 422);
            } else {
                $value = trim($value);
            }
            $values[$key] = $value;
        }
        $template = $values['title_template'];
        if ($template === '' || mb_strlen($template) > 190 || !str_contains($template, '{title}') || self::unsafeText($template)) {
            throw new \InvalidArgumentException('Шаблонът на заглавието трябва да съдържа {title} и да е до 190 символа.', 422);
        }
        if (mb_strlen($values['home_title']) > 190 || self::unsafeText($values['home_title'])) {
            throw new \InvalidArgumentException('Заглавието на началната страница е невалидно.', 422);
        }
        $values['twitter_site'] = ltrim($values['twitter_site'], '@');
        if ($values['twitter_site'] !== '' && preg_match('/^[A-Za-z0-9_]{1,15}$/', $values['twitter_site']) !== 1) {
            throw new \InvalidArgumentException('Невалиден профил в X (Twitter).', 422);
        }
        $values['site_url'] = rtrim($values['site_url'], '/');
        if ($values['site_url'] !== '' && (mb_strlen($values['site_url']) > 300 || !self::absoluteUrl($values['site_url']) || parse_url($values['site_url'], PHP_URL_QUERY) !== null)) {
            throw new \InvalidArgumentException('Адресът на сайта трябва да е HTTP или HTTPS адрес без параметри.', 422);
        }
        if (mb_strlen($values['robots_extra']) > 2000) {
            throw new \InvalidArgumentException('Допълнителните правила за robots.txt са до 2000 символа.', 422);
        }
        $lines = preg_split('/\R/u', $values['robots_extra']) ?: [];
        foreach ($lines as $line) {
            if (trim($line) !== '' && preg_match('/^(?:#[^\x00-\x1F\x7F]*|[A-Za-z-]+:[^\x00-\x1F\x7F]*)$/', trim($line)) !== 1) {
                throw new \InvalidArgumentException('Всеки ред в robots.txt трябва да е „Правило: стойност“ или коментар с #.', 422);
            }
        }
        $values['robots_extra'] = implode("\n", array_map('trim', $lines));
        if ($values['default_image_media_id'] > 0 && !$this->imageExists($values['default_image_media_id'])) {
            throw new \InvalidArgumentException('Избраното изображение не е намерено.', 422);
        }
        $this->db->table('flex_seo_settings')->where('id', 1)->update(['values' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

        return $this->settings();
    }

    /** @return array<string, mixed> */
    public function get(string $type, int $id): array
    {
        $this->assertType($type);
        $row = $this->db->table('flex_seo_meta')->where('object_type', $type)->where('object_id', $id)->first();

        return self::row($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $type): array
    {
        $this->assertType($type);
        $result = [];
        foreach ($this->db->table('flex_seo_meta')->where('object_type', $type)->get() as $row) {
            $result[(int) $row->object_id] = self::row($row);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input Partial values; missing keys keep the stored value.
     * @param bool $lenient Truncate and drop invalid values instead of failing (page editor events and migration).
     * @return array<string, mixed>
     */
    public function save(string $type, int $id, array $input, bool $lenient = false): array
    {
        $this->assertType($type);
        if ($id < 1) {
            throw new \InvalidArgumentException('Невалиден обект.', 422);
        }
        $values = $this->get($type, $id);
        foreach (self::FIELDS as $field => $length) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($value === null) {
                $value = '';
            }
            if (!is_string($value)) {
                if ($lenient) {
                    continue;
                }
                throw new \InvalidArgumentException('Невалидна стойност: ' . $field, 422);
            }
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
            if (mb_strlen($value) > $length) {
                if (!$lenient) {
                    throw new \InvalidArgumentException(sprintf('Полето %s е до %d символа.', $field, $length), 422);
                }
                $value = mb_substr($value, 0, $length);
            }
            if (self::unsafeText($value)) {
                if ($lenient) {
                    continue;
                }
                throw new \InvalidArgumentException('Полето ' . $field . ' съдържа непозволени символи.', 422);
            }
            if ($field === 'canonical_url' && $value !== '' && !self::canonicalAllowed($value)) {
                if ($lenient) {
                    continue;
                }
                throw new \InvalidArgumentException('Каноничният адрес трябва да започва с / или да е HTTP/HTTPS адрес.', 422);
            }
            $values[$field] = $value;
        }
        if (array_key_exists('og_image_media_id', $input)) {
            $media = $input['og_image_media_id'];
            $media = $media === null || $media === '' || $media === 0 ? null : filter_var($media, FILTER_VALIDATE_INT);
            if ($media !== null && (!is_int($media) || $media < 1 || !$this->imageExists($media))) {
                if (!$lenient) {
                    throw new \InvalidArgumentException('Избраното изображение не е намерено.', 422);
                }
            } else {
                $values['og_image_media_id'] = $media;
            }
        }
        $row = array_intersect_key($values, self::FIELDS) + ['og_image_media_id' => $values['og_image_media_id'], 'updated_at' => gmdate('Y-m-d H:i:s')];
        $this->db->table('flex_seo_meta')->updateOrInsert(['object_type' => $type, 'object_id' => $id], $row);

        return $this->get($type, $id);
    }

    public function delete(string $type, int $id): void
    {
        $this->assertType($type);
        $this->db->table('flex_seo_meta')->where('object_type', $type)->where('object_id', $id)->delete();
    }

    /** @param array<string, mixed> $values */
    public function hasValues(array $values): bool
    {
        foreach (array_keys(self::FIELDS) as $field) {
            if (($values[$field] ?? '') !== '') {
                return true;
            }
        }

        return ($values['og_image_media_id'] ?? null) !== null;
    }

    public static function canonicalAllowed(string $value): bool
    {
        if (str_starts_with($value, '/')) {
            return !str_starts_with($value, '//') && !str_contains($value, '\\');
        }

        return self::absoluteUrl($value);
    }

    private static function absoluteUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '' && !isset($parts['user']) && !str_contains($value, '\\');
    }

    private static function unsafeText(string $value): bool
    {
        // Output is always escaped; only control characters are rejected.
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1;
    }

    private function imageExists(int $id): bool
    {
        return $this->db->getSchemaBuilder()->hasTable('media')
            && $this->db->table('media')->where('id', $id)->whereNull('deleted_at')->where('mime', 'like', 'image/%')->exists();
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Невалиден тип.', 422);
        }
    }

    /** @return array<string, mixed> */
    private static function row(?object $row): array
    {
        $values = array_fill_keys(array_keys(self::FIELDS), '') + ['og_image_media_id' => null];
        if ($row === null) {
            return $values;
        }
        foreach (array_keys(self::FIELDS) as $field) {
            $values[$field] = (string) ($row->{$field} ?? '');
        }
        $values['og_image_media_id'] = $row->og_image_media_id === null ? null : (int) $row->og_image_media_id;

        return $values;
    }
}
