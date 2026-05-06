<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Model;

/**
 * What the runner should do when a state-aware fixture reports that
 * existing entities are present.
 *
 * Skip   — leave existing data alone, don't run execute()
 * Merge  — run execute() over the existing data; idempotent fixtures
 *          will reconcile, additive fixtures may produce duplicates
 *          (the default — preserves the historical "always run" behaviour)
 * Reset  — call clear() first, then execute() — clean-slate per fixture
 */
enum ConflictAction: string
{
    case Skip = 'skip';
    case Merge = 'merge';
    case Reset = 'reset';
}
