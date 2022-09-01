<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\FilesystemEntities\Actions;

use Kanvas\Filesystem\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\FilesystemEntities\Models\FilesystemEntities;

class AttachFilesystemAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected Filesystem $filesystem,
        protected int $entityId,
        protected int $systemModulesId,
        protected string $fieldName
    ) {
    }

    /**
     * Invoke function.
     *
     * @return FilesystemEntities
     */
    public function execute() : FilesystemEntities
    {
        $filesystemEntity = new FilesystemEntities();
        $filesystemEntity->filesystem_id = $this->filesystem->getKey();
        $filesystemEntity->companies_id = $this->filesystem->companies_id;
        $filesystemEntity->system_modules_id = $systemModulesId;
        $filesystemEntity->entity_id = $entityId;
        $filesystemEntity->field_name = $fieldName;
        $filesystemEntity->save();

        return $filesystemEntity;
    }
}
