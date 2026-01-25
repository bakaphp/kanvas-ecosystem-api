<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\Observers;

use Kanvas\Social\Tags\Models\Tag;

class TagsObserver
{
    public function updated(Tag $tag): void
    {
        $tag->clearLightHouseCacheJob();
    }
}
