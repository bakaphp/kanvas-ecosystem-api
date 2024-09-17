<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Model as EloquentModel;
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

        if ($update) {
            $fileEntity = FilesystemEntitiesRepository::getByIdAdnEntity((int) $id, $this->entity);
        } else {
            /**
             * @var FilesystemEntities
             */
            $fileEntity = FilesystemEntities::firstOrCreate([
               'entity_id' => $this->entity->getKey(),
               'system_modules_id' => $systemModule->getKey(),
               'filesystem_id' => $this->filesystem->getKey(),
               //'companies_id' => $this->filesystem->companies_id,
            ], [
               'companies_id' => $this->filesystem->companies_id,
            ]);
        }

        $fileEntity->filesystem_id = $this->filesystem->getKey();
        $fileEntity->field_name = $fieldName;
        $fileEntity->is_deleted = StateEnums::NO->getValue();
        $fileEntity->saveOrFail();

        if ($this->entity->hasWorkflow()) {
            $this->entity->fireWorkflow(WorkflowEnum::ATTACH_FILE->value);
        }

        if (method_exists($this->entity, 'clearLightHouseCache')) {
            $this->entity->clearLightHouseCacheJob();
        }

        return $fileEntity;
    }
}
