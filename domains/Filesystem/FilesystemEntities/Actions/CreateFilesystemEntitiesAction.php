<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\FilesystemEntities\Actions;

use Kanvas\Filesystem\FilesystemEntities\DataTransferObject\FilesystemEntitiesPostData;
use Kanvas\Filesystem\FilesystemEntities\Models\FilesystemEntities;

class CreateFilesystemEntitiesAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected FilesystemEntitiesPostData $data
    ) {
    }

    /**
     * Invoke function.
     *
     * @param FilesystemEntitiesPostData $data
     *
     * @return FilesystemEntities
     */
    public function execute() : FilesystemEntities
    {
        $filesystemEntity = new FilesystemEntities();
        $filesystemEntity->filesystem_id = $this->data->filesystem_id;
        $filesystemEntity->companies_id = $this->data->companies_id;
        $filesystemEntity->system_modules_id = $this->data->system_modules_id;
        $filesystemEntity->entity_id = $this->data->entity_id;
        $filesystemEntity->field_name = $this->data->field_name;
        $filesystemEntity->save();

        return $filesystemEntity;
    }
}
