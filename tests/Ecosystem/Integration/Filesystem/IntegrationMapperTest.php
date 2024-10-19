<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\Actions\ImportDataFromFilesystemAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\FilesystemImports;
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
        $user = auth()->user();
        $app = app(Apps::class);

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
            'files' => 'File URL',
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
                    'files' => 'File URL',
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
            'options' => [], // validate optional params is enable
        ];

        $filesystemMapperName = 'Products' . uniqid();
        $dto = new FilesystemMapper(
            $app,
            $user->getCurrentBranch(),
            $user,
            SystemModulesRepository::getByModelName(Products::class),
            $filesystemMapperName,
            [],
            $mapper,
        );
        $filesystemMapper = (new CreateFilesystemMapperAction($dto))->execute();

        $regionDto = new Region(
            $user->getCurrentCompany(),
            $app,
            $user,
            Currencies::getById(1),
            'Region Name',
            'Region Short Slug',
            null,
            1,
        );
        $region = (new CreateRegionAction($regionDto, $user))->execute();
        $warehouseDto = new Warehouses(
            $user->getCurrentCompany(),
            $app,
            $user,
            $region,
            'Warehouse Name',
            'Warehouse Location',
            true,
            true,
        );
        $warehouse = (new CreateWarehouseAction($warehouseDto, $user))->execute();
        $values = [
                    'List Number' => fake()->numerify('LIST-####'),
                    'Features' => fake()->sentence,
                    'regionId' => $region->getId(),
                    'Original List Price' => fake()->randomFloat(2, 100, 1000),
                    'Discount Price' => fake()->randomFloat(2, 50, 900),
                    'Quantity' => fake()->numberBetween(1, 100),
                    'Is Published' => fake()->boolean,
                    'File URL' => fake()->imageUrl . '|' . fake()->imageUrl . '|' . fake()->imageUrl,
                    'File Name' => fake()->word . '.jpg',
                    'Property Type' => fake()->word,
                    'Weight' => fake()->randomFloat(2, 0.5, 5),
                    'customFields' => [],
                    'Status' => fake()->boolean,
                    'Warehouse ID' => $warehouse->getId(),
                    'is_new' => fake()->boolean,
                    'Style' => fake()->word,
                    'Position' => fake()->numberBetween(1, 10),
            ];

        $importDataFromFilesystemAction = new ImportDataFromFilesystemAction(new FilesystemImports());
        $dataMapper = $importDataFromFilesystemAction->mapper($filesystemMapper->mapping, $values);
        $productDto = ProductImporter::from($dataMapper);

        $productImporter = new ProductImporterAction(
            $productDto,
            $user->getCurrentCompany(),
            $user,
            $region
        );

        $this->assertInstanceOf(Products::class, $productImporter->execute());
    }
}
