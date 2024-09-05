<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\Actions\ImportDataFromFilesystemAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

final class IntegrationMapperTest extends TestCase
{
    public function testImportDataFromFilesystemAction(): void
    {
        $mapper = [
            'name' => 'List Number',
            'description' => 'Features',
            'sku' => 'List Number',
            'slug' => 'List Number',
            'regionId' => 'regionId',
            'price' => 'Original List Price',
            'discountPrice' => 'Discount Price',
            'quantity' => 'Quantity',
            'is_published' => 'Is Published',
            'files' => [
                [
                    'url' => 'File URL',
                    'name' => 'File Name',
                ],
                ],
            'productType' => [
                'name' => 'Property Type',
                'description' => 'Property Type',
                'is_published' => 'Is Published',
                'weight' => 'Weight',
            ],
            'customFields' => [],
            'attributes' => [],
            'variants' => [
                [
                    'name' => 'List Number',
                    'description' => 'Features',
                    'sku' => 'List Number',
                    'price' => 'Original List Price',
                    'discountPrice' => 'Discount Price',
                    'is_published' => 'Status',
                    'slug' => 'List Number',
                    'files' => [
                        [
                            'url' => 'File URL',
                            'name' => 'File Name',
                        ],
                    ],
                    'warehouse' => [
                        [
                            'id' => 'Warehouse ID',
                            'price' => 'Original List Price',
                            'quantity' => 'Quantity',
                            'sku' => 'List Number',
                            'is_new' => true,
                        ],
                    ],
                ],
            ],
            'categories' => [
                [
                    'name' => 'Style',
                    'code' => 'Style',
                    'is_published' => 'Is Published',
                    'position' => 'Position',
                ],
            ],
            'options' => [] // validate optional params is enable
        ];

        $filesystemMapperName = 'Products' . uniqid();
        $dto = new FilesystemMapper(
            app(Apps::class),
            auth()->user()->getCurrentBranch(),
            auth()->user(),
            SystemModulesRepository::getByModelName(Products::class),
            $filesystemMapperName,
            [],
            $mapper,
        );
        $filesystemMapper = (new CreateFilesystemMapperAction($dto))->execute();

        $regionDto = new Region(
            auth()->user()->getCurrentCompany(),
            app(Apps::class),
            auth()->user(),
            Currencies::getById(1),
            'Region Name',
            'Region Short Slug',
            null,
            1,
        );
        $region = (new CreateRegionAction($regionDto, auth()->user()))->execute();
        $warehouseDto = new Warehouses(
            auth()->user()->getCurrentCompany(),
            app(Apps::class),
            auth()->user(),
            $region,
            'Warehouse Name',
            'Warehouse Location',
            true,
            true,
        );
        $warehouse = (new CreateWarehouseAction($warehouseDto, auth()->user()))->execute();
        $values =
[
    'List Number' => fake()->numerify('LIST-####'),
    'Features' => fake()->sentence,
    'regionId' => fake()->numerify('REG###'),
    'Original List Price' => fake()->randomFloat(2, 100, 1000),
    'Discount Price' => fake()->randomFloat(2, 50, 900),
    'Quantity' => fake()->numberBetween(1, 100),
    'Is Published' => fake()->boolean,

            'File URL' => fake()->url,
                        'File Name' => fake()->word . '.jpg',

                        'Property Type' => fake()->word,
                        'Weight' => fake()->randomFloat(2, 0.5, 5),

    'customFields' => [],
    'Status' => fake()->boolean,
    'Warehouse ID' => fake()->numerify('WH-###'),
     'is_new' => fake()->boolean,

    'Style' => fake()->word,
            'Position' => fake()->numberBetween(1, 10),
];

        $dataMapper = ImportDataFromFilesystemAction::mapper($filesystemMapper->mapping, $values);
        $productDto = ProductImporter::from($dataMapper);

        $productImporter = new ProductImporterAction(
            $productDto,
            auth()->user()->getCurrentCompany(),
            auth()->user(),
            $region
        );

        $this->assertInstanceOf(Products::class, $productImporter->execute());
    }
}
