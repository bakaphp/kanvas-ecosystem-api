<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Zoho\Actions\PushFilesToZohoRecordAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Deals\Models\Deal;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * Which Kanvas files already sit on the Zoho record, kept on the entity as `[id => id]` so a retry
 * or a later push never uploads the same file twice. A file pulled down from Zoho is remembered
 * the same way so it does not go straight back up.
 */
trait TracksZohoSyncedFiles
{
    protected function filePusher(ZohoRecordsModule $module, string $zohoRecordId): PushFilesToZohoRecordAction
    {
        return new PushFilesToZohoRecordAction($module, $zohoRecordId);
    }

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

    /**
     * A file that was uploaded to the Zoho deal on behalf of its lead is attached to the Kanvas deal
     * too: the same row, no second copy, under the name Zoho got. Remembered on the deal first so the
     * attach workflow cannot push it back up, and named so the next pull does not download it back.
     *
     * @param int[] $uploadedIds
     * @param array<int, string> $zohoFileNames keyed by filesystem id; defaults to the file's own name
     */
    protected function mirrorUploadedFilesOnDeal(Deal $deal, array $uploadedIds, array $zohoFileNames = []): void
    {
        if ($uploadedIds === []) {
            return;
        }

        $this->rememberSyncedFiles($deal, CustomFieldEnum::ZOHO_DEAL_SYNC_FILES, $uploadedIds);

        $alreadyOnDeal = $deal->files()->pluck('filesystem.id')->map(intval(...))->all();

        foreach (Filesystem::query()->whereKey($uploadedIds)->whereKeyNot($alreadyOnDeal)->get() as $file) {
            $deal->addFile($file, $zohoFileNames[$file->getId()] ?? $file->name);
        }
    }
}
