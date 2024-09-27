<?php

namespace Tests\GraphQL\Inventory\Traits;

use Illuminate\Testing\TestResponse;

trait InventoryCases
{
    public function createProduct(array $data = []): TestResponse
    {
        if(empty($data)) {
            $data = [
                'name' => fake()->name,
                'description' => fake()->text,
                'sku' => fake()->time,
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
                    attributes {
                        name
                        value
                    }
                }
            }', ['data' => $data]);
    }

    public function createVariant(int $productId, array $warehouseData, array $data = []): TestResponse
    {
        if(empty($data)) {
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
}
