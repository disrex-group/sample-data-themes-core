<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Api\Data;

/**
 * Read-only view of a registered theme. Used by the CLI to list themes and
 * by external integrations (e.g. an admin UI) without exposing the full
 * {@see \Disrex\SampleDataThemesCore\Api\ThemeInterface}.
 *
 * @api
 */
interface ThemeMetadataInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function getVersion(): string;

    /** @return array<int, string> */
    public function getSupportedLocales(): array;

    public function getDefaultLocale(): string;

    /**
     * Number of fixtures the theme will run. Useful as a coarse "size"
     * indicator in listings.
     */
    public function getFixtureCount(): int;
}
