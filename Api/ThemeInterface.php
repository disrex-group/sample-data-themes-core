<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Api;

/**
 * Contract for a sample-data theme.
 *
 * A "theme" here is a named bundle of demo content (catalog + translations +
 * media) — not a Magento frontend theme. Theme packages register an
 * implementation of this interface in the {@see ThemeRegistry} via di.xml.
 *
 * @api
 */
interface ThemeInterface
{
    /**
     * Unique theme identifier (kebab-case). Used on the CLI and as the
     * registry key.
     *
     * @example 'home-living'
     */
    public function getCode(): string;

    /**
     * Human-readable name shown in CLI listings and prompts.
     */
    public function getName(): string;

    /**
     * One-line description shown alongside the name.
     */
    public function getDescription(): string;

    /**
     * Ordered list of fixture FQCNs to execute. Order matters: attributes →
     * attribute sets → categories → products → links.
     *
     * @return array<int, class-string<FixtureInterface>>
     */
    public function getFixtures(): array;

    /**
     * Absolute filesystem path to the theme's `_files/` directory.
     */
    public function getFixturesPath(): string;

    /**
     * Optional Magento module dependencies (e.g. a sibling media package).
     * The framework checks they are enabled and skips features that need
     * them when they are not.
     *
     * @return array<int, string>  Magento module names like
     *                             "Disrex_SampleDataThemeHomeLivingMedia"
     */
    public function getOptionalDependencies(): array;

    /**
     * Locales for which this theme ships translations under
     * `_files/i18n/<locale>/`.
     *
     * @return array<int, string>  ICU locale codes, e.g. ['en_US', 'nl_NL']
     */
    public function getSupportedLocales(): array;

    /**
     * Canonical / source locale. Used as the fallback when a translation is
     * missing in another locale.
     *
     * @return string  ICU locale code, e.g. 'en_US'
     */
    public function getDefaultLocale(): string;

    /**
     * Theme content version. Independent of the composer package version so
     * that a theme can re-publish its content without a code release.
     */
    public function getVersion(): string;

    /**
     * SKU prefix that scopes write operations to this theme's catalog.
     * Used by helpers that mutate the live catalog (e.g. promoting a
     * locale onto the default storeview, regenerating URL rewrites) so
     * they don't touch SKUs from other sample-data themes or from the
     * operator's own products.
     *
     * Convention: short uppercase prefix ending in a separator,
     * e.g. `DRX-HL-` for the disrex Home & Living theme. Themes that
     * don't enforce a prefix can return an empty string, but then
     * locale promotion and similar operations will refuse to run on a
     * shared install.
     */
    public function getSkuPrefix(): string;
}
