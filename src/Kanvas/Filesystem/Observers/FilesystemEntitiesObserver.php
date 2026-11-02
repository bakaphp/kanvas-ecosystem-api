<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Observers;

use Kanvas\Filesystem\Models\FilesystemEntities;

/**
 * A detached file row only reaches the entity's `files` `@cacheRedis` payload through the parent,
 * and the parent is not written when a row is soft- or hard-deleted, so nothing else drops its
 * cache. Attaching is already covered by AttachFilesystemAction.
 */
class FilesystemEntitiesObserver
{
    public function saved(FilesystemEntities $fileEntity): void
    {
        if ($fileEntity->wasChanged('is_deleted') && $fileEntity->trashed()) {
            $fileEntity->clearEntityLightHouseCache();
        }
    }

    public function deleted(FilesystemEntities $fileEntity): void
    {
        $fileEntity->clearEntityLightHouseCache();
    }
}
