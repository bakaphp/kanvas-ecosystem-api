<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Languages\Models\Languages;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    use InventoryCases;

    /**
     * testSave.
     */
    public function testSave(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
            'weight' => 1,
            'attributes' => [
                [
                    'name' => fake()->name,
                    'value' => fake()->name,
                ],
            ],
        ];

        $response = $this->createProduct($data);
        unset($data['id']);
        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);
    }

    public function testSortByAttributes(): void
    {
        $attributeName = fake()->name;
        $sku = fake()->time;
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => $sku,
            'attributes' => [
                [
                    'name' => $attributeName,
                    'value' => 0,
                ],
            ],
        ];

        $response = $this->createProduct($data);
        unset($data['id']);
        unset($data['sku']);
        $response = $this->graphQL(
            "
            query {
                products(
                    attributeOrderBy: {
                     name: \"$attributeName\", 
                     sort: \"DESC\" ,
                     format: NUMERIC
                }                    
                ) {
                    data {
                        name
                        description
                    }
                }
            }"
        );
        $this->assertEquals($data['name'], $response->json()['data']['products']['data'][0]['name']);
        // $this->assertArrayHasKey('name', $response->json()['data']['products']['data'][0]);
    }
    public function testSortByVariantAttributes(): void
    {
        $attributeName = fake()->name;
        $sku = fake()->time;
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => $sku,
            'attributes' => [
                [
                    'name' => $attributeName,
                    'value' => 0,
                ],
            ],
        ];
        $data['variants'][] = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => $sku,
            'attributes' => [
                [
                    'name' => $attributeName,
                    'value' => 0,
                ],
            ],
        ];
        $response = $this->createProduct($data);
        unset($data['id']);
        unset($data['sku']);
        $response = $this->graphQL(
            "
            query {
                products(
                    variantAttributeOrderBy: {
                     name: \"$attributeName\", 
                     sort: \"DESC\" ,
                     format: NUMERIC
                }                    
                ) {
                    data {
                        name
                        description
                    }
                }
            }"
        );
        $this->assertEquals($data['name'], $response->json()['data']['products']['data'][0]['name']);
        // $this->assertArrayHasKey('name', $response->json()['data']['products']['data'][0]);
    }

    public function testFilterByNearByLocation(): void
    {
        $sku = fake()->time;
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => $sku,
            'attributes' => [
                [
                    'name' => 'coordinates',
                    'value' => [
                        "lat" => 18.463449,
                        "long" => -66.117866
                    ],
                ],
            ],
        ];

        $response = $this->createProduct($data);
        unset($data['id']);
        unset($data['sku']);

        $location = [
            'lat' => 18.500000,
            'long' => -66.150000,
        ];
        $response = $this->graphQL(
            "
            query {
                products(
                   nearByLocation: { lat: {$location['lat']}, long: {$location['long']}, radius: 1 }
                ) {
                    data {
                        name
                        description
                    }
                }
            }"
        );

        // assert that there's no product is near by location
        $this->assertEmpty($response->json()['data']['products']['data']);

        $response = $this->graphQL(
            "query {
                products(
                   nearByLocation: { lat: {$location['lat']}, long: {$location['long']}, radius: 5.62 }
                ) {
                    data {
                        name
                        description
                    }
                }
            }"
        );
        // assert that there's one product is near by location
        $this->assertEquals($data['name'], $response->json()['data']['products']['data'][0]['name']);
    }
    /**
     * test get product.
     */
    public function testGetProduct(): void
    {
        $response = $this->createProduct();
        $this->assertArrayHasKey('name', $response['data']['createProduct']);

        $response = $this->graphQL('
            query {
                products {
                    data {
                        name
                        description
                    }
                }
            }');
        $this->assertArrayHasKey('name', $response->json()['data']['products']['data'][0]);
    }

    /**
     * test update product.
     */
    public function testUpdateProduct(): void
    {
        $response = $this->createProduct();

        $this->assertArrayHasKey('id', $response['data']['createProduct']);

        $id = $response->json()['data']['createProduct']['id'];

        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'weight' => 1,
        ];
        $this->graphQL('
        mutation($data: ProductInputUpdate! $id: ID!) {
            updateProduct(input: $data, id: $id)
            {
                name
                description
                weight
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
        $response = $this->createProduct();
        $this->assertArrayHasKey('id', $response['data']['createProduct']);
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
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $response = $this->createProduct();

        $this->assertArrayHasKey('id', $response['data']['createProduct']);

        $id = $response->json()['data']['createProduct']['id'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $id,
            warehouseData: $warehouseData
        );

        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);
    }

    /**
     * testDeleteVariantToProduct.
     */
    public function testDeleteVariantToProduct(): void
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $response = $this->createProduct();

        $this->assertArrayHasKey('id', $response['data']['createProduct']);

        $id = $response->json()['data']['createProduct']['id'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $id,
            warehouseData: $warehouseData
        );

        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);

        $variantResponseId = $variantResponse->json()['data']['createVariant']['id'];
        $this->graphQL('
        mutation($id: ID!) {
            deleteVariant(id: $id)
        }', ['id' => $variantResponseId])->assertJson([
            'data' => ['deleteVariant' => true],
        ]);
    }

    /**
     * testDeleteLastVariantToProduct.
     */
    public function testDeleteLastVariantToProduct(): void
    {
        // Create product with default variant
        $productData = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
        ];
        $productResponse = $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                id
                name
                description
                variants {
                    id
                }
            }
        }', ['data' => $productData]);

        $productResponse->assertJsonStructure([
            'data' => [
                'createProduct' => [
                    'id',
                    'name',
                    'description',
                    'variants' => [
                        ['id'],
                    ],
                ],
            ],
        ]);

        $productId = $productResponse->json()['data']['createProduct']['id'];
        $defaultVariantId = $productResponse->json()['data']['createProduct']['variants'][0]['id'];

        // Try delete default product variant
        $deleteResponse = $this->graphQL('
        mutation($id: ID!) {
            deleteVariant(id: $id)
        }', ['id' => $defaultVariantId]);

        $this->assertArrayHasKey('errors', $deleteResponse->json());
        $this->assertNull($deleteResponse->json()['data']['deleteVariant']);
    }

    /**
     * test update product.
     */
    public function testUpdateProductTranslate(): void
    {
        $response = $this->createProduct();

        $this->assertArrayHasKey('id', $response['data']['createProduct']);
        $language = Languages::first();
        $id = $response->json()['data']['createProduct']['id'];

        $dataUpdate = [
            'name' => fake()->name . ' en',
            'description' => fake()->text . ' en'
        ];
        $response = $this->graphQL('
            mutation($dataUpdate: ProductTranslationInput!, $id: ID!, $code: String!) {
                updateProductTranslations(input: $dataUpdate, id: $id, code: $code)
                {
                    id,
                    name,
                    description,
                    translation(languageCode: $code){
                        name
                        description
                        language{
                            code
                            language
                        }
                    }
                }
            }', [
            'dataUpdate' => $dataUpdate,
            'id' => $id,
            'code' => $language->code
        ]);

        $this->assertEquals(
            $dataUpdate['name'],
            $response['data']['updateProductTranslations']['translation']['name']
        );
    }
}
