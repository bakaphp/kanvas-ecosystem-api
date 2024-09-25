<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Kanvas\Filesystem\DataTransferObject\FilesystemMapperUpdate;
use Kanvas\Filesystem\Models\FilesystemMapper as ModelsFilesystemMapper;

class UpdateFilesystemMapperAction
{
    public function __construct(
        protected string $id,
        protected FilesystemMapperUpdate $filesystemMapping
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
