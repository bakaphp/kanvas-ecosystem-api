<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Filesystem\Observers\FilesystemImportObserver;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCaseUnit;

class FilesystemImportObserverTest extends TestCaseUnit
{
    public function testRunsTheHandlerWhenExtraHasNoDeleteAfterUseKey(): void
    {
        RecordingImportHandlerModel::$executed = false;

        $systemModule = new SystemModules();
        $systemModule->model_name = RecordingImportHandlerModel::class;

        $mapper = new FilesystemMapper(['mapping' => []]);
        $mapper->setRelation('systemModule', $systemModule);

        $import = new FilesystemImports();
        $import->extra = ['channels_id' => 9];
        $import->setRelation('filesystemMapper', $mapper);

        new FilesystemImportObserver()->created($import);

        $this->assertTrue(RecordingImportHandlerModel::$executed);
    }
}

final class RecordingImportHandlerModel
{
    public static bool $executed = false;

    public static function getImportHandler(FilesystemImports $filesystemImport): object
    {
        return new class () {
            public function execute(): void
            {
                RecordingImportHandlerModel::$executed = true;
            }
        };
    }
}
