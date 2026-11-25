<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * A filesystem row without an upload: FilesystemServices::upload() needs S3 credentials, and these
 * tests only care about what is done with the row.
 */
trait CreatesFilesystemRecords
{
    /**
     * @param int $companyOffset added to the current company id to make the file another company's
     */
    protected function createFilesystemRecord(string $name, int $companyOffset = 0): Filesystem
    {
        $file = new Filesystem();
        $file->users_id = auth()->user()->getId();
        $file->apps_id = app(Apps::class)->getId();
        $file->companies_id = auth()->user()->getCurrentCompany()->getId() + $companyOffset;
        $file->name = $name;
        $file->path = 'tests/' . uniqid('', true) . '/' . $name;
        $file->url = 'https://example.test/' . $name;
        $file->size = '1';
        $file->file_type = pathinfo($name, PATHINFO_EXTENSION);
        $file->saveOrFail();

        return $file;
    }

    /**
     * @return array<int, string> the field_name of every file row on the lead, deleted ones included
     */
    protected function attachedFieldNames(Lead $lead): array
    {
        $module = SystemModulesRepository::getByModelName(Lead::class, $lead->app);

        return FilesystemEntities::query()
            ->where('entity_id', $lead->getKey())
            ->where('system_modules_id', $module->getKey())
            ->orderBy('id')
            ->pluck('field_name')
            ->all();
    }
}
