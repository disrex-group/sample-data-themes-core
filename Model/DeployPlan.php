<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Model;

/**
 * The user's deploy choices, normalised into a single value object so
 * the runner doesn't have to consult flags, profiles AND interactive
 * answers separately.
 *
 * Built by the CLI from this precedence chain (each layer overrides
 * the previous):
 *   1. profile defaults (YAML in theme package)
 *   2. command-line flags (--skip, --only, --reviews-per-product, …)
 *   3. interactive prompts (only when neither profile nor flags were
 *      enough)
 *
 * A fixture's stable identifier is its short class name (e.g.
 * "SimpleProductFixture") — using the FQCN would couple the plan to
 * an internal namespace.
 */
final class DeployPlan
{
    /**
     * @param array<int, string> $skip       Fixture short-names to skip
     * @param array<int, string>|null $only  When non-null, ONLY these run
     * @param array<string, ConflictAction> $conflictActions
     *                                       Per-fixture decision when
     *                                       state already exists
     * @param array<string, array<string, scalar|array<scalar>>> $options
     *                                       Per-fixture runtime options
     */
    public function __construct(
        public readonly array $skip = [],
        public readonly ?array $only = null,
        public readonly array $conflictActions = [],
        public readonly array $options = [],
        public readonly bool $dryRun = false,
    ) {
    }

    public function shouldRun(string $fixtureShortName): bool
    {
        if ($this->only !== null && !in_array($fixtureShortName, $this->only, true)) {
            return false;
        }
        return !in_array($fixtureShortName, $this->skip, true);
    }

    public function conflictAction(string $fixtureShortName): ConflictAction
    {
        return $this->conflictActions[$fixtureShortName] ?? ConflictAction::Merge;
    }

    /**
     * @return array<string, scalar|array<scalar>>
     */
    public function optionsFor(string $fixtureShortName): array
    {
        return $this->options[$fixtureShortName] ?? [];
    }
}
