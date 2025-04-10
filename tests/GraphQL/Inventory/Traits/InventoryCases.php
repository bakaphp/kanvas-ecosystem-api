<?php

namespace Tests\GraphQL\Inventory\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Testing\TestResponse;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status;
use Kanvas\Inventory\Status\Models\Status as ModelsStatus;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as DataTransferObjectWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;

trait InventoryCases
{
    public function createProduct(array $data = []): TestResponse
    {
        if (empty($data)) {
            $name = fake()->name;
            $data = [
                'name' => $name,
                'description' => fake()->text,
                'sku' => fake()->time,
                'slug' => Str::slug($name),
                'weight' => 1,
                'attributes' => [
                    [
                        'name' => fake()->name,
                        'value' => fake()->name,
                    ],
                ],
            ];
        }

        return $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    id
                    name
                    description
                    slug
                    weight
                    attributes {
                        name
                        value
                    }
                }
            }', ['data' => $data]);
    }

    public function createVariant(string $productId, array $warehouseData, array $data = [], array $attributes = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'name' => fake()->name,
                'description' => fake()->text,
                'products_id' => $productId,
                'sku' => fake()->time,
                'ean' => fake()->ean13,
                'barcode' => fake()->ean13,
                'weight' => 1,
                'warehouses' => [$warehouseData],
                'attributes' => [
                    [
                        'name' => fake()->name,
                        'value' => fake()->name,
                    ],
                    ...$attributes,
                ],
            ];
        }

        return $this->graphQL('
            mutation($data: VariantsInput!) {
                createVariant(input: $data)
                {
                    id
                    name
                    sku
                    ean
                    barcode
                    description
                    products_id
                    weight
                }
            }', ['data' => $data]);
    }

    public function createChannel(array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'name' => fake()->name,
                'description' => fake()->text,
                'is_default' => true,
            ];
        }

        return $this->graphQL('
        mutation($data: CreateChannelInput!) {
            createChannel(input: $data)
            {
                id
                name
                description,
                is_default
            }
        }', ['data' => $data]);
    }

    public function addVariantToChannel(string $variantId, string $channelId, array $warehouseData, array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'variants_id' => $variantId,
                'channels_id' => $channelId,
                'warehouses_id' => $warehouseData['id'],
                'input' => [
                    'price' => 100,
                    'discounted_price' => 10,
                    'is_published' => true,
                ]
            ];
        }

        return $this->graphQL('
        mutation addVariantToChannel($variants_id: ID! $channels_id: ID! $warehouses_id: ID! $input: VariantChannelInput!){
            addVariantToChannel(variants_id: $variants_id channels_id:$channels_id warehouses_id:$warehouses_id input:$input){
                id
            } 
        }
        ', $data);
    }

    public function addVariantToWarehouse(string $variantId, string $warehouseId, int $amount = 0, array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'data' => [
                    'id' => $warehouseId,
                    'price' => rand(1, 1000),
                    'quantity' => $amount ?? rand(1, 5),
                    'position' => rand(1, 4),
                ],
                'id' => $variantId,
            ];
        }

        return $this->graphQL('
        mutation addVariantToWarehouse($data: WarehouseReferenceInput! $id: ID!) {
            addVariantToWarehouse(input: $data id: $id)
            {
                id
                name
                description
                products_id
                warehouses{
                    warehouseinfo{
                        id
                    }
                }
            }
        }', $data);
    }

    public function createRegion(array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'name' => fake()->name,
                'slug' => Str::slug(fake()->name),
                'short_slug' =>  Str::slug(fake()->name),
                'is_default' => 1,
                'currency_id' => 1,
            ];
        }

        return $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', ['data' => $data]);
    }

    public function createWarehouses(string $regionId, array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'regions_id' => $regionId,
                'name' => fake()->name,
                'location' => 'Test Location',
                'is_default' => true,
                'is_published' => true,
            ];
        }

        return $this->graphQL('
            mutation($data: WarehouseInput!) {
                createWarehouse(input: $data)
                {
                    id
                    regions_id
                    name
                    location
                    is_default
                    is_published
                }
            }', ['data' => $data]);
    }

    public function createDefaultWarehouse(CompanyInterface $company, AppInterface $app, UserInterface $user, Regions $region): Warehouses
    {
        $createWarehouse = new CreateWarehouseAction(
            new DataTransferObjectWarehouses(
                $company,
                $app,
                $user,
                $region,
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                (bool) StateEnums::YES->getValue(),
                (bool) StateEnums::YES->getValue(),
            ),
            $user
        );

        return $createWarehouse->execute();
    }

    public function createDefaultRegion(CompanyInterface $company, AppInterface $app, UserInterface $user): Regions
    {
        $createRegion = new CreateRegionAction(
            new Region(
                $company,
                $app,
                $user,
                Currencies::getBaseCurrency(),
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                null,
                StateEnums::YES->getValue(),
            ),
            $user
        );

        return $createRegion->execute();
    }

    public function createDefaultStatus(CompanyInterface $company, AppInterface $app, UserInterface $user): ModelsStatus
    {
        $createDefaultStatus = new CreateStatusAction(
            new Status(
                $app,
                $company,
                $user,
                'Default',
                true
            ),
            $user
        );

        return $createDefaultStatus->execute();
    }
}
