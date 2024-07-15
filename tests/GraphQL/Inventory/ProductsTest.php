<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class ProductsTest extends TestCase
{
    /**
     * testSave.
     */
    public function testSave(): void
    {
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

        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                    attributes {
                        name
                        value
                    }
                }
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);
    }

    /**
     * test get product.
     */
    public function testGetProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
        ];

        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                }
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);

        $this->graphQL('
            query {
                products {
                    data {
                        name
                        description
                    }
                }
            }')->assertJson([
            'data' => ['products' => ['data' => [$data]]],
        ]);
    }

    /**
     * test update product.
     */
    public function testUpdateProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
        ];
        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                }
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);

        $response = $this->graphQL('
        query {
            products {
                data {
                    id
                    name
                    description
                }
            }
        }');
        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('products', $response->json()['data']);
        $this->assertArrayHasKey('data', $response->json()['data']['products']);
        $this->assertArrayHasKey('id', $response->json()['data']['products']['data'][0]);

        $id = $response->json()['data']['products']['data'][0]['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($data: ProductInputUpdate! $id: ID!) {
            updateProduct(input: $data, id: $id)
            {
                name
                description
            }
        }', ['data' => $data, 'id' => $id])->assertJson([
            'data' => ['updateProduct' => $data],
        ]);
    }

    /**
     * testDeleteProduct.
     */
    public function testDeleteProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
        ];
        $response = $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                id
                name
                description
            }
        }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);
        $id = $response->json()['data']['createProduct']['id'];
        $this->graphQL('
                mutation($id: ID!) {
                    deleteProduct(id: $id)
                }', ['id' => $id])->assertJson([
            'data' => ['deleteProduct' => true],
        ]);
    }

    /**
     * testAddVariantToProduct.
     */
    public function testAddVariantToProduct(): void
    {
        $region = [
            'name' => fake()->name,
            'short_slug' => fake()->name,
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $regionResponse = $this->graphQL('
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
        ', [
            'data' => $region,
        ])->assertJson([
            'data' => ['createRegion' => $region],
        ]);
        $regionResponse = $regionResponse->decodeResponseJson();

        $warehouseData = [
            'regions_id' => $regionResponse['data']['createRegion']['id'],
            'name' => fake()->name,
            'location' => 'Test Location',
            'is_default' => true,
            'is_published' => true,
        ];

        $warehouseResponse = $this->graphQL('
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
        }', ['data' => $warehouseData])->assertJson([
        'data' => ['createWarehouse' => $warehouseData],
    ]);

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
        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                    attributes {
                        name
                        value
                    }
                }
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);

        $response = $this->graphQL('
        query {
            products {
                data {
                    id
                    name
                    description
                }
            }
        }');
        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('products', $response->json()['data']);
        $this->assertArrayHasKey('data', $response->json()['data']['products']);
        $this->assertArrayHasKey('id', $response->json()['data']['products']['data'][0]);

        $id = $response->json()['data']['products']['data'][0]['id'];

        $warehouseData = [
            'id' => $warehouseResponse['data']['createWarehouse']['id'],
        ];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $id,
            'sku' => fake()->time,
            'warehouses' => [$warehouseData],
            'attributes' => [
                [
                    'name' => fake()->name,
                    'value' => fake()->name,
                ],
            ],
        ];
        $variantResponse = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            {
                id
                name
                description
                products_id
            }
        }', ['data' => $data]);

        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);
    }

    /**
     * testDeleteVariantToProduct.
     */
    public function testDeleteVariantToProduct(): void
    {
        $region = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $regionResponse = $this->graphQL('
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
        ', [
            'data' => $region,
        ])->assertJson([
            'data' => ['createRegion' => $region],
        ]);
        $regionResponse = $regionResponse->decodeResponseJson();

        $warehouseData = [
            'regions_id' => $regionResponse['data']['createRegion']['id'],
            'name' => fake()->name,
            'location' => 'Test Location',
            'is_default' => true,
            'is_published' => true,
        ];

        $warehouseResponse = $this->graphQL('
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
        }', ['data' => $warehouseData])->assertJson([
        'data' => ['createWarehouse' => $warehouseData],
    ]);

        $data = [
            'name' => fake()->name,
            'sku' => fake()->time,
            'description' => fake()->text,
        ];
        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                }
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);

        $response = $this->graphQL('
        query {
            products {
                data {
                    id
                    name
                    description
                }
            }
        }');
        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('products', $response->json()['data']);
        $this->assertArrayHasKey('data', $response->json()['data']['products']);
        $this->assertArrayHasKey('id', $response->json()['data']['products']['data'][0]);

        $id = $response->json()['data']['products']['data'][0]['id'];

        $warehouseData = [
            'id' => $warehouseResponse['data']['createWarehouse']['id'],
        ];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $id,
            'sku' => fake()->time,
            'warehouses' => [$warehouseData]
        ];
        $variantResponse = $this->graphQL('
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
        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);

        $variantResponseId = $variantResponse->json()['data']['createVariant']['id'];
        $this->graphQL('
        mutation($id: ID!) {
            deleteVariant(id: $id)
        }', ['id' => $variantResponseId])->assertJson([
            'data' => ['deleteVariant' => true],
        ]);
    }
}
