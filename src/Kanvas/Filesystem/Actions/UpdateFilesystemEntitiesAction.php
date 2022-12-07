<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\FilesystemEntities\Actions;

use Kanvas\Filesystem\FilesystemEntities\Models\FilesystemEntities;
use Kanvas\Filesystem\FilesystemEntities\DataTransferObject\FilesystemEntitiesPutData;

class UpdateFilesystemEntitiesAction
{
    /**
     * Construct function
     *
     * @param FilesystemEntitiesPutData $data
     */
    public function __construct(
        protected FilesystemEntitiesPutData $data
    ) {
    }

    /**
     * Invoke function
     *
     * @param int $id
     *
     * @return FilesystemEntities
     */
    public function execute(int $id): FilesystemEntities
    {
        $filesystemEntity = FilesystemEntities::findOrFail($id);
        $filesystemEntity->filesystem_id = $this->data->filesystem_id;
        $filesystemEntity->companies_id = $this->data->companies_id;
        $filesystemEntity->system_modules_id = $this->data->system_modules_id;
        $filesystemEntity->entity_id = $this->data->entity_id;
        $filesystemEntity->field_name = $this->data->field_name;
        $filesystemEntity->update();

        return $filesystemEntity;
    }
}
