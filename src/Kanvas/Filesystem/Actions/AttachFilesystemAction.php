<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\DB;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

class AttachFilesystemAction
{
    public function __construct(
        protected Filesystem $filesystem,
        protected EloquentModel $entity
    ) {
    }

    public function execute(string $fieldName, ?int $id = null): FilesystemEntities
    {
        return DB::connection('ecosystem')->transaction(function () use ($fieldName, $id) {
            $systemModule = SystemModulesRepository::getByModelName($this->entity::class, $this->filesystem->app);
            $update = (int) $id > 0;
            $allowDuplicateFiles = $this->filesystem->app->get(AppSettingsEnums::FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME->getValue());
            $runUpdate = false;

            if ($update) {
                $fileEntity = FilesystemEntitiesRepository::getByIdAdnEntity((int) $id, $this->entity);
            } else {
                // Lock the rows we're going to check to prevent race conditions
                $fileEntity = FilesystemEntities::where([
                    'entity_id' => $this->entity->getKey(),
                    'system_modules_id' => $systemModule->getKey(),
                    'filesystem_id' => $this->filesystem->getKey(),
                    'companies_id' => $this->filesystem->companies_id,
                ])->lockForUpdate()->first();

                if (! $fileEntity) {
                    $filter = [
                        'entity_id' => $this->entity->getKey(),
                        'system_modules_id' => $systemModule->getKey(),
                    ];

                    if (! $allowDuplicateFiles) {
                        $filter['field_name'] = $fieldName;
                    } else {
                        $filter['filesystem_id'] = $this->filesystem->getKey();
                    }

                    // Use firstOrCreate with lockForUpdate to prevent race condition
                    $fileEntity = FilesystemEntities::where($filter)
                        ->lockForUpdate()
                        ->first();

                    if (! $fileEntity) {
                        $filter['companies_id'] = $this->filesystem->companies_id;
                        $fileEntity = FilesystemEntities::create($filter);
                    }
                }
            }

            if ($fileEntity->filesystem_id != $this->filesystem->getKey()) {
                $fileEntity->filesystem_id = $this->filesystem->getKey();
                $runUpdate = true;
            }

            if ($fileEntity->field_name != $fieldName) {
                $fileEntity->field_name = $fieldName;
                $runUpdate = true;
            }

            if ($fileEntity->is_deleted == StateEnums::YES->getValue()) {
                $fileEntity->is_deleted = StateEnums::NO->getValue();
                $runUpdate = true;
            }

            if ($runUpdate) {
                $fileEntity->saveOrFail();
                if (method_exists($fileEntity, 'flushCache')) {
                    $fileEntity->flushCache();
                }
            }

            // Fire events after successful database operations
            if ($this->entity->hasWorkflow()) {
                $this->entity->fireWorkflow(WorkflowEnum::ATTACH_FILE->value);
            }

            if (method_exists($this->entity, 'clearLightHouseCache')) {
                $this->entity->clearLightHouseCacheJob();
            }

            if (method_exists($this->entity, 'searchable')) {
                $this->entity->searchable();
            }

            return $fileEntity;
        });
    }
}
