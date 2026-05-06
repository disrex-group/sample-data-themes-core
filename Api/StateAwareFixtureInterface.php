<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Api;

/**
 * Optional companion to FixtureInterface for fixtures that can answer
 * "what does the current install look like for the entities I manage?"
 * and "remove everything I would create on a re-run".
 *
 * Implementations let the deploy command:
 *   - Show the operator a "before / after" status table
 *   - Offer skip / clear-and-rerun / merge options per fixture when
 *     existing data is detected (the alternative is a blunt "delete
 *     everything in this prefix and start over")
 *   - Compute a meaningful `--dry-run` diff
 *
 * Fixtures that can't easily answer these (e.g. shared global attributes,
 * categories that are entangled with other modules) should NOT implement
 * this interface — the runner falls back to "execute once, idempotent
 * by design" semantics.
 *
 * @api
 */
interface StateAwareFixtureInterface
{
    /**
     * Number of entities the current install holds that this fixture
     * owns. Returning 0 means the fixture would be doing greenfield
     * work; >0 means a re-run will overlap.
     */
    public function count(): int;

    /**
     * Remove every entity this fixture would create. Stronger than
     * rollback() — rollback() removes only what THIS theme produced;
     * clear() wipes the whole namespace this fixture manages, regardless
     * of which theme imported it. Used by the "clear-and-rerun" path.
     *
     * Returns the number of rows removed.
     */
    public function clear(): int;
}
