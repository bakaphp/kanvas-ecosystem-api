<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\SystemModules\Models\SystemModules;
use RuntimeException;
use Tests\TestCaseUnit;

class ImportProductFromFilesystemActionTest extends TestCaseUnit
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

    public function testStreamCsvGroupsContiguousVariantsIntoOneProduct(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU', 'Price'],
            ['prod-a', 'Product A', 'A-1', '10.00'],
            ['prod-a', 'Product A', 'A-2', '12.00'],
            ['prod-a', 'Product A', 'A-3', '14.00'],
            ['prod-b', 'Product B', 'B-1', '20.00'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath);

        $products = $this->readProducts($jsonlPath);
        $this->assertCount(2, $products);

        $this->assertSame('Product A', $products[0]['name']);
        $this->assertSame('A-1', $products[0]['sku']);
        $this->assertCount(3, $products[0]['variants']);
        $this->assertSame(['A-1', 'A-2', 'A-3'], array_column($products[0]['variants'], 'sku'));

        $this->assertSame('Product B', $products[1]['name']);
        $this->assertCount(1, $products[1]['variants']);
    }

    public function testStreamCsvEmitsOneProductPerSingleVariantWhenAllHandlersUnique(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU'],
            ['prod-a', 'Product A', 'A-1'],
            ['prod-b', 'Product B', 'B-1'],
            ['prod-c', 'Product C', 'C-1'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath);

        $products = $this->readProducts($jsonlPath);
        $this->assertCount(3, $products);
        $this->assertSame(['Product A', 'Product B', 'Product C'], array_column($products, 'name'));
    }

    public function testStreamCsvThrowsClearErrorOnOutOfOrderHandler(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU'],
            ['prod-a', 'Product A', 'A-1'],
            ['prod-b', 'Product B', 'B-1'],
            ['prod-a', 'Product A', 'A-2'], // <- non-contiguous, was already emitted
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/grouped by handler/i');

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath);
    }

    public function testStreamCsvCleansUpJsonlOnError(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU'],
            ['prod-a', 'Product A', 'A-1'],
            ['prod-b', 'Product B', 'B-1'],
            ['prod-a', 'Product A', 'A-2'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        try {
            $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($jsonlPath, 'Failed transform must not leave a partial JSONL file behind');
        }
    }

    public function testStreamCsvProducesEmptyFileForHeaderOnlyCsv(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath);

        $this->assertFileExists($jsonlPath);
        $this->assertSame('', file_get_contents($jsonlPath));
    }

    public function testStreamCsvAggregatesFromProductAttributesAcrossVariants(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU', 'Color', 'Brand'],
            ['prod-a', 'Product A', 'A-1', 'red', 'Acme'],
            ['prod-a', 'Product A', 'A-2', 'blue', 'Acme'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $action = $this->makeAction([
            'product_name' => 'Name',
            'sku' => 'SKU',
            'handler' => 'Slug',
            'product_slug' => 'Slug',
            'attributes' => [
                // Per the existing mapAttributes() shape: each entry maps a CSV column,
                // and the boolean `fromProduct` flag promotes the attribute to product
                // level. The downstream check is `=== true`, so the boolean must pass
                // through the mapper untouched (non-string values hit the default arm).
                ['Color' => 'Color', 'fromProduct' => false],
                ['Brand' => 'Brand', 'fromProduct' => true],
            ],
        ]);

        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath);

        $products = $this->readProducts($jsonlPath);
        $this->assertCount(1, $products);
        $productAttributeNames = array_column($products[0]['attributes'], 'name');
        $this->assertContains('Brand', $productAttributeNames, 'Brand was marked fromProduct=true and should be promoted to product level');
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $configuration
     */
    private function makeAction(?array $mapping = null, array $configuration = []): ImportProductFromFilesystemAction
    {
        $mapping ??= [
            'product_name' => 'Name',
            'sku' => 'SKU',
            'handler' => 'Slug',
            'product_slug' => 'Slug',
            'price' => 'Price',
        ];

        $systemModule = new SystemModules();
        $systemModule->model_name = Products::class;

        $mapper = new FilesystemMapper([
            'mapping' => $mapping,
            'configuration' => $configuration,
        ]);
        $mapper->setRelation('systemModule', $systemModule);

        $import = new FilesystemImports();
        $import->id = 1;
        $import->uuid = 'test-uuid-' . uniqid();
        $import->setRelation('filesystemMapper', $mapper);

        return new ImportProductFromFilesystemAction($import);
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = sys_get_temp_dir() . '/importer-csv-' . uniqid() . '.csv';
        $this->tempPaths[] = $path;

        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '\\');
        }
        fclose($handle);

        return $path;
    }

    private function makeJsonlPath(): string
    {
        $path = sys_get_temp_dir() . '/importer-jsonl-' . uniqid() . '.jsonl';
        $this->tempPaths[] = $path;

        return $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readProducts(string $jsonlPath): array
    {
        $contents = file_get_contents($jsonlPath);
        $lines = array_values(array_filter(explode("\n", $contents), fn ($l) => $l !== ''));

        return array_map(fn ($line) => json_decode($line, true), $lines);
    }
}
