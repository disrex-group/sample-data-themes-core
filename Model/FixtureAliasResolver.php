<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Model;

use Disrex\SampleDataThemesCore\Api\ThemeInterface;

/**
 * Maps user-facing fixture identifiers to the internal short class name
 * that {@see DeployPlan} keys off.
 *
 * Three identifier forms resolve to the same fixture:
 *
 *   - Curated alias       (e.g. "reviews")        — declared by the
 *                                                   fixture's static
 *                                                   alias() method
 *   - Auto-derived alias  (e.g. "product-reviews") — kebab-case of the
 *                                                    short class name
 *                                                    minus the "Fixture"
 *                                                    suffix, used when
 *                                                    no curated alias
 *                                                    is declared
 *   - Short class name    (e.g. "ProductReviewsFixture") — the original
 *                                                          DeployPlan
 *                                                          identifier;
 *                                                          accepted for
 *                                                          backwards
 *                                                          compatibility
 *
 * The resolver is theme-scoped: a single theme's fixtures must have
 * unique aliases, but two themes may legally use the same alias for
 * their own fixtures.
 */
class FixtureAliasResolver
{
    /** @var array<string, string> alias → short class name (cache) */
    private array $aliasToShortName = [];

    /** @var array<string, string> short class name → preferred alias (cache) */
    private array $shortNameToAlias = [];

    /** @var string|null hash of the last theme passed to build(), so we rebuild on theme change */
    private ?string $lastThemeKey = null;

    public function build(ThemeInterface $theme): void
    {
        $key = $theme->getCode();
        if ($this->lastThemeKey === $key) {
            return;
        }
        $this->lastThemeKey = $key;
        $this->aliasToShortName = [];
        $this->shortNameToAlias = [];

        foreach ($theme->getFixtures() as $fqcn) {
            $shortName = self::shortNameOf($fqcn);

            // Curated alias takes precedence; fall back to auto-derived.
            $alias = null;
            if (method_exists($fqcn, 'alias')) {
                $alias = $fqcn::alias();
            }
            if (!is_string($alias) || $alias === '') {
                $alias = self::autoAlias($shortName);
            }

            $this->aliasToShortName[$alias] = $shortName;
            // The legacy short class name MUST also resolve, for back-compat.
            $this->aliasToShortName[$shortName] = $shortName;
            // Auto-derived alias resolves too even when a curated alias
            // is set, so users get two valid spellings without ambiguity.
            $autoAlias = self::autoAlias($shortName);
            $this->aliasToShortName[$autoAlias] = $shortName;

            $this->shortNameToAlias[$shortName] = $alias;
        }
    }

    /**
     * Translate any of (curated alias | auto-derived alias | short class
     * name) into the canonical short class name used by DeployPlan.
     *
     * Returns null if the identifier is unknown to this theme.
     */
    public function resolve(string $identifier): ?string
    {
        return $this->aliasToShortName[$identifier] ?? null;
    }

    /**
     * The display-name for a fixture, given its short class name. Used
     * by the wizard and the plan-preview table.
     */
    public function aliasFor(string $shortName): string
    {
        return $this->shortNameToAlias[$shortName] ?? $shortName;
    }

    /** @return array<string, string> alias → short class name */
    public function map(): array
    {
        // Filter out the legacy-shortname keys so callers iterating
        // aliases don't see "ProductReviewsFixture" as a separate entry.
        return array_filter(
            $this->aliasToShortName,
            fn (string $shortName, string $alias) => $alias !== $shortName,
            ARRAY_FILTER_USE_BOTH
        );
    }

    public static function shortNameOf(string $fqcn): string
    {
        return basename(str_replace('\\', '/', $fqcn));
    }

    /**
     * "ProductReviewsFixture" → "product-reviews"
     * "AttributeSetFixture"  → "attribute-set"
     */
    private static function autoAlias(string $shortName): string
    {
        $stem = preg_replace('/Fixture$/', '', $shortName) ?? $shortName;
        // Insert hyphens at lower-to-upper boundaries, then lowercase.
        $kebab = preg_replace('/(?<!^)([A-Z])/', '-$1', $stem) ?? $stem;
        return strtolower($kebab);
    }
}
