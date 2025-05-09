<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Kanvas\Filesystem\DataTransferObject\FilesystemMapperUpdate;
use Kanvas\Filesystem\Models\FilesystemMapper as ModelsFilesystemMapper;

class UpdateFilesystemMapperAction
{
    public function __construct(
        protected ModelsFilesystemMapper $filesystemMapper,
        protected FilesystemMapperUpdate $filesystemMapping
    ) {
    }

    public function execute(): ModelsFilesystemMapper
    {
        $this->filesystemMapper->update([
            'name' => $this->filesystemMapping->name,
            'description' => $this->filesystemMapping->description,
            'file_header' => $this->filesystemMapping->header,
            'mapping' => $this->filesystemMapping->mapping,
            'configuration' => $this->filesystemMapping->configuration,
            'is_default' => $this->filesystemMapping->is_default,
        ]);
        $this->filesystemMapper->refresh();

        return $this->filesystemMapper;
    }
}
