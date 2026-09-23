<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Regions\Models\Regions;

trait ProductImportFixtures
{
    private function makeRegion(CompanyInterface $company, Apps $app, UserInterface $user): Regions
    {
        return new CreateRegionAction(
            new Region(
                $company,
                $app,
                $user,
                Currencies::getById(1),
                'Region ' . uniqid(),
                'r-' . uniqid(),
                null,
                1,
            ),
            $user,
        )->execute();
    }

    /**
     * The real CSV → JSONL transform with only the S3 round trip stubbed, returning the records the
     * importer receives. Shared so a test can assert on what a mapper actually produces.
     *
     * @return list<array<string, mixed>>
     */
    private function transformToImporterRows(
        FilesystemImports $import,
        string $csvPath,
        Filesystem $jsonlTarget
    ): array {
        $jsonl = null;
        $storage = $this->createStub(FilesystemServices::class);
        $storage->method('getFileLocalPath')->willReturn($csvPath);
        $storage->method('upload')->willReturnCallback(function ($uploaded) use (&$jsonl, $jsonlTarget) {
            $jsonl = file_get_contents($uploaded->getRealPath());

            return $jsonlTarget;
        });

        new ImportProductFromFilesystemAction(
            $import,
            $storage
        )->execute();

        $this->assertNotNull($jsonl, 'The transform must produce a JSONL payload');

        return array_map(
            fn (string $line) => json_decode($line, true),
            array_values(
                array_filter(
                    explode("\n", (string) $jsonl),
                    fn (string $line) => $line !== ''
                )
            )
        );
    }

    private function findImportedProduct(string $slug, Apps $app, CompanyInterface $company): ?Products
    {
        return Products::where('slug', $slug)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();
    }
}
