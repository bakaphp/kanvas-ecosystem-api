<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Contracts;

use Kanvas\Filesystem\Models\FilesystemImports;

interface EntityImportFilesystemInterface
{
    public function getId(): mixed;

    public static function getImportHandler(FilesystemImports $filesystemImport);
}
