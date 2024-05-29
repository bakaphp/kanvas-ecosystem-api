<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class ProductCategoryTest extends TestCase
{
    public function testAddProductCategoryTest(): void
    {
        $dataRegion = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $response = $this->graphQL('
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
            }', ['data' => $dataRegion])
            ->assertJson([
                'data' => ['createRegion' => $dataRegion]
            ]);
        $idRegion = $response->json()['data']['createRegion']['id'];
        $data = [
            'regions_id' => $idRegion,
            'name' => 'Test Warehouse',
            'location' => 'Test Location',
            'is_default' => true,
            'is_published' => true,
        ];

        $response = $this->graphQL('
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
            }', ['data' => $data])->assertJson([
            'data' => ['createWarehouse' => $data]
        ]);

        $categoryData = [
            'name' => fake()->name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];
        $categoryResponse = $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    position,
                    is_published
                    weight
                }
            }', ['data' => $categoryData])->assertJson([
            'data' => ['createCategory' => $categoryData]
        ]);
        $idCategory = $categoryResponse->json()['data']['createCategory']['id'];

        $data = [
            'name' => fake()->name,
            'sku' => fake()->time,
            'description' => fake()->text,
            'categories' => [
                [
                    'id' => $idCategory
                ]
            ]
        ];

        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    id
                    name
                    description
                    categories{
                        id
                    }
                }
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);
    }
}
