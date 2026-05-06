<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Api;

/**
 * Optional companion to FixtureInterface for fixtures that accept
 * per-deploy configuration via the CLI.
 *
 * Examples:
 *   - ProductReviewsFixture: reviews-per-product range, star skew, seed
 *   - SimpleProductFixture: a category whitelist, a max-product cap
 *   - ImageryFixture: skip-media flag, alternate image directory
 *
 * The keys a fixture honours are documented in its describeOptions()
 * return value, which the deploy command renders into `--help` so the
 * available knobs are discoverable.
 *
 * @api
 */
interface ConfigurableFixtureInterface
{
    /**
     * Apply runtime options before execute() runs. Called once per
     * deploy. Unknown keys are ignored (forward-compatible).
     *
     * @param array<string, scalar|array<scalar>> $options
     */
    public function configure(array $options): void;

    /**
     * Self-documenting option schema. Keys are option names (kebab-case
     * by convention), values describe the option:
     *
     *   ['default' => mixed, 'type' => 'int|string|bool|range|enum',
     *    'description' => string, 'enum' => list<string>?]
     *
     * Used by the deploy command to surface options in --help and to
     * type-coerce raw CLI values before passing to configure().
     *
     * @return array<string, array<string, mixed>>
     */
    public function describeOptions(): array;
}
