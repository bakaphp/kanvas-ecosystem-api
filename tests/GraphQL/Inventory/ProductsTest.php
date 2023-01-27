<?php
declare(strict_types=1);
namespace Tests\GraphQL\Inventory;

use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    /**
     * testSave
     *
     * @return void
     */
    public function testSave(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];

        $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
        ]);
    }

    /**
     * test get product
     *
     * @return void
     */
    public function testGetProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];

        $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
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
            'data' => ['products' => ['data' => [$data]]]
        ]);
    }

    /**
     * test update product
     *
     * @return void
     */
    public function testUpdateProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
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
        mutation($data: ProductInputUpdate! $id: Int!) {
            updateProduct(input: $data, id: $id)
            {
                name
                description
            }
        }', ['data' => $data, 'id' => $id])->assertJson([
            'data' => ['updateProduct' => $data]
        ]);
    }

    /**
     * testDeleteProduct
     *
     * @return void
     */
    public function testDeleteProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
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
        $this->graphQL('
        mutation($id: Int!) {
            deleteProduct(id: $id)
        }', ['id' => $id])->assertJson([
            'data' => ['deleteProduct' => true]
        ]);
    }

    /**
     * testAddVariantToProduct
     *
     * @return void
     */
    public function testAddVariantToProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
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
            'products_id' => $id
        ];
        $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            {
                name
                description
                products_id
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createVariant' => $data]
        ]);
    }

    /**
     * testDeleteVariantToProduct
     *
     * @return void
     */
    public function testDeleteVariantToProduct(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
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
        $id = $response->json()['data']['products']['data'][0]['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $id
        ];
        $response = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            {
                id
                name
                description
                products_id
            }
        }', ['data' => $data]);
        $id = $response->json()['data']['createVariant']['id'];
        $this->graphQL('
        mutation($id: Int!) {
            deleteVariant(id: $id)
        }', ['id' => $id])->assertJson([
            'data' => ['deleteVariant' => true]
        ]);
    }
}
