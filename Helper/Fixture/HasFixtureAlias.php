<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

/**
 * Optional trait that lets a fixture declare a short, human-friendly
 * alias for use in the CLI (e.g. `reviews` instead of
 * `ProductReviewsFixture`).
 *
 * Default is null — fixtures that don't override the static method
 * fall back to the auto-derived kebab-case-without-suffix in
 * {@see FixtureAliasResolver}.
 *
 * Usage:
 *
 *     class ProductReviewsFixture implements FixtureInterface
 *     {
 *         use HasFixtureAlias;
 *
 *         public static function alias(): ?string
 *         {
 *             return 'reviews';
 *         }
 *     }
 */
trait HasFixtureAlias
{
    public static function alias(): ?string
    {
        return null;
    }
}
