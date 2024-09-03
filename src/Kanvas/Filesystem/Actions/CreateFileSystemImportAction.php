<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Kanvas\Filesystem\DataTransferObject\FilesystemImport as FilesystemImportDto;
use Kanvas\Filesystem\Models\FilesystemImports;

class CreateFileSystemImportAction
{
    public function __construct(
        public FilesystemImportDto $filesystemImportDto
    ) {
    }

    public function execute(): FilesystemImports
    {
        return FilesystemImports::create([
            'apps_id' => $this->filesystemImportDto->app->getId(),
            'companies_id' => $this->filesystemImportDto->companies->getId(),
            'companies_branches_id' => $this->filesystemImportDto->companiesBranches->getId(),
            'users_id' => $this->filesystemImportDto->users->getId(),
            'regions_id' => $this->filesystemImportDto->regions->getId(),
            'filesystem_id' => $this->filesystemImportDto->filesystem->getId(),
            'filesystem_mapper_id' => $this->filesystemImportDto->filesystemMapper->getId(),
        ]);
    }
}
