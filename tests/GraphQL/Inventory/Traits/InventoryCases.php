<?php

namespace Tests\GraphQL\Inventory\Traits;

use Baka\Support\Str;
use Illuminate\Testing\TestResponse;

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
                    attributes {
                        name
                        value
                    }
                }
            }', ['data' => $data]);
    }

    public function createVariant(string $productId, array $warehouseData, array $data = []): TestResponse
    {
        if (empty($data)) {
            $data = [
                'name' => fake()->name,
                'description' => fake()->text,
                'products_id' => $productId,
                'sku' => fake()->time,
                'warehouses' => [$warehouseData],
                'attributes' => [
                    [
                        'name' => fake()->name,
                        'value' => fake()->name,
                    ],
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
                    description
                    products_id
                }
            }', ['data' => $data]);
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
}
