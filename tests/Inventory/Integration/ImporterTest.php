<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Enums\ConfigEnum as AttributeConfigEnum;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Tests\TestCase;

final class ImporterTest extends TestCase
{
    public function testImportAction(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $setupCompany = new Setup(
            app(Apps::class),
            auth()->user(),
            $company
        );
        $setupCompany->run();

        $attributes = [
            'attributes' => [
                [
                    'name' => fake()->word(),
                    'value' => fake()->word(),
                ],
                [
                    'name' => fake()->word(),
                    'value' => fake()->word(),
                ],
            ],
        ];

        $region = RegionRepository::getByName('default', $company);

        $warehouse = [
            'name' => fake()->word(),
            'regions_id' => $region->getId(),
            'is_default' => true,
            'is_published' => true,
        ];

        $warehouseData = (new CreateWarehouseAction(
            WarehousesDto::viaRequest($warehouse, auth()->user(), $company),
            auth()->user()
        ))->execute();

        $statusData = (new CreateStatusAction(
            new Status(
                app(Apps::class),
                $company,
                auth()->user(),
                'Default',
                true
            ),
            auth()->user()
        ))->execute();

        $productData = ProductImporter::from([
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'slug' => fake()->slug(),
            'sku' => fake()->time(),
            'price' => fake()->randomNumber(2),
            'quantity' => fake()->randomNumber(2),
            'isPublished' => true,
            'files' => [
                [
                    'url' => fake()->imageUrl,
                    'name' => fake()->word(),
                ],
                [
                    'url' => fake()->imageUrl,
                    'name' => fake()->word(),
                ],
            ],
            'variants' => [
                [
                    'name' => fake()->word(),
                    //'warehouse_id' => $warehouseData->getId(),
                    'warehouse' => [
                        'id' => $warehouseData->getId(),
                        'price' => fake()->randomNumber(2),
                        'quantity' => fake()->randomNumber(2),
                        'sku' => fake()->time(),
                        'is_new' => fake()->boolean(),
                        'status' => $statusData,
                    ],
                    'description' => fake()->sentence(),
                    'sku' => fake()->time(),
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    ...$attributes,
                    'files' => [
                        [
                            'url' => fake()->imageUrl,
                            'name' => fake()->word(),
                        ],
                        [
                            'url' => fake()->imageUrl,
                            'name' => fake()->word(),
                        ],
                    ],
                ],
                [
                    'name' => fake()->word(),
                    //'warehouse_id' => $warehouseData->getId(),
                    'warehouse' => [
                        'id' => $warehouseData->getId(),
                        'price' => fake()->randomNumber(2),
                        'quantity' => fake()->randomNumber(2),
                        'sku' => fake()->time(),
                        'is_new' => fake()->boolean(),
                    ],
                    'description' => fake()->sentence(),
                    'sku' => fake()->time(),
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    ...$attributes,
                ],
            ],
            'categories' => [
                [
                    'name' => fake()->word(),
                    'code' => (string) fake()->randomNumber(3),
                    'position' => fake()->randomNumber(1),
                ],
            ],
            ...$attributes,
        ]);

        $productImporter = new ProductImporterAction(
            $productData,
            $company,
            auth()->user(),
            $region
        );

        $this->assertInstanceOf(Products::class, $productImporter->execute());
    }

    public function testImportActionWithDefaultAttributes(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $setupCompany = new Setup(
            app(Apps::class),
            auth()->user(),
            $company
        );
        $setupCompany->run();
        $app = app(Apps::class);
        $customAttribute = 'test_' . fake()->word();
        $app->set(AttributeConfigEnum::DEFAULT_VARIANT_ATTRIBUTE->value, [
            [
                'name' => $customAttribute,
                'value' => fake()->word(),
            ]
        ]);
        $attributes = [
            'attributes' => [
                [
                    'name' => fake()->word(),
                    'value' => fake()->word(),
                ],
                [
                    'name' => fake()->word(),
                    'value' => fake()->word(),
                ],
            ],
        ];

        $region = RegionRepository::getByName('default', $company);

        $warehouse = [
            'name' => fake()->word(),
            'regions_id' => $region->getId(),
            'is_default' => true,
            'is_published' => true,
        ];

        $warehouseData = (new CreateWarehouseAction(
            WarehousesDto::viaRequest($warehouse, auth()->user(), $company),
            auth()->user()
        ))->execute();

        $statusData = (new CreateStatusAction(
            new Status(
                app(Apps::class),
                $company,
                auth()->user(),
                'Default',
                true
            ),
            auth()->user()
        ))->execute();

        $productData = ProductImporter::from([
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'slug' => fake()->slug(),
            'sku' => fake()->time(),
            'price' => fake()->randomNumber(2),
            'quantity' => fake()->randomNumber(2),
            'isPublished' => true,
            'files' => [
                [
                    'url' => fake()->imageUrl,
                    'name' => fake()->word(),
                ],
                [
                    'url' => fake()->imageUrl,
                    'name' => fake()->word(),
                ],
            ],
            'variants' => [
                [
                    'name' => fake()->word(),
                    //'warehouse_id' => $warehouseData->getId(),
                    'warehouse' => [
                        'id' => $warehouseData->getId(),
                        'price' => fake()->randomNumber(2),
                        'quantity' => fake()->randomNumber(2),
                        'sku' => fake()->time(),
                        'is_new' => fake()->boolean(),
                        'status' => $statusData,
                    ],
                    'description' => fake()->sentence(),
                    'sku' => fake()->time(),
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    ...$attributes,
                    'files' => [
                        [
                            'url' => fake()->imageUrl,
                            'name' => fake()->word(),
                        ],
                        [
                            'url' => fake()->imageUrl,
                            'name' => fake()->word(),
                        ],
                    ],
                ],
                [
                    'name' => fake()->word(),
                    //'warehouse_id' => $warehouseData->getId(),
                    'warehouse' => [
                        'id' => $warehouseData->getId(),
                        'price' => fake()->randomNumber(2),
                        'quantity' => fake()->randomNumber(2),
                        'sku' => fake()->time(),
                        'is_new' => fake()->boolean(),
                    ],
                    'description' => fake()->sentence(),
                    'sku' => fake()->time(),
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    ...$attributes,
                ],
            ],
            'categories' => [
                [
                    'name' => fake()->word(),
                    'code' => (string) fake()->randomNumber(3),
                    'position' => fake()->randomNumber(1),
                ],
            ],
            ...$attributes,
        ]);

        $productImporter = new ProductImporterAction(
            $productData,
            $company,
            auth()->user(),
            $region
        );
        $product = $productImporter->execute();
        $this->assertInstanceOf(Products::class, $product);
        $attribute = $product->variants()->first()->getAttributeByName($customAttribute);
        $this->assertInstanceOf(Attributes::class, $attribute);
    }
}
