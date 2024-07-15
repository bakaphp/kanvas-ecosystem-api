<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class VariantAttributeTest extends TestCase
{
    /**
     * testAddAttributeToVariant.
     *
     * @return void
     */
    public function testAddAttributeToVariant(): void
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
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];

        $data = [
            'name' => fake()->name,
            'sku' => fake()->time,
            'description' => fake()->text,
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
        $productId = $response->json()['data']['createProduct']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
            'products_id' => $productId,
            'warehouses' => [$warehouseData]
        ];
        $response = $this->graphQL('
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
        $variantId = $response->json()['data']['createVariant']['id'];

        $dataAtribute = [
            'name' => fake()->name,
        ];
        $response = $this->graphQL('
        mutation($data: AttributeInput!) {
            createAttribute(input: $data)
            {
                id
                name
                values {
                    value
                }
            }
        }', ['data' => $dataAtribute]);
        $attributeId = $response->json()['data']['createAttribute']['id'];
        $response = $this->graphQL('
            mutation($id: ID! $attributes_id: ID! $input: VariantsAttributesInput!) {
                addAttributeToVariant(id: $id, attributes_id: $attributes_id, input: $input)
                {
                    id
                    name
                }
            }
        ', [
            'id' => $variantId,
            'attributes_id' => $attributeId,
            'input' => [
                'value' => fake()->name,
                'name' => fake()->name,
            ]
        ]);
        $this->assertArrayHasKey('data', $response->json());
    }

    /**
     * testRemoveAttributeFromVariant.
     *
     * @return void
     */
    public function testRemoveAttributeFromVariant(): void
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
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];
        $data = [
            'name' => fake()->name,
            'sku' => fake()->time,
            'description' => fake()->text,
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
        $productId = $response->json()['data']['createProduct']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
            'products_id' => $productId,
            'warehouses' => [$warehouseData]
        ];
        $response = $this->graphQL('
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
        $variantId = $response->json()['data']['createVariant']['id'];

        $dataAtribute = [
            'name' => fake()->name
        ];
        $response = $this->graphQL('
        mutation($data: AttributeInput!) {
            createAttribute(input: $data)
            {
                id
                name
                values {
                    value
                }
            }
        }', ['data' => $dataAtribute]);
        $attributeId = $response->json()['data']['createAttribute']['id'];

        $dataAtribute = [
            'name' => fake()->name,
        ];
        $response = $this->graphQL('
        mutation($data: AttributeInput!) {
            createAttribute(input: $data)
            {
                id
                name
            }
        }', ['data' => $dataAtribute]);
        $attributeId = $response->json()['data']['createAttribute']['id'];
        $response = $this->graphQL('
            mutation($id: ID! $attributes_id: ID! $input: VariantsAttributesInput!) {
                addAttributeToVariant(id: $id, attributes_id: $attributes_id, input: $input)
                {
                    id
                    name
                }
            }
        ', [
            'id' => $variantId,
            'attributes_id' => $attributeId,
            'input' => [
                'value' => fake()->name,
                'name' => fake()->name,
            ]
        ]);
        $this->assertArrayHasKey('data', $response->json());
        $response = $this->graphQL('
        mutation($id: ID! $attributesId: ID!) {
            removeAttributeToVariant(id:$id attributes_id:$attributesId)
            {
                id
                name
            }
        }', [
            'id' => $variantId,
            'attributesId' => $attributeId,
        ]);
        $this->assertArrayHasKey('data', $response->json());
    }
}
