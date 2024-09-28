<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

class AttachFilesystemAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Filesystem $filesystem,
        protected EloquentModel $entity
    ) {
    }

    /**
     * Attached a filesystem to a Eloquent model.
     */
    public function execute(string $fieldName, ?int $id = null): FilesystemEntities
    {
        $systemModule = SystemModulesRepository::getByModelName($this->entity::class, $this->filesystem->app);
        $update = (int) $id > 0;
        $allowDuplicateFiles = $this->filesystem->app->get(AppSettingsEnums::FILESYSTEM_ALLOW_DUPLICATE_FILES_BY_NAME->getValue());
        $runUpdate = false;

        if ($update) {
            $fileEntity = FilesystemEntitiesRepository::getByIdAdnEntity((int) $id, $this->entity);
        } else {
            /**
             * @todo improve this code, doesn't look good but works
             */
            $fileEntity = FilesystemEntities::where([
                'entity_id' => $this->entity->getKey(),
                'system_modules_id' => $systemModule->getKey(),
                'filesystem_id' => $this->filesystem->getKey(),
                'companies_id' => $this->filesystem->companies_id,
                'is_deleted' => StateEnums::NO->getValue(),
            ])->first();

            if (! $fileEntity) {
                $filter = [
                    'entity_id' => $this->entity->getKey(),
                    'system_modules_id' => $systemModule->getKey(),
                    //'filesystem_id' => $this->filesystem->getKey(),
                    //'companies_id' => $this->filesystem->companies_id,
                ];
                if (! $allowDuplicateFiles) {
                    $filter['field_name'] = $fieldName;
                } else {
                    $filter['filesystem_id'] = $this->filesystem->getKey();
                }
                $fileEntity = FilesystemEntities::firstOrCreate($filter, [
                   'companies_id' => $this->filesystem->companies_id,
                ]);
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

        if ($runUpdate) {
            $fileEntity->is_deleted = StateEnums::NO->getValue();
            $fileEntity->saveOrFail();
        }

        if ($this->entity->hasWorkflow()) {
            $this->entity->fireWorkflow(WorkflowEnum::ATTACH_FILE->value);
        }

        if (method_exists($this->entity, 'clearLightHouseCache')) {
            $this->entity->clearLightHouseCacheJob();
        }

        return $fileEntity;
    }
}
