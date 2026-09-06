<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Observers;

use Illuminate\Database\Eloquent\Model;

/**
 * Drops the Lighthouse Redis cache before a record is saved, so a `files`/`custom_fields` read
 * right after the write does not serve the pre-write payload. Shared by every Scribe record that
 * exposes `files` — the rule is the same for all of them.
 *
 * Typed on Model, not the Scribe BaseModel — FiscalPeriod is not one. Attaching this observer is
 * the declaration that the model uses HasLightHouseCache.
 */
class ClearsLightHouseCacheObserver
{
    public function updating(Model $record): void
    {
        $record->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
