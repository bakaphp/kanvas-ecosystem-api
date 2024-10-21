<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Observers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Filesystem\Actions\ImportDataFromFilesystemAction;
use Kanvas\Filesystem\Models\FilesystemImports;

class FilesystemImportObserver // implements ShouldQueue
{
    public function created(FilesystemImports $filesystemImport): void
    {
        (new ImportDataFromFilesystemAction($filesystemImport))->execute();
    }
}
