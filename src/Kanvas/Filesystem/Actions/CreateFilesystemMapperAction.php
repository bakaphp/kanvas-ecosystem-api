<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Exception;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\FilesystemMapper as ModelsFilesystemMapper;

class CreateFilesystemMapperAction
{
    public function __construct(
        protected FilesystemMapper $filesystemMapping
    ) {
    }

    public function execute(): ModelsFilesystemMapper
    {
        $mapperKeys = array_keys($this->filesystemMapping->mapping);
        $requiredFields = $this->filesystemMapping->systemModule->browse_fields ?? [];
        $arrayDiff = array_diff($requiredFields, $mapperKeys);
        if ($requiredFields && $arrayDiff) {
            throw new Exception('Missing fields in mapping, fields required: ' . implode(', ', $arrayDiff));
        }

        return ModelsFilesystemMapper::firstOrCreate([
            'apps_id' => $this->filesystemMapping->app->getId(),
            'companies_branches_id' => $this->filesystemMapping->branch->getId(),
            'companies_id' => $this->filesystemMapping->branch->company->getId(),
            'users_id' => $this->filesystemMapping->user->getId(),
            'system_modules_id' => $this->filesystemMapping->systemModule->getId(),
            'name' => $this->filesystemMapping->name,
        ], [
            'file_header' => $this->filesystemMapping->header,
            'mapping' => $this->filesystemMapping->mapping,
            'description' => $this->filesystemMapping->description,
            'configuration' => $this->filesystemMapping->configuration,
            'is_default' => $this->filesystemMapping->is_default,
        ]);
    }
}
