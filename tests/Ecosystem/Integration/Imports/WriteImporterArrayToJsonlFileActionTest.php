<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Imports\Actions\WriteImporterArrayToJsonlFileAction;
use Kanvas\Inventory\Regions\Models\Regions as InventoryRegions;
use Kanvas\Regions\Models\Regions as BaseRegions;
use Tests\TestCase;

final class WriteImporterArrayToJsonlFileActionTest extends TestCase
{
    public function testExecuteUploadsJsonlAndCreatesFilesystemImportWithoutMapper(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $branch = $user->getCurrentBranch();
        /** @var BaseRegions $region */
        $region = InventoryRegions::getDefault($company);

        $importerPayload = [
            ['name' => 'Product Alpha', 'sku' => 'SPOOL-A'],
            ['name' => 'Product Beta', 'sku' => 'SPOOL-B'],
        ];

        // Pre-create a real Filesystem row that the mock will return — keeps
        // the FK on filesystem_imports.filesystem_id satisfied without making
        // a real S3 upload during the test.
        $fakeFilesystem = new Filesystem([
            'users_id' => $user->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'importer-test-' . uniqid() . '.jsonl',
            'path' => '/test/spool/' . uniqid() . '.jsonl',
            'url' => 'https://example.test/spool.jsonl',
            'size' => '128',
            'file_type' => 'jsonl',
        ]);
        $fakeFilesystem->save();

        $mockService = $this->createMock(FilesystemServices::class);
        $capturedTempPath = null;
        $mockService
            ->expects($this->once())
            ->method('upload')
            ->willReturnCallback(function ($uploadedFile) use (&$capturedTempPath, $fakeFilesystem) {
                $capturedTempPath = $uploadedFile->getRealPath();
                $this->assertSame('jsonl', $uploadedFile->getClientOriginalExtension());

                // Assert the spooled file actually contains JSONL content with one line per row.
                $contents = file_get_contents($capturedTempPath);
                $lines = array_values(array_filter(explode("\n", $contents), fn ($l) => $l !== ''));
                $this->assertCount(2, $lines);
                $this->assertSame(['name' => 'Product Alpha', 'sku' => 'SPOOL-A'], json_decode($lines[0], true));
                $this->assertSame(['name' => 'Product Beta', 'sku' => 'SPOOL-B'], json_decode($lines[1], true));

                return $fakeFilesystem;
            });

        $import = new WriteImporterArrayToJsonlFileAction(
            $importerPayload,
            $app,
            $company,
            $user,
            $branch,
            $region,
            $mockService,
        )->execute();

        $this->assertInstanceOf(FilesystemImports::class, $import);
        $this->assertTrue($import->exists, 'FilesystemImports row must be persisted');
        $this->assertNull($import->filesystem_mapper_id, 'Direct JSONL spools must have null mapper');
        $this->assertSame($fakeFilesystem->getId(), $import->filesystem_id);
        $this->assertSame($app->getId(), $import->apps_id);
        $this->assertSame($company->getId(), $import->companies_id);
        $this->assertSame($user->getId(), $import->users_id);
        $this->assertSame('pending', $import->status);
        $this->assertFalse((bool) $import->is_deleted);
        $this->assertNotEmpty($import->uuid);

        // The temp file passed to the mock service should be cleaned up by execute()
        // after the (mocked) upload completes.
        $this->assertNotNull($capturedTempPath);
        $this->assertFileDoesNotExist($capturedTempPath, 'Temp JSONL file must be deleted after upload');
    }
}
