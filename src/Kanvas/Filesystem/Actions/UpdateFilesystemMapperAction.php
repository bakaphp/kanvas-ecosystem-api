<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\FilesystemMapper as ModelsFilesystemMapper;

class UpdateFilesystemMapperAction
{
    public function __construct(
        protected string $id,
        protected FilesystemMapper $filesystemMapping
    ) {
    }

    public function execute(): ModelsFilesystemMapper
    {
        return ModelsFilesystemMapper::updateOrCreate(
            [
                'apps_id' => $this->filesystemMapping->app->getId(),
                'companies_branches_id' => $this->filesystemMapping->branch->getId(),
                'companies_id' => $this->filesystemMapping->branch->company->getId(),
                'users_id' => $this->filesystemMapping->user->getId(),
                'system_modules_id' => $this->filesystemMapping->systemModule->getId(),
                'id' => $this->id,
            ],
            [
                'name' => $this->filesystemMapping->name,
                'file_header' => $this->filesystemMapping->header,
                'mapping' => $this->filesystemMapping->mapping,
                'configuration' => $this->filesystemMapping->configuration,
            ]
        );
    }
}
