<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

/**
 * End-to-end test for the CSV-with-mapper import path (PR 3).
 *
 * Validates the full chain that the customer touches when uploading a CSV
 * via the mapper UI:
 *
 *   1. The action streams the source CSV → applies the mapper row-by-row →
 *      emits one fully-formed product per JSONL line.
 *   2. The transformed JSONL is "uploaded" (mocked) and attached to
 *      `FilesystemImports.extra.streamable_filesystem_id`.
 *   3. The job is dispatched with `[]` + the FilesystemImports reference.
 *   4. Each emitted JSONL line, when fed through the same `ProductImporterAction`
 *      the worker uses, creates a real `Products` row in the DB with the
 *      expected variants and pricing — proving the data integrity end-to-end.
 *
 * The test bypasses S3 by injecting a mocked `FilesystemServices` and skips
 * the queue-runtime layer with `Bus::fake()`, but exercises every line of
 * application code in the import pipeline.
 */
final class ImportProductFromFilesystemEndToEndTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testCsvImportProducesCorrectProductsAndVariantsInTheDatabase(): void
    {
        // Faking the queue early intercepts the FilesystemImports observer's
        // ShouldQueue listener (which would otherwise auto-dispatch the
        // real ImportProductFromFilesystemAction synchronously and trigger
        // a real S3 download against our fake Filesystem path).
        Queue::fake();

        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $region = new CreateRegionAction(
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

        $warehouse = new CreateWarehouseAction(
            new Warehouses(
                $company,
                $app,
                $user,
                $region,
                'Warehouse ' . uniqid(),
                'Test Location',
                true,
                true,
            ),
            $user,
        )->execute();

        // Product type owns the attribute schema for everything imported under
        // it. configuration.product_type_id must be set on the mapper or the
        // action throws — so we create a real one and reference its id below.
        $productType = new CreateProductTypeAction(
            new ProductsTypesDto(
                $company,
                $user,
                'E2E Test Type ' . uniqid(),
                'Type used by the e2e import test',
                1,
                true,
            ),
            $user,
        )->execute();

        $mapping = [
            'name' => 'Product Name',
            'description' => 'Description',
            'sku' => 'SKU',
            'slug' => 'Slug',
            'product_name' => 'Product Name',
            'product_slug' => 'Slug',
            'product_description' => 'Description',
            'handler' => 'Slug',
            'regionId' => 'Region ID',
            'price' => 'Price',
            'quantity' => 'Quantity',
            'is_published' => 1,
            'productType' => [
                'name' => 'Product Type',
                'description' => 'Product Type',
                'is_published' => 1,
                'weight' => 1,
            ],
            'customFields' => [],
            'attributes' => [
                ['Color' => 'Color', 'fromProduct' => false],
                ['Brand' => 'Brand', 'fromProduct' => true],
            ],
            'variants' => [
                [
                    'name' => 'Variant Name',
                    'description' => 'Description',
                    'sku' => 'SKU',
                    'slug' => 'SKU',
                    'price' => 'Price',
                    'is_published' => 1,
                    'warehouse' => [
                        [
                            'id' => 'Warehouse ID',
                            'price' => 'Price',
                            'quantity' => 'Quantity',
                            'sku' => 'SKU',
                            'is_new' => true,
                        ],
                    ],
                ],
            ],
            'categories' => [],
        ];

        $mapperName = 'E2E Test Mapper ' . uniqid();
        $filesystemMapper = new CreateFilesystemMapperAction(
            new FilesystemMapper(
                app: $app,
                branch: $user->getCurrentBranch(),
                user: $user,
                systemModule: SystemModulesRepository::getByModelName(Products::class),
                name: $mapperName,
                header: [],
                mapping: $mapping,
                configuration: ['product_type_id' => $productType->getId()],
            ),
        )->execute();

        $productSlugA = 'e2e-prod-a-' . uniqid();
        $productSlugB = 'e2e-prod-b-' . uniqid();
        $skuA1 = 'E2E-A-1-' . uniqid();
        $skuA2 = 'E2E-A-2-' . uniqid();
        $skuB1 = 'E2E-B-1-' . uniqid();

        $csvPath = $this->writeCsv([
            ['Slug', 'Product Name', 'Description', 'SKU', 'Region ID', 'Price', 'Quantity', 'Product Type', 'Warehouse ID', 'Color', 'Brand'],
            [$productSlugA, 'Product Alpha', 'Alpha description', $skuA1, (string) $region->getId(), '10.00', '5', 'Test Type', (string) $warehouse->getId(), 'red', 'Acme'],
            [$productSlugA, 'Product Alpha', 'Alpha description', $skuA2, (string) $region->getId(), '12.00', '3', 'Test Type', (string) $warehouse->getId(), 'blue', 'Acme'],
            [$productSlugB, 'Product Beta', 'Beta description', $skuB1, (string) $region->getId(), '20.00', '7', 'Test Type', (string) $warehouse->getId(), 'green', 'Globex'],
        ]);

        $sourceFilesystem = new Filesystem([
            'users_id' => $user->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => basename($csvPath),
            'path' => '/test/source/' . basename($csvPath),
            'url' => 'https://example.test/source.csv',
            'size' => (string) filesize($csvPath),
            'file_type' => 'csv',
        ]);
        $sourceFilesystem->save();

        $jsonlFilesystem = new Filesystem([
            'users_id' => $user->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'transformed-' . uniqid() . '.jsonl',
            'path' => '/test/jsonl/file.jsonl',
            'url' => 'https://example.test/transformed.jsonl',
            'size' => '0',
            'file_type' => 'jsonl',
        ]);
        $jsonlFilesystem->save();

        $filesystemImport = new FilesystemImports();
        $filesystemImport->apps_id = $app->getId();
        $filesystemImport->users_id = $user->getId();
        $filesystemImport->companies_id = $company->getId();
        $filesystemImport->companies_branches_id = $user->getCurrentBranch()->getId();
        $filesystemImport->regions_id = $region->getId();
        $filesystemImport->filesystem_id = $sourceFilesystem->getId();
        $filesystemImport->filesystem_mapper_id = $filesystemMapper->getId();
        $filesystemImport->status = 'pending';
        $filesystemImport->is_deleted = 0;
        $filesystemImport->saveOrFail();

        $capturedJsonlContent = null;
        $stubService = $this->createStub(FilesystemServices::class);
        $stubService->method('getFileLocalPath')->willReturn($csvPath);
        $stubService
            ->method('upload')
            ->willReturnCallback(function ($uploadedFile) use (&$capturedJsonlContent, $jsonlFilesystem) {
                $capturedJsonlContent = file_get_contents($uploadedFile->getRealPath());

                return $jsonlFilesystem;
            });

        new ImportProductFromFilesystemAction($filesystemImport, $stubService)->execute();

        $filesystemImport->refresh();
        $this->assertSame(
            $jsonlFilesystem->getId(),
            $filesystemImport->extra['streamable_filesystem_id'],
            'Action must attach the transformed JSONL filesystem id under extra so the worker streams it',
        );

        Queue::assertPushed(
            ProductImporterJob::class,
            function (ProductImporterJob $job) use ($filesystemImport): bool {
                return $job->importer === []
                    && $job->filesystemImport?->getId() === $filesystemImport->getId();
            },
        );

        $this->assertNotNull($capturedJsonlContent, 'Action must produce a JSONL payload before dispatching');
        $jsonlLines = array_values(array_filter(
            explode("\n", $capturedJsonlContent),
            fn ($line) => $line !== '',
        ));
        $this->assertCount(2, $jsonlLines, 'Two CSV rows of product A grouped + one of product B = 2 JSONL products');

        $productARow = json_decode($jsonlLines[0], true);
        $productBRow = json_decode($jsonlLines[1], true);

        $this->assertSame('Product Alpha', $productARow['name']);
        $this->assertSame($productSlugA, $productARow['slug']);
        $this->assertCount(2, $productARow['variants'], 'Product A must have 2 grouped variants');
        $this->assertSame([$skuA1, $skuA2], array_column($productARow['variants'], 'sku'));

        $this->assertSame('Product Beta', $productBRow['name']);
        $this->assertCount(1, $productBRow['variants']);

        // Now exercise the per-row processing the worker would do — feed each
        // JSONL line through ProductImporterAction (the same call the worker
        // makes inside its iteration loop) and verify the resulting Products
        // and Variants land in the database with the right shape.
        foreach ($jsonlLines as $line) {
            $payload = json_decode($line, true);
            new ProductImporterAction(
                ProductImporter::from($payload),
                $company,
                $user,
                $region,
                $app,
            )->execute();
        }

        $productA = Products::where('slug', $productSlugA)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();
        $this->assertNotNull($productA, 'Product A should exist after worker processing');
        $this->assertSame('Product Alpha', $productA->name);

        $variantsA = $productA->variants()->get();
        $this->assertCount(2, $variantsA, 'Product A should have its two grouped variants persisted');
        $variantSkus = $variantsA->pluck('sku')->all();
        $this->assertEqualsCanonicalizing([$skuA1, $skuA2], $variantSkus);

        $productB = Products::where('slug', $productSlugB)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();
        $this->assertNotNull($productB);
        $this->assertSame('Product Beta', $productB->name);
        $this->assertCount(1, $productB->variants()->get());

        // Attributes: Brand was flagged fromProduct=true so it should land on
        // the product itself; Color was variant-level so it should appear on
        // each variant with the per-row value from the CSV.
        $productAAttributes = $productA->attributes()->get()->mapWithKeys(
            fn ($attr) => [$attr->attribute->name => $attr->value],
        );
        $this->assertArrayHasKey('Brand', $productAAttributes->all(), 'Brand should be promoted to Product Alpha attributes');
        $this->assertSame('Acme', $productAAttributes['Brand']);

        $productBAttributes = $productB->attributes()->get()->mapWithKeys(
            fn ($attr) => [$attr->attribute->name => $attr->value],
        );
        $this->assertSame('Globex', $productBAttributes['Brand'] ?? null, 'Brand on Product Beta should match its CSV value');

        // Per-variant Color values: walk each variant and pull its Color attribute.
        $colorByVariantSku = [];
        foreach ($productA->variants()->get() as $variant) {
            $variantAttrs = $variant->attributes()->get()->mapWithKeys(
                fn ($attr) => [$attr->attribute->name => $attr->value],
            );
            if (isset($variantAttrs['Color'])) {
                $colorByVariantSku[$variant->sku] = $variantAttrs['Color'];
            }
        }
        $this->assertSame('red', $colorByVariantSku[$skuA1] ?? null, 'Variant A-1 should have Color=red from its CSV row');
        $this->assertSame('blue', $colorByVariantSku[$skuA2] ?? null, 'Variant A-2 should have Color=blue from its CSV row');

        // Product type association — the whole reason product_type_id is
        // mandatory. Without this link, attributes are orphan key/value pairs
        // not tied to any type's schema.
        $this->assertSame(
            $productType->getId(),
            $productA->products_types_id,
            'Imported product must be linked to the configured product type',
        );
        $this->assertSame($productType->getId(), $productB->products_types_id);
    }

    public function testActionFailsFastWhenProductTypeIdIsMissingFromMapperConfiguration(): void
    {
        Queue::fake();

        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $region = new CreateRegionAction(
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

        $filesystemMapper = new CreateFilesystemMapperAction(
            new FilesystemMapper(
                app: $app,
                branch: $user->getCurrentBranch(),
                user: $user,
                systemModule: SystemModulesRepository::getByModelName(Products::class),
                name: 'No Type Mapper ' . uniqid(),
                header: [],
                mapping: [
                    'product_name' => 'Product Name',
                    'sku' => 'SKU',
                    'handler' => 'Slug',
                    'product_slug' => 'Slug',
                ],
                // intentionally no product_type_id in configuration
                configuration: [],
            ),
        )->execute();

        $csvPath = $this->writeCsv([
            ['Slug', 'Product Name', 'SKU'],
            ['slug-' . uniqid(), 'A Product', 'SKU-' . uniqid()],
        ]);

        $sourceFilesystem = new Filesystem([
            'users_id' => $user->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => basename($csvPath),
            'path' => '/test/no-type/' . basename($csvPath),
            'url' => 'https://example.test/no-type.csv',
            'size' => (string) filesize($csvPath),
            'file_type' => 'csv',
        ]);
        $sourceFilesystem->save();

        $filesystemImport = new FilesystemImports();
        $filesystemImport->apps_id = $app->getId();
        $filesystemImport->users_id = $user->getId();
        $filesystemImport->companies_id = $company->getId();
        $filesystemImport->companies_branches_id = $user->getCurrentBranch()->getId();
        $filesystemImport->regions_id = $region->getId();
        $filesystemImport->filesystem_id = $sourceFilesystem->getId();
        $filesystemImport->filesystem_mapper_id = $filesystemMapper->getId();
        $filesystemImport->status = 'pending';
        $filesystemImport->is_deleted = 0;
        $filesystemImport->saveOrFail();

        $stubService = $this->createStub(FilesystemServices::class);
        $stubService->method('getFileLocalPath')->willReturn($csvPath);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/product_type_id/');

        new ImportProductFromFilesystemAction($filesystemImport, $stubService)->execute();
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = sys_get_temp_dir() . '/e2e-importer-' . uniqid() . '.csv';
        $this->tempPaths[] = $path;

        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '\\');
        }
        fclose($handle);

        return $path;
    }
}
