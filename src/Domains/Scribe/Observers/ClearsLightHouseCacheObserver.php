<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Observers;

use Kanvas\Scribe\Models\BaseModel;

/**
 * Drops the Lighthouse Redis cache before a document is saved, so a `files`/`custom_fields` read
 * right after the write does not serve the pre-write payload. Shared by every Scribe document that
 * exposes `files` — the rule is the same for all of them.
 */
class ClearsLightHouseCacheObserver
{
    public function updating(BaseModel $document): void
    {
        $document->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
