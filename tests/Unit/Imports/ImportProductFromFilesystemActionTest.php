<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
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

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

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

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

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

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());
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
            $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());
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

        $this->makeAction()->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

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

        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        $products = $this->readProducts($jsonlPath);
        $this->assertCount(1, $products);
        $productAttributeNames = array_column($products[0]['attributes'], 'name');
        $this->assertContains('Brand', $productAttributeNames, 'Brand was marked fromProduct=true and should be promoted to product level');
    }

    public function testStreamCsvSplitsProductAndVariantTags(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU', 'Product Tags', 'Variant Tags'],
            ['prod-a', 'Product A', 'A-1', 'summer, sale', 'red,cotton'],
            ['prod-a', 'Product A', 'A-2', 'summer, sale', 'blue'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $action = $this->makeAction($this->tagMapping());
        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        $products = $this->readProducts($jsonlPath);
        $this->assertCount(1, $products);

        $this->assertSame(['summer', 'sale'], $products[0]['tags'], 'Product tags come from product_tags, comma-split and trimmed');
        $this->assertSame(['red', 'cotton'], $products[0]['variants'][0]['tags'], 'variant_tags is aliased onto the variant as tags');
        $this->assertSame(['blue'], $products[0]['variants'][1]['tags']);
    }

    public function testStreamCsvUnionsProductTagsAcrossVariantRowsWithoutDuplicating(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU', 'Product Tags', 'Variant Tags'],
            ['prod-a', 'Product A', 'A-1', 'summer', ''],
            ['prod-a', 'Product A', 'A-2', 'sale, summer', ''],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $action = $this->makeAction($this->tagMapping());
        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        $products = $this->readProducts($jsonlPath);
        $this->assertSame(['summer', 'sale'], $products[0]['tags']);
    }

    public function testStreamCsvUnionsProductFilesAcrossVariantRowsWithoutDuplicating(): void
    {
        // The photo column repeats on every variant row of a product, so a union that didn't key by
        // url would download the same image once per row.
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU', 'Photos'],
            ['prod-a', 'Product A', 'A-1', 'https://cdn.test/a.jpg|https://cdn.test/b.jpg'],
            ['prod-a', 'Product A', 'A-2', 'https://cdn.test/a.jpg|https://cdn.test/c.jpg'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $action = $this->makeAction([
            'product_name' => 'Name',
            'sku' => 'SKU',
            'handler' => 'Slug',
            'product_slug' => 'Slug',
            'files' => 'Photos',
            'product_files' => 'Photos',
        ]);
        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        $products = $this->readProducts($jsonlPath);
        $this->assertSame(
            ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg', 'https://cdn.test/c.jpg'],
            array_column($products[0]['files'], 'url')
        );
    }

    public function testStreamCsvEmitsNoProductFilesWhenTheMappingOmitsThem(): void
    {
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU', 'Photos'],
            ['prod-a', 'Product A', 'A-1', 'https://cdn.test/a.jpg'],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $action = $this->makeAction([
            'product_name' => 'Name',
            'sku' => 'SKU',
            'handler' => 'Slug',
            'product_slug' => 'Slug',
            'files' => 'Photos',
        ]);
        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        $products = $this->readProducts($jsonlPath);
        $this->assertSame([], $products[0]['files']);
        $this->assertCount(1, $products[0]['variants'][0]['files']);
    }

    public function testStreamCsvEmitsEmptyTagsWhenColumnsAreBlank(): void
    {
        // syncTags is detach-then-add, so a blank cell must arrive as [] and be
        // skipped downstream rather than wiping the entity's existing tags.
        $csvPath = $this->writeCsv([
            ['Slug', 'Name', 'SKU', 'Product Tags', 'Variant Tags'],
            ['prod-a', 'Product A', 'A-1', '', '   '],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $action = $this->makeAction($this->tagMapping());
        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        $products = $this->readProducts($jsonlPath);
        $this->assertSame([], $products[0]['tags']);
        $this->assertSame([], $products[0]['variants'][0]['tags']);
    }

    public function testStreamCsvReadsASemicolonDelimitedFile(): void
    {
        // Excel exports ';'-delimited CSV by default across most of Europe and
        // Latin America. The delimiter is detected from the file's shape, so
        // the tags column still splits on commas.
        $csvPath = $this->writeRawCsv(
            "Slug;Name;SKU;Product Tags;Variant Tags\n"
            . "prod-a;Product A;A-1;summer, sale;red\n"
            . "prod-a;Product A;A-2;summer, sale;blue\n"
        );
        $jsonlPath = $this->makeJsonlPath();

        $action = $this->makeAction($this->tagMapping());
        $action->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        $products = $this->readProducts($jsonlPath);
        $this->assertCount(1, $products, 'Both rows share a handler so they group into one product');
        $this->assertSame('Product A', $products[0]['name']);
        $this->assertSame(['summer', 'sale'], $products[0]['tags']);
        $this->assertSame(['red'], $products[0]['variants'][0]['tags']);
        $this->assertSame(['blue'], $products[0]['variants'][1]['tags']);
    }

    public function testMapperResolvesConstantsColumnsAndMissingColumns(): void
    {
        $result = $this->makeAction()->mapper(
            [
                'quantity' => '_1',
                'sku' => 'SKU',
                'missing' => 'No Such Column',
                'is_published' => true,
                'nested' => ['sku' => 'SKU', 'label' => '_fixed'],
            ],
            ['SKU' => 'A-1']
        );

        $this->assertSame('1', $result['quantity']);
        $this->assertSame('A-1', $result['sku']);
        $this->assertNull($result['missing']);
        $this->assertTrue($result['is_published']);
        $this->assertSame(['sku' => 'A-1', 'label' => 'fixed'], $result['nested']);
    }

    public function testMapperAppliesVariantAliasesCategoriesFilesAndDates(): void
    {
        $result = $this->makeAction()->mapper(
            [
                'variant_name' => 'Name',
                'categories' => 'Categories',
                'files' => 'Photos',
                'inventory_date' => 'Inventory Date',
                'sold_at' => 'date_Sold',
            ],
            [
                'Name' => 'Variant A',
                'Categories' => 'Cars, Used Cars',
                'Photos' => 'https://cdn.test/a.jpg|https://cdn.test/b.jpg?w=1',
                'Inventory Date' => '2026-01-15',
                'Sold' => '01/20/2026',
            ]
        );

        $this->assertSame('Variant A', $result['name']);
        $this->assertArrayNotHasKey('variant_name', $result);
        $this->assertSame(
            [
                ['name' => 'Cars', 'slug' => 'cars'],
                ['name' => 'Used Cars', 'slug' => 'used-cars'],
            ],
            $result['categories']
        );
        $this->assertSame(
            [
                ['url' => 'https://cdn.test/a.jpg', 'name' => 'a.jpg'],
                ['url' => 'https://cdn.test/b.jpg?w=1', 'name' => 'b.jpg'],
            ],
            $result['files']
        );
        $this->assertStringStartsWith('2026-01-15', $result['inventory_date']);
        $this->assertStringStartsWith('2026-01-20', $result['sold_at']);
    }

    public function testMapperFlattensAttributeGroupsKeepingFromProduct(): void
    {
        $result = $this->makeAction()->mapper(
            [
                'attributes' => [
                    ['fromProduct' => true, 'year' => 'Year'],
                    ['stock_number' => 'Stock #'],
                ],
            ],
            ['Year' => '2026', 'Stock #' => 'C123']
        );

        $this->assertSame(
            [
                ['fromProduct' => true, 'name' => 'year', 'value' => '2026'],
                ['fromProduct' => false, 'name' => 'stock_number', 'value' => 'C123'],
            ],
            $result['attributes']
        );
    }

    public function testMapperResolvesExpressionsIncludingInsideAttributeGroups(): void
    {
        $result = $this->makeAction()->mapper(
            [
                'product_name' => ['$concat' => ['Make', 'Model', 'Year']],
                'price' => ['$coalesce' => ['MSRP', 'Price']],
                'warehouses' => [['id' => 'extra.warehouse_id']],
                'attributes' => [
                    ['new' => ['$map' => 'New/Used', 'values' => ['New' => 1, 'Used' => 0]]],
                ],
            ],
            [
                'Make' => 'Cadillac',
                'Model' => 'XT6',
                'Year' => '2026',
                'MSRP' => '',
                'Price' => '59900',
                'New/Used' => 'Used',
                'extra' => ['warehouse_id' => 4],
            ]
        );

        $this->assertSame('Cadillac XT6 2026', $result['product_name']);
        $this->assertSame('59900', $result['price']);
        $this->assertSame([['id' => 4]], $result['warehouses']);
        $this->assertSame([['fromProduct' => false, 'name' => 'new', 'value' => 0]], $result['attributes']);
    }

    public function testStreamCsvPrefersTheImportChannelOverTheMapperConfiguration(): void
    {
        $rows = [
            ['Slug', 'Name', 'SKU', 'Price'],
            ['prod-a', 'Product A', 'A-1', '10.00'],
        ];

        $fromConfiguration = $this->makeJsonlPath();
        $this->makeAction(configuration: ['channels_id' => 1])
            ->streamCsvFileToJsonlFile($this->writeCsv($rows), $fromConfiguration, $this->stubProductType());

        $fromImport = $this->makeJsonlPath();
        $this->makeAction(configuration: ['channels_id' => 1], extra: ['channels_id' => 9])
            ->streamCsvFileToJsonlFile($this->writeCsv($rows), $fromImport, $this->stubProductType());

        $this->assertSame(1, $this->readProducts($fromConfiguration)[0]['variants'][0]['channels'][0]['channels_id']);
        $this->assertSame(9, $this->readProducts($fromImport)[0]['variants'][0]['channels'][0]['channels_id']);
    }

    public function testStreamCsvCarriesTheMappedWarehouseAndPublishFlagIntoTheChannelRow(): void
    {
        $rows = [
            ['Slug', 'Name', 'SKU', 'Price'],
            ['prod-a', 'Product A', 'A-1', '10.00'],
        ];
        $mapping = [
            'product_name' => 'Name',
            'sku' => 'SKU',
            'handler' => 'Slug',
            'price' => 'Price',
            'is_published' => '_1',
            'warehouses' => [['id' => 'extra.warehouse_id']],
        ];

        $mapped = $this->makeJsonlPath();
        $this->makeAction($mapping, extra: ['channels_id' => 9, 'warehouse_id' => 4])
            ->streamCsvFileToJsonlFile($this->writeCsv($rows), $mapped, $this->stubProductType());

        $legacy = $this->makeJsonlPath();
        $this->makeAction(configuration: ['channels_id' => 9])
            ->streamCsvFileToJsonlFile($this->writeCsv($rows), $legacy, $this->stubProductType());

        $this->assertEquals(
            ['channels_id' => 9, 'warehouses_id' => 4, 'price' => 10.0, 'discounted_price' => 0.0, 'is_published' => '1'],
            $this->readProducts($mapped)[0]['variants'][0]['channels'][0]
        );
        $this->assertEquals(
            ['channels_id' => 9, 'price' => 10.0, 'discounted_price' => 0.0],
            $this->readProducts($legacy)[0]['variants'][0]['channels'][0]
        );
    }

    public function testDealerTemplateMapsLegacyDealerRowsIntoProducts(): void
    {
        $template = ImportTemplateEnum::DEALER_VEHICLE_CSV->template();
        $header = ['VIN', 'Stock #', 'New/Used', 'Year', 'Make', 'Model', 'Series', 'MSRP', 'Price', 'Odometer', 'Description', 'Features', 'Photo Url List'];
        $csvPath = $this->writeCsv([
            $header,
            ['1GYKPGRS4TZ104417', 'C123', 'New', '2026', 'Cadillac', 'XT6', 'Sport', '62000', '59900', '12', 'Loaded', '', 'https://cdn.test/a.jpg|https://cdn.test/b.jpg'],
            ['2HGFC2F59LH512345', 'U77', 'Used', '2020', 'Honda', 'Civic', '', '', '18500', '41000', '', 'Backup camera', ''],
        ]);
        $jsonlPath = $this->makeJsonlPath();

        $this->makeAction(
            $template->mappingFor($template->resolveOptions([])),
            extra: ['channels_id' => 9, 'warehouse_id' => 4]
        )->streamCsvFileToJsonlFile($csvPath, $jsonlPath, $this->stubProductType());

        [$new, $used] = $this->readProducts($jsonlPath);

        $this->assertSame('Cadillac XT6 2026 Sport', $new['name']);
        $this->assertSame('1GYKPGRS4TZ104417', $new['sku']);
        $this->assertSame('1GYKPGRS4TZ104417', $new['slug']);
        $this->assertSame('Loaded', $new['description']);
        $this->assertSame([['name' => 'Cadillac', 'slug' => 'cadillac']], $new['categories']);

        $this->assertSame(
            ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'],
            array_column($new['files'], 'url'),
            'The photos land on the product as well as the variant'
        );

        $variant = $new['variants'][0];
        $this->assertSame('62000', $variant['price']);
        $this->assertCount(2, $variant['files']);
        $this->assertEquals(
            ['channels_id' => 9, 'warehouses_id' => 4, 'price' => 62000.0, 'discounted_price' => 62000.0, 'is_published' => '1'],
            $variant['channels'][0]
        );
        $this->assertSame(4, $variant['warehouses'][0]['id']);
        $this->assertTrue($variant['warehouses'][0]['is_new'], 'VariantsWarehouses types is_new as bool');
        $this->assertContains(['fromProduct' => true, 'name' => 'new', 'value' => 1], $variant['attributes']);
        $this->assertContains(['fromProduct' => false, 'name' => 'stock_number', 'value' => 'C123'], $variant['attributes']);

        $this->assertSame('Honda Civic 2020', $used['name']);
        $this->assertSame('Backup camera', $used['description']);
        $this->assertSame('18500', $used['variants'][0]['price']);
        $this->assertEmpty($used['variants'][0]['files']);
        $this->assertSame([], $used['files']);
        $this->assertFalse($used['variants'][0]['warehouses'][0]['is_new']);
        $this->assertEquals(0.0, $used['variants'][0]['channels'][0]['discounted_price']);
    }

    private function writeRawCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rawcsv') . '.csv';
        file_put_contents($path, $content);
        $this->tempPaths[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function tagMapping(): array
    {
        return [
            'product_name' => 'Name',
            'sku' => 'SKU',
            'handler' => 'Slug',
            'product_slug' => 'Slug',
            'product_tags' => 'Product Tags',
            'variant_tags' => 'Variant Tags',
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $configuration
     */
    private function makeAction(?array $mapping = null, array $configuration = [], array $extra = []): ImportProductFromFilesystemAction
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
        $import->extra = $extra;
        $import->setRelation('filesystemMapper', $mapper);

        return new ImportProductFromFilesystemAction($import);
    }

    private function stubProductType(): ProductsTypes
    {
        // In-memory stub — bypasses the resolveProductType DB lookup that
        // execute() does in production. Lets us test the streaming logic
        // without setting up a real ProductsTypes record.
        $type = new ProductsTypes();
        $type->id = 999;
        $type->name = 'Stub Type';
        $type->weight = 1;

        return $type;
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
