<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

/**
 * Loads per-locale CSVs from a theme's `_files/i18n/<locale>/<entity>.csv`
 * directory, indexed by a key column (typically `sku`, `path`, or
 * `attribute_code`).
 *
 * Lookups fall back to the theme's default locale field-by-field, so a
 * partial translation never produces a blank string in the frontend — the
 * worst case is that the string remains in the source language.
 *
 * Results are cached per-instance keyed by directory + locale + entity, so
 * the same CSV is never parsed twice during one fixture run.
 */
class TranslationLoader
{
    /** @var array<string, array<string, array<string, string>>> */
    private array $cache = [];

    public function __construct(private readonly CsvParser $csvParser)
    {
    }

    /**
     * @return array<string, array<string, string>> Map of entity-key => row.
     */
    public function load(string $i18nDir, string $locale, string $entity, string $keyCol): array
    {
        $cacheKey = $this->cacheKey($i18nDir, $locale, $entity);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $path = sprintf('%s/%s/%s.csv', rtrim($i18nDir, '/'), $locale, $entity);
        if (!is_file($path)) {
            return $this->cache[$cacheKey] = [];
        }

        $rows = [];
        foreach ($this->csvParser->parse($path) as $row) {
            $key = $row[$keyCol] ?? null;
            if ($key === null || $key === '') {
                continue;
            }
            // First occurrence wins. Allows attribute CSVs that have one
            // logical "header" row (with frontend_label) followed by
            // option rows that share the same attribute_code.
            if (!isset($rows[$key])) {
                $rows[$key] = $row;
            }
        }

        return $this->cache[$cacheKey] = $rows;
    }

    /**
     * Return $field for $entityKey, falling back to $defaultLocale if the
     * value is missing or empty in $locale.
     */
    public function get(
        string $i18nDir,
        string $locale,
        string $defaultLocale,
        string $entity,
        string $keyCol,
        string $entityKey,
        string $field
    ): ?string {
        $primary = $this->load($i18nDir, $locale, $entity, $keyCol);
        if (isset($primary[$entityKey][$field]) && $primary[$entityKey][$field] !== '') {
            return $primary[$entityKey][$field];
        }
        if ($locale === $defaultLocale) {
            return null;
        }
        $fallback = $this->load($i18nDir, $defaultLocale, $entity, $keyCol);
        $value = $fallback[$entityKey][$field] ?? null;
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Return *all* rows for an entity in $entity.csv (e.g. attributes,
     * which have one row per option). Falls back to default locale if the
     * locale's CSV is missing or empty.
     *
     * @return array<int, array<string, string>>
     */
    public function loadAllRows(
        string $i18nDir,
        string $locale,
        string $defaultLocale,
        string $entity
    ): array {
        $rows = $this->loadRawRows($i18nDir, $locale, $entity);
        if ($rows === [] && $locale !== $defaultLocale) {
            $rows = $this->loadRawRows($i18nDir, $defaultLocale, $entity);
        }
        return $rows;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function loadRawRows(string $i18nDir, string $locale, string $entity): array
    {
        $path = sprintf('%s/%s/%s.csv', rtrim($i18nDir, '/'), $locale, $entity);
        if (!is_file($path)) {
            return [];
        }
        $rows = [];
        foreach ($this->csvParser->parse($path) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function cacheKey(string $i18nDir, string $locale, string $entity): string
    {
        return $i18nDir . '|' . $locale . '|' . $entity;
    }
}
