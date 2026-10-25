<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;

/**
 * Which Kanvas files already sit on the Zoho record, kept on the entity as `[id => id]` so a retry
 * or a later push never uploads the same file twice. A file pulled down from Zoho is remembered
 * the same way so it does not go straight back up.
 */
trait TracksZohoSyncedFiles
{
    /**
     * @return int[]
     */
    protected function syncedFileIds(Model $entity, CustomFieldEnum $field): array
    {
        return array_keys($entity->get($field->value) ?? []);
    }

    /**
     * @param int[] $fileIds
     */
    protected function rememberSyncedFiles(Model $entity, CustomFieldEnum $field, array $fileIds): void
    {
        $synced = $entity->get($field->value) ?? [];

        foreach ($fileIds as $fileId) {
            $synced[$fileId] = $fileId;
        }

        $entity->set($field->value, $synced);
    }
}
