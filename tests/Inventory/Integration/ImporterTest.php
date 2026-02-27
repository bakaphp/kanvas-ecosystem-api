<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Enums\ConfigEnum as AttributeConfigEnum;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
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

        // Retry to handle savepoint conflicts from parallel test execution
        $this->assertInstanceOf(Products::class, retry(3, fn () => $productImporter->execute(), 100));
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
            ],
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
        $this->assertInstanceOf(VariantsAttributes::class, $attribute);
    }

    public function testImportRePublishesUnpublishedProductWhenVariantPublishedToChannel(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $user = auth()->user();

        $setupCompany = new Setup($app, $user, $company);
        $setupCompany->run();

        $region = RegionRepository::getByName('default', $company);

        $warehouseModel = new CreateWarehouseAction(
            WarehousesDto::viaRequest([
                'name' => fake()->word(),
                'regions_id' => $region->getId(),
                'is_default' => true,
                'is_published' => true,
            ], $user, $company),
            $user
        )->execute();

        $channelModel = new CreateChannel(
            new ChannelsDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'Import Test Channel ' . fake()->word(),
            ),
            $user
        )->execute();

        $variantSku = 'import-republish-test-' . fake()->time();

        // First import: create the product with a variant + channel
        $productData = ProductImporter::from([
            'name' => 'Republish Test Product ' . fake()->word(),
            'description' => fake()->sentence(),
            'slug' => fake()->slug(),
            'sku' => $variantSku,
            'price' => fake()->randomNumber(2),
            'quantity' => fake()->randomNumber(2),
            'isPublished' => true,
            'variants' => [
                [
                    'name' => fake()->word(),
                    'description' => fake()->sentence(),
                    'sku' => $variantSku,
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    'warehouse' => [
                        'id' => $warehouseModel->getId(),
                        'price' => fake()->randomNumber(2),
                        'quantity' => fake()->randomNumber(2),
                        'sku' => $variantSku,
                    ],
                    'channels' => [
                        [
                            'channels_id' => $channelModel->getId(),
                            'warehouses_id' => $warehouseModel->getId(),
                            'price' => 50,
                            'discounted_price' => 0,
                            'is_published' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $product = new ProductImporterAction(
            $productData,
            $company,
            $user,
            $region
        )->execute();

        $this->assertInstanceOf(Products::class, $product);
        $this->assertEquals(1, $product->is_published);

        // Unpublish the product (simulating it was taken down)
        $product->unPublish();
        $product->refresh();
        $this->assertEquals(0, $product->is_published);

        // Re-import the same product with the variant published to channel
        $productData = ProductImporter::from([
            'name' => $product->name,
            'description' => $product->description,
            'slug' => $product->slug,
            'sku' => $variantSku,
            'price' => fake()->randomNumber(2),
            'quantity' => fake()->randomNumber(2),
            'isPublished' => true,
            'variants' => [
                [
                    'name' => fake()->word(),
                    'description' => fake()->sentence(),
                    'sku' => $variantSku,
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    'warehouse' => [
                        'id' => $warehouseModel->getId(),
                        'price' => fake()->randomNumber(2),
                        'quantity' => fake()->randomNumber(2),
                        'sku' => $variantSku,
                    ],
                    'channels' => [
                        [
                            'channels_id' => $channelModel->getId(),
                            'warehouses_id' => $warehouseModel->getId(),
                            'price' => 75,
                            'discounted_price' => 0,
                            'is_published' => true,
                        ],
                    ],
                ],
            ],
        ]);

        new ProductImporterAction(
            $productData,
            $company,
            $user,
            $region
        )->execute();

        // The product should be re-published because a variant was published to a channel
        $product->refresh();
        $this->assertEquals(1, $product->is_published);
    }
}
