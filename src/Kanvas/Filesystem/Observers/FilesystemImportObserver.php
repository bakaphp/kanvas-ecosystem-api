<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Observers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Filesystem\Models\FilesystemImports;

class FilesystemImportObserver implements ShouldQueue
{
    public function created(FilesystemImports $filesystemImport): void
    {
        // Direct payload spools (e.g. JSONL written by WriteImporterArrayToJsonlFileAction)
        // have no mapper because the resolver dispatches the job itself. The
        // mapper-driven auto-dispatch only applies to CSV-mapped imports.
        if (! $filesystemImport->filesystemMapper) {
            return;
        }

        $className = $filesystemImport->filesystemMapper->systemModule->model_name;
        $handler = $className::getImportHandler($filesystemImport);

        $handler->execute();

        if ($filesystemImport->extra['deleteAfterUse']) {
            $filesystemImport->filesystemMapper->softdelete();
        }
    }
}
