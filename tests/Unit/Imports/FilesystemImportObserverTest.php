<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Filesystem\Observers\FilesystemImportObserver;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\SystemModules\Models\SystemModules;
use RuntimeException;
use Tests\TestCaseUnit;

class FilesystemImportObserverTest extends TestCaseUnit
{
    public function testDispatchesInlineImportWithoutMapper(): void
    {
        $import = new FilesystemImports();
        $import->extra = ['inline_product_import' => ['mapping' => []]];
        $action = $this->createMock(ImportProductFromFilesystemAction::class);
        $action->expects($this->once())->method('execute');
        $this->app->bind(ImportProductFromFilesystemAction::class, fn () => $action);

        new FilesystemImportObserver()->created($import);
    }

    public function testRecordsInlineTransformationFailure(): void
    {
        $import = $this->getMockBuilder(FilesystemImports::class)->onlyMethods(['update'])->getMock();
        $import->extra = ['inline_product_import' => ['mapping' => []]];
        $import->expects($this->once())->method('update')->with($this->callback(
            fn (array $values) => $values['status'] === 'failed'
                && $values['exception']['message'] === 'Invalid CSV'
                && isset($values['finished_at'])
        ))->willReturn(true);
        $action = $this->createMock(ImportProductFromFilesystemAction::class);
        $action->expects($this->once())->method('execute')->willThrowException(new RuntimeException('Invalid CSV'));
        $this->app->bind(ImportProductFromFilesystemAction::class, fn () => $action);
        $this->expectException(RuntimeException::class);

        new FilesystemImportObserver()->created($import);
    }

    public function testRunsTheHandlerWhenExtraHasNoDeleteAfterUseKey(): void
    {
        RecordingImportHandlerModel::$executed = false;

        $systemModule = new SystemModules();
        $systemModule->model_name = RecordingImportHandlerModel::class;

        $mapper = new FilesystemMapper(['mapping' => []]);
        $mapper->setRelation('systemModule', $systemModule);

        $import = new FilesystemImports();
        $import->filesystem_mapper_id = 123;
        $import->extra = ['channels_id' => 9, 'inline_product_import' => ['mapping' => []]];
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
