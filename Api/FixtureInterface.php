<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Api;

/**
 * Contract for a single import step within a theme (e.g. "import simple
 * products", "create attribute sets").
 *
 * Implementations should be idempotent — running execute() twice must not
 * produce duplicate entities or errors. The runner does not wrap fixtures in
 * a transaction; each fixture is responsible for its own consistency.
 *
 * @api
 */
interface FixtureInterface
{
    /**
     * Apply the fixture. Must be safe to call multiple times.
     */
    public function execute(): void;

    /**
     * Reverse the fixture (used by `sampledata:theme:remove`).
     *
     * Implementations should remove only the entities they created. If
     * tracking is impractical (e.g. global attributes shared across themes)
     * a no-op rollback is acceptable.
     */
    public function rollback(): void;

    /**
     * Short label printed during execution, e.g. "Importing 24 simple
     * products".
     */
    public function getLabel(): string;
}
