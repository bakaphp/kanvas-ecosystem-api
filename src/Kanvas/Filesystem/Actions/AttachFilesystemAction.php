<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

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
     * Attached a filesystem to a Eloquent model
     *
     * @param string $fieldName
     * @param integer|null $id
     * @return FilesystemEntities
     */
    public function execute(string $fieldName, ?int $id = null) : FilesystemEntities
    {
        $systemModule = SystemModulesRepository::getByModelName($this->entity::class);
        $update = (int) $id > 0;

        if ($update) {
            $fileEntity = FilesystemEntitiesRepository::getByIdAdnEntity($id, $this->entity);
        } else {
            $fileEntity = new FilesystemEntities();
            $fileEntity->system_modules_id = $systemModule->getKey();
            $fileEntity->companies_id = $this->filesystem->companies_id;
            $fileEntity->entity_id = $this->entity->getKey();
        }

        $fileEntity->filesystem_id = $this->filesystem->getKey();
        $fileEntity->field_name = $fieldName;
        $fileEntity->saveOrFail();

        return $fileEntity;
    }
}
