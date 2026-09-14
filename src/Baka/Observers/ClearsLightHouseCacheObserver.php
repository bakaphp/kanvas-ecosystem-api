<?php

declare(strict_types=1);

namespace Baka\Observers;

use Illuminate\Database\Eloquent\Model;

/**
 * Drops the Lighthouse Redis cache once a record is written, so the next `files`/`custom_fields`
 * read cannot serve the pre-write payload.
 *
 * Hooks `saved`, not `updating`: clearing before the write leaves a window where a concurrent read
 * re-warms the cache from the uncommitted row and the stale entry then survives until the next
 * write. `saved` also covers inserts — a no-op for a brand-new id, which has no cache key yet, but
 * it keeps one hook instead of two.
 *
 * Attach it with `#[ObservedBy]` on any model that uses HasLightHouseCache and needs nothing more
 * than that on write — attaching it IS the declaration that the model has the trait, which is why
 * the parameter is a bare Model rather than any one domain's base class. A model that also needs
 * its own lifecycle work keeps its own observer and calls clearLightHouseCache() from there.
 */
class ClearsLightHouseCacheObserver
{
    public function saved(Model $record): void
    {
        $record->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
