<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Observers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Throwable;

class FilesystemImportObserver implements ShouldQueue
{
    public function created(FilesystemImports $filesystemImport): void
    {
        if ($filesystemImport->filesystem_mapper_id === null && isset($filesystemImport->extra['inline_product_import'])) {
            try {
                app(ImportProductFromFilesystemAction::class, ['filesystemImports' => $filesystemImport])->execute();
            } catch (Throwable $exception) {
                $filesystemImport->update([
                    'status' => 'failed',
                    'exception' => ['message' => $exception->getMessage()],
                    'finished_at' => now(),
                ]);

                throw $exception;
            }

            return;
        }

        // Direct payload spools (e.g. JSONL written by WriteImporterArrayToJsonlFileAction)
        // have no mapper because the resolver dispatches the job itself. The
        // mapper-driven auto-dispatch only applies to CSV-mapped imports.
        if (! $filesystemImport->filesystemMapper) {
            return;
        }

        $className = $filesystemImport->filesystemMapper->systemModule->model_name;
        $handler = $className::getImportHandler($filesystemImport);

        $handler->execute();

        if ($filesystemImport->extra['deleteAfterUse'] ?? false) {
            $filesystemImport->filesystemMapper->softdelete();
        }
    }
}
