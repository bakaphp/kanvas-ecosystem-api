<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class ProductsTypesTest extends TestCase
{
    /**
     * testCreate.
     *
     * @return void
     */
    public function testCreate(): void
    {
        $data = [
            'name' => fake()->name,
            'weight' => 1,
        ];
        $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    name
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProductType' => $data]
        ]);
    }

    /**
     * testSearch.
     *
     * @return void
     */
    public function testSearch(): void
    {
        $data = [
            'name' => fake()->name,
            'weight' => 1,
        ];
        $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    name
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProductType' => $data]
        ]);
        $response = $this->graphQL('
            query {
                productTypes {
                    data {
                        name
                        weight
                    }
                }
            }');
        $this->assertArrayHasKey('name', $response->json()['data']['productTypes']['data'][0]);
    }

    /**
     * testUpdate.
     *
     * @return void
     */
    public function testUpdate(): void
    {
        $data = [
            'name' => fake()->name,
            'weight' => 1,
        ];
        $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    name
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProductType' => $data]
        ]);
        $response = $this->graphQL('
            query {
                productTypes {
                    data {
                        id,
                        name
                        weight
                    }
                }
            }');
        $id = $response['data']['productTypes']['data'][0]['id'];
        $data['weight'] = 2;
        $this->graphQL('
            mutation($data: ProductTypeUpdateInput! $id: ID!) {
                updateProductType(input: $data id: $id)
                {
                    name
                    weight
                }
            }', ['data' => $data, 'id' => $id])->assertJson([
            'data' => ['updateProductType' => $data]
        ]);
    }

    /**
     * testDelete.
     *
     * @return void
     */
    public function testDelete(): void
    {
        $data = [
            'name' => fake()->name,
            'weight' => 1,
        ];
        $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    name
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProductType' => $data]
        ]);
        $response = $this->graphQL('
            query {
                productTypes {
                    data {
                        id,
                        name
                        weight
                    }
                }
            }');
        $id = $response['data']['productTypes']['data'][0]['id'];
        $this->graphQL('
            mutation($id: ID!) {
                deleteProductType(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteProductType' => true]
        ]);
    }
}
